<?php

namespace modules;

use Craft;
use craft\commerce\elements\Order as CommerceOrder;
use craft\commerce\Plugin as Commerce;
use enupal\stripe\services\Orders;
use enupal\stripe\elements\Order;
use enupal\stripe\records\OrderStatus;
use enupal\stripe\events\WebhookEvent;
use enupal\stripe\Stripe as EnupalStripe;
use Stripe\Invoice as StripeInvoice;
use Stripe\Subscription as StripeSubscription;

use craft\errors\ElementException;
use craft\mail\Message;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\mail\Mailer;
use craft\elements\Entry;

use yii\base\Event;

class StripeWebhookModule extends \yii\base\Module
{
  // #region agent log
  private static function debugAgentLog(string $message, array $data, string $hypothesisId): void
  {
    $payload = [
      'sessionId' => 'ee9ea5',
      'hypothesisId' => $hypothesisId,
      'location' => 'StripeWebhookModule.php',
      'message' => $message,
      'data' => $data,
      'timestamp' => (int) round(microtime(true) * 1000),
    ];
    $line = json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
    $paths = [dirname(__DIR__) . '/.cursor/debug-ee9ea5.log'];
    try {
      if (Craft::$app !== null) {
        $paths[] = Craft::$app->getPath()->getStoragePath() . '/logs/autoship-webhook-ee9ea5.ndjson';
      }
    } catch (\Throwable $ignore) {
    }
    foreach ($paths as $p) {
      $dir = dirname($p);
      if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
      }
      @file_put_contents($p, $line, FILE_APPEND | LOCK_EX);
    }
  }
  // #endregion

  /**
   * Commerce order number for autoship renewals may live on invoice line metadata (first charge)
   * or only on the subscription / invoice (recurring); the cron “about to renew” job uses
   * subscription metadata, so we mirror that here.
   */
  private static function resolveAutoshipCommerceOrderNumberFromInvoice(array $invoice): ?string
  {
    foreach ($invoice['lines']['data'] ?? [] as $line) {
      if (!empty($line['metadata']['orderNumber'])) {
        return (string) $line['metadata']['orderNumber'];
      }
    }
    if (!empty($invoice['metadata']['orderNumber'])) {
      return (string) $invoice['metadata']['orderNumber'];
    }
    $subscription = $invoice['subscription'] ?? null;
    if (is_array($subscription) && !empty($subscription['metadata']['orderNumber'])) {
      return (string) $subscription['metadata']['orderNumber'];
    }
    $parent = $invoice['parent'] ?? null;
    $subFromParent = is_array($parent) ? (string) ($parent['subscription_details']['subscription'] ?? '') : '';
    $subscriptionId = (is_string($subscription) && $subscription !== '')
      ? $subscription
      : $subFromParent;
    if ($subscriptionId !== '') {
      try {
        EnupalStripe::$app->settings->initializeStripe();
        $sub = StripeSubscription::retrieve($subscriptionId);
        if (!empty($sub->metadata['orderNumber'])) {
          return (string) $sub->metadata['orderNumber'];
        }
      } catch (\Throwable $e) {
        Craft::error('StripeWebhookModule: subscription retrieve failed for autoship orderNumber: ' . $e->getMessage(), __METHOD__);
      }
    }
    return null;
  }

  /**
   * Clone template Commerce order for an autoship renewal. Stripe sends multiple success signals
   * (`invoice.paid`, `invoice.payment_succeeded`, sometimes `invoice_payment.paid`); we accept any
   * with the same invoice payload (or load invoice from invoice_payment) and idempotently skip
   * duplicate deliveries for the same Stripe invoice id.
   */
  private static function processAutoshipRenewalFromStripeInvoice(array $invoice, string $stripeEventType): void
  {
    $stripeInvoiceId = (string) ($invoice['id'] ?? '');

    if (!isset($invoice['lines']['data'])) {
        // #region agent log
        self::debugAgentLog('invoice_paid_missing_lines_data', [
          'invoice_id' => $invoice['id'] ?? null,
          'stripe_event_type' => $stripeEventType,
        ], 'B');
        // #endregion

      return;
    }

    $templateOrderNumber = self::resolveAutoshipCommerceOrderNumberFromInvoice($invoice);
      // #region agent log
      $sub = $invoice['subscription'] ?? null;
      self::debugAgentLog('invoice_paid_resolution', [
        'stripe_event_type' => $stripeEventType,
        'resolved_nonempty' => (bool) $templateOrderNumber,
        'template_order_len' => $templateOrderNumber ? strlen((string) $templateOrderNumber) : 0,
        'lines_count' => count($invoice['lines']['data'] ?? []),
        'first_line_has_md_orderNumber' => !empty($invoice['lines']['data'][0]['metadata']['orderNumber']),
        'invoice_md_has_orderNumber' => !empty($invoice['metadata']['orderNumber']),
        'subscription_field_type' => is_string($sub) ? 'string' : (is_array($sub) ? 'array' : gettype($sub)),
        'subscription_id_len' => is_string($sub) ? strlen($sub) : 0,
        'parent_has_sub_details' => !empty($invoice['parent']['subscription_details']['subscription']),
      ], 'B');
    // #endregion

    if (!$templateOrderNumber) {
      // #region agent log
      self::debugAgentLog('order_number_unresolved', [
        'invoice_id' => $invoice['id'] ?? null,
        'stripe_event_type' => $stripeEventType,
      ], 'B');
      // #endregion
      Craft::warning('StripeWebhookModule: paid invoice webhook — could not resolve Commerce orderNumber (lines, invoice, or subscription metadata); renewal order not created.', __METHOD__);

      return;
    }

    $order = Order::find()->where(['variants' => '{"orderNumber":"' . $templateOrderNumber . '"}'])->one();
    if ($order) {
      $entryStatus = OrderStatus::find()->where(['isDefault' => 1])->one();
      if ($entryStatus) {
        $order->orderStatusId = $entryStatus->id;
        Craft::$app->elements->saveElement($order);
      }
    }
    $commerceOrder = CommerceOrder::find()->where(['number' => $templateOrderNumber])->orderBy(['id' => 'DESC'])->one();
    // #region agent log
    self::debugAgentLog('commerce_template_lookup', [
      'commerce_order_found' => (bool) $commerceOrder,
      'commerce_order_id' => $commerceOrder ? (int) $commerceOrder->id : null,
      'stripe_event_type' => $stripeEventType,
    ], 'C');
    // #endregion

    if (!$commerceOrder) {
      return;
    }

    $lockKey = 'autoshipRenewalStripeInv:' . $stripeInvoiceId;
    if ($stripeInvoiceId !== '') {
      if (!Craft::$app->cache->add($lockKey, 1, 3600)) {
        // #region agent log
        self::debugAgentLog('autoship_renewal_skip_duplicate_invoice', [
          'stripe_invoice_id' => $stripeInvoiceId,
          'stripe_event_type' => $stripeEventType,
        ], 'B');
        // #endregion

        return;
      }
    }

    try {
      // Webhooks run without a CP user; Commerce order duplicate/save expects an identity with
      // commerce-editOrders (or equivalent). Completed clones must get a new unique `number`
      // or the DB unique constraint / validation fails (logged as duplicate_element_failed).
      $originalIdentity = Craft::$app->getUser()->getIdentity();
      $admin = User::find()->admin()->status(null)->orderBy(['id' => SORT_ASC])->one();
      if ($admin) {
        Craft::$app->getUser()->setIdentity($admin);
      }
      try {
        $newOrderNumber = str_replace('-', '', StringHelper::UUID());
        $clonedCommerceOrder = Craft::$app->getElements()->duplicateElement($commerceOrder, [
          'number' => $newOrderNumber,
          'isCompleted' => false,
          'dateOrdered' => null,
          'reference' => $commerceOrder->reference,
        ]);
      } catch (\Throwable $dupEx) {
        // #region agent log
        $logData = [
          'exception_class' => get_class($dupEx),
          'message' => $dupEx->getMessage(),
          'stripe_event_type' => $stripeEventType,
        ];
        if ($dupEx instanceof ElementException) {
          $logData['element_errors'] = $dupEx->element->getErrors();
        }
        self::debugAgentLog('duplicate_element_failed', $logData, 'D');
        // #endregion
        throw $dupEx;
      } finally {
        Craft::$app->getUser()->setIdentity($originalIdentity);
      }
      $commercePlugin = new Commerce('commerce');
      $autoshipStatus = $commercePlugin->getOrderStatuses()->getOrderStatusByHandle('autoShip');
      if ($autoshipStatus) {
        $clonedCommerceOrder->orderStatusId = $autoshipStatus->id;
      }
      $clonedCommerceOrder->dateCreated = new \DateTime();
      $clonedCommerceOrder->dateOrdered = new \DateTime();
      $clonedCommerceOrder->isCompleted = true;
      Craft::$app->elements->saveElement($clonedCommerceOrder);
      // #region agent log
      self::debugAgentLog('renewal_order_saved', [
        'cloned_commerce_order_id' => (int) $clonedCommerceOrder->id,
        'cloned_number_len' => strlen((string) $clonedCommerceOrder->number),
        'autoship_status_assigned' => (bool) $autoshipStatus,
        'stripe_event_type' => $stripeEventType,
      ], 'D');
      // #endregion

      Craft::$app->db->createCommand()->update(
        '{{%commerce_orders}}',
        [
          'totalPaid' => $commerceOrder->totalPaid,
          'totalPrice' => $commerceOrder->totalPrice,
          'itemTotal' => $commerceOrder->itemTotal,
          'total' => $commerceOrder->total,
          'totalDiscount' => $commerceOrder->totalDiscount,
          'totalTax' => $commerceOrder->totalTax,
          'totalTaxIncluded' => $commerceOrder->totalTaxIncluded,
          'shippingMethodName' => $commerceOrder->shippingMethodName,
          'totalShippingCost' => $commerceOrder->totalShippingCost,
          'itemSubTotal' => $commerceOrder->itemSubTotal,
        ],
        ['id' => $clonedCommerceOrder->id]
      )->execute();

      // duplicateElement may copy line items; we always replace from the template order.
      Craft::$app->db->createCommand()->delete('{{%commerce_lineitems}}', ['orderId' => $clonedCommerceOrder->id])->execute();
      Craft::$app->db->createCommand()->delete('{{%commerce_orderadjustments}}', ['orderId' => $clonedCommerceOrder->id])->execute();

      $date = date('Y-m-d H:i:s');
      $sql = "SELECT * FROM {{%commerce_orderadjustments}} WHERE [[orderId]] = :oid";
      $orderlineQ = Craft::$app->db->createCommand($sql, [':oid' => $commerceOrder->id])->queryAll();
      foreach ($orderlineQ as $adjRow) {
        unset($adjRow['id']);
        $adjRow['orderId'] = (int) $clonedCommerceOrder->id;
        if (array_key_exists('uid', $adjRow)) {
          $adjRow['uid'] = StringHelper::UUID();
        }
        Craft::$app->db->createCommand()->insert('{{%commerce_orderadjustments}}', $adjRow)->execute();
      }

      $sql = "SELECT * FROM {{%commerce_lineitems}} WHERE [[orderId]] = :oid";
      $orderlineQ = Craft::$app->db->createCommand($sql, [':oid' => $commerceOrder->id])->queryAll();
      foreach ($orderlineQ as $liRow) {
        unset($liRow['id']);
        $liRow['orderId'] = (int) $clonedCommerceOrder->id;
        $liRow['uid'] = StringHelper::UUID();
        $liRow['dateCreated'] = $date;
        $liRow['dateUpdated'] = $date;
        $liRow['lineItemStatusId'] = null;
        Craft::$app->db->createCommand()->insert('{{%commerce_lineitems}}', $liRow)->execute();
      }

      $orderNumber = $clonedCommerceOrder->number;
      Craft::info("StripeWebhookModule: Starting email trigger for order number: {$orderNumber}", __METHOD__);

      $order = Order::find()->where(['variants' => '{"orderNumber":"' . $orderNumber . '"}'])->one();
      $commerceOrder = CommerceOrder::find()->where(['number' => $orderNumber])->orderBy(['id' => 'DESC'])->one();

      Craft::info('StripeWebhookModule: Order check - order: ' . ($order ? "found (ID: {$order->id})" : 'not found'), __METHOD__);
      Craft::info('StripeWebhookModule: CommerceOrder check - commerceOrder: ' . ($commerceOrder ? "found (ID: {$commerceOrder->id})" : 'not found'), __METHOD__);
      Craft::info('StripeWebhookModule: Reference check - reference: ' . ($commerceOrder && $commerceOrder->reference ? $commerceOrder->reference : 'not found'), __METHOD__);

      if ($order && $commerceOrder && $commerceOrder->reference) {
        Craft::info("StripeWebhookModule: All checks passed, sending patient email to: {$order->email}", __METHOD__);

        try {
          $body = Craft::$app->getView()->renderTemplate(
            'shop/emails/_orderReceivedPatient',
            [
              'order' => $commerceOrder,
            ]
          );
          $subject = 'Your Autoship Order Has Been Placed!';
          $mailer = Craft::$app->getMailer();
          $message = new Message();
          $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
          $message->setTo($order->email);
          $message->setSubject($subject);
          $message->setHtmlBody($body);
          $result = $mailer->send($message);
          Craft::info('StripeWebhookModule: Patient email sent successfully: ' . ($result ? 'yes' : 'no'), __METHOD__);
        } catch (\Exception $e) {
          Craft::error('StripeWebhookModule: Error sending patient email: ' . $e->getMessage(), __METHOD__);
        }

        try {
          $patient = Craft::$app->getUsers()->getUserByUsernameOrEmail($order->email);
          $hcp = $patient && $patient->relatedHcp ? ($patient->relatedHcp->count() ? $patient->relatedHcp->one() : null) : null;
          if (!is_null($hcp) && $hcp->hcpEmailNotifications) {
            Craft::info("StripeWebhookModule: Sending HCP email to: {$hcp->email}", __METHOD__);
            $body = Craft::$app->getView()->renderTemplate(
              'shop/emails/_hcpPatientPlacedOrder',
              [
                'order' => $commerceOrder,
                'hcp' => $hcp,
                'patient' => $patient,
                'patientDisplayName' => HcpPatientOrderEmailHelper::patientDisplayName($commerceOrder, $patient),
              ]
            );
            $subject = 'New order on your NeuroScience storefront';
            $mailer = Craft::$app->getMailer();
            $message = new Message();
            $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
            $message->setTo($hcp->email);
            $message->setSubject($subject);
            $message->setHtmlBody($body);
            $result = $mailer->send($message);
            Craft::info('StripeWebhookModule: HCP email sent successfully: ' . ($result ? 'yes' : 'no'), __METHOD__);
          } else {
            Craft::info('StripeWebhookModule: HCP email skipped - hcp: ' . ($hcp ? 'found but notifications disabled' : 'not found'), __METHOD__);
          }
        } catch (\Exception $e) {
          Craft::error('StripeWebhookModule: Error sending HCP email: ' . $e->getMessage(), __METHOD__);
        }

        try {
          Craft::info('StripeWebhookModule: Sending admin email to: customerservice@neurorelief.com', __METHOD__);
          $body = Craft::$app->getView()->renderTemplate(
            'shop/emails/_orderReceivedAdmin',
            [
              'order' => $commerceOrder,
            ]
          );
          $subject = 'An autoship order has renewed';
          $mailer = Craft::$app->getMailer();
          $message = new Message();
          $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
          $message->setTo('customerservice@neurorelief.com');
          $message->setSubject($subject);
          $message->setHtmlBody($body);
          $result = $mailer->send($message);
          Craft::info('StripeWebhookModule: Admin email sent successfully: ' . ($result ? 'yes' : 'no'), __METHOD__);
        } catch (\Exception $e) {
          Craft::error('StripeWebhookModule: Error sending admin email: ' . $e->getMessage(), __METHOD__);
        }
      } else {
        Craft::warning('StripeWebhookModule: Email sending skipped - order: ' . ($order ? 'yes' : 'no') . ', commerceOrder: ' . ($commerceOrder ? 'yes' : 'no') . ', reference: ' . ($commerceOrder && $commerceOrder->reference ? 'yes' : 'no'), __METHOD__);
      }
    } catch (\Throwable $e) {
      if ($stripeInvoiceId !== '') {
        Craft::$app->cache->delete($lockKey);
      }
      throw $e;
    }
  }

  /**
  * Initializes the module.
  */
  public function init()
  {
    // Set a @modules alias pointed to the modules/ directory
    Craft::setAlias('@modules', __DIR__);

    // Set the controllerNamespace based on whether this is a console or web request
    if (Craft::$app->getRequest()->getIsConsoleRequest()) {
      $this->controllerNamespace = 'modules\\console\\controllers';
    } else {
      $this->controllerNamespace = 'modules\\controllers';
    }
    parent::init();

    Event::on(Orders::class, Orders::EVENT_AFTER_PROCESS_WEBHOOK, function (WebhookEvent $e) {
      $webhookData = $e->stripeData;
      // #region agent log
      $obj = $webhookData['data']['object'] ?? [];
      $objType = is_array($obj) ? ($obj['object'] ?? null) : null;
      $invoiceLogId = null;
      $billingReason = null;
      if ($objType === 'invoice') {
        $invoiceLogId = $obj['id'] ?? null;
        $billingReason = $obj['billing_reason'] ?? null;
      } elseif ($objType === 'invoice_payment') {
        $invField = $obj['invoice'] ?? null;
        $invoiceLogId = is_string($invField) ? $invField : (is_array($invField) ? ($invField['id'] ?? null) : null);
      }
      self::debugAgentLog('enupal_after_process_webhook', [
        'event_type' => $webhookData['type'] ?? null,
        'stripe_object_type' => $objType,
        'invoice_id' => $invoiceLogId,
        'billing_reason' => $billingReason,
      ], 'A');
      // #endregion
      switch ($webhookData['type']) {
        case 'invoice.paid':
        case 'invoice.payment_succeeded':
          $invoice = $webhookData['data']['object'] ?? [];
          if (is_array($invoice)) {
            self::processAutoshipRenewalFromStripeInvoice($invoice, (string) ($webhookData['type'] ?? ''));
          }
          break;

        case 'invoice_payment.paid':
          $payment = $webhookData['data']['object'] ?? [];
          $invoiceId = '';
          if (is_array($payment)) {
            $invField = $payment['invoice'] ?? null;
            $invoiceId = is_string($invField) ? $invField : (is_array($invField) ? (string) ($invField['id'] ?? '') : '');
          }
          if ($invoiceId === '') {
            // #region agent log
            self::debugAgentLog('invoice_payment_paid_no_invoice_id', [
              'stripe_object_type' => is_array($payment) ? ($payment['object'] ?? null) : null,
            ], 'B');
            // #endregion
            break;
          }
          try {
            EnupalStripe::$app->settings->initializeStripe();
            $stripeInv = StripeInvoice::retrieve($invoiceId);
            $invoiceArr = method_exists($stripeInv, 'toArray') ? $stripeInv->toArray() : json_decode(json_encode($stripeInv), true);
            if (!is_array($invoiceArr)) {
              throw new \RuntimeException('Could not normalize Stripe Invoice to array');
            }
            self::processAutoshipRenewalFromStripeInvoice($invoiceArr, 'invoice_payment.paid');
          } catch (\Throwable $retrieveEx) {
            Craft::error('StripeWebhookModule: invoice retrieve failed for invoice_payment.paid: ' . $retrieveEx->getMessage(), __METHOD__);
            // #region agent log
            self::debugAgentLog('invoice_retrieve_failed', [
              'message' => $retrieveEx->getMessage(),
              'invoice_id' => $invoiceId,
            ], 'B');
            // #endregion
          }
          break;
      }
    });
  }
}
