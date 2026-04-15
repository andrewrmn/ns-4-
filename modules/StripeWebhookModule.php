<?php

namespace modules;

use Craft;
use craft\commerce\elements\Order as CommerceOrder;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as CommerceTransactionRecord;
use enupal\stripe\services\Orders;
use enupal\stripe\elements\Order;
use enupal\stripe\records\OrderStatus;
use enupal\stripe\events\WebhookEvent;
use enupal\stripe\Stripe as EnupalStripe;
use Stripe\Invoice as StripeInvoice;
use Stripe\Subscription as StripeSubscription;

use craft\mail\Message;
use craft\elements\User;
use craft\helpers\StringHelper;
use craft\mail\Mailer;

use yii\base\Event;

class StripeWebhookModule extends \yii\base\Module
{
  /**
   * Webhook payloads may send `subscription` as a string id or an expanded object; casting an
   * array to string yields "Array" and causes Stripe `resource_missing` on GET /v1/subscriptions/:id.
   */
  private static function normalizeStripeSubscriptionId(mixed $value): string
  {
    if (is_string($value)) {
      $s = trim($value);

      return str_starts_with($s, 'sub_') ? $s : '';
    }
    if (is_array($value)) {
      $id = $value['id'] ?? null;
      if (is_string($id)) {
        $id = trim($id);
        if (str_starts_with($id, 'sub_')) {
          return $id;
        }
      }
    }

    return '';
  }

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
    $subFromParent = '';
    if (is_array($parent)) {
      $rawParentSub = $parent['subscription_details']['subscription'] ?? null;
      $subFromParent = self::normalizeStripeSubscriptionId($rawParentSub);
    }
    $subscriptionId = self::normalizeStripeSubscriptionId($subscription) ?: $subFromParent;
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
   * Commerce 4 derives paid state from successful purchase/capture transactions, not the
   * `commerce_orders.totalPaid` column alone. Record a purchase for the renewal so CP shows Paid.
   */
  private static function recordAutoshipRenewalPurchaseTransaction(
    int $clonedOrderId,
    CommerceOrder $templateOrder,
    string $stripeInvoiceId
  ): void {
    // Use ->id(), not where(['id' => …]): OrderQuery joins address tables and an unqualified `id`
    // produces SQLSTATE[1052] Column 'id' in WHERE is ambiguous (seen after renewal_sql_lineitems_adjustments_done).
    $cloned = CommerceOrder::find()->id($clonedOrderId)->status(null)->one();
    if (!$cloned) {
      Craft::warning('StripeWebhookModule: renewal purchase transaction skipped — cloned order not found (id ' . $clonedOrderId . ').', __METHOD__);

      return;
    }

    if (!$cloned->getGateway()) {
      $cloned->gatewayId = $templateOrder->gatewayId;
      $cloned->paymentSourceId = $templateOrder->paymentSourceId;
      Craft::$app->getElements()->saveElement($cloned, false);
      $cloned = CommerceOrder::find()->id($clonedOrderId)->status(null)->one();
      if (!$cloned) {
        return;
      }
    }

    $transactions = Commerce::getInstance()->getTransactions();
    $tx = $transactions->createTransaction($cloned, null, CommerceTransactionRecord::TYPE_PURCHASE);
    $tx->status = CommerceTransactionRecord::STATUS_SUCCESS;
    $tx->reference = $stripeInvoiceId !== '' ? 'stripe-invoice:' . $stripeInvoiceId : 'stripe-autoship-renewal';
    $tx->message = 'Stripe subscription renewal';

    if (!$transactions->saveTransaction($tx)) {
      Craft::error(
        'StripeWebhookModule: failed to save renewal purchase transaction for Commerce order ' . $clonedOrderId,
        __METHOD__
      );
    }
  }

  /**
   * After duplicateElement(), the clone has an id but must not load billing/shipping via
   * AddressElement::find()->owner($order) while order->id was still null (that produced ownerId [null]
   * and yii\base\InvalidConfigException in AddressQuery). We therefore cleared address FKs on duplicate
   * and re-attach copies of the template’s address elements owned by the clone.
   */
  private static function attachAutoshipRenewalAddressesFromTemplate(CommerceOrder $template, CommerceOrder $clone): void
  {
    $shipping = $template->getShippingAddress();
    if ($shipping) {
      $newShipping = Craft::$app->getElements()->duplicateElement($shipping, [
        'owner' => $clone,
        'title' => Craft::t('commerce', 'Shipping Address'),
      ]);
      $clone->setShippingAddress($newShipping);
    } else {
      $clone->setShippingAddress(null);
    }

    $billing = $template->getBillingAddress();
    if ($billing) {
      if ($shipping && $billing->id === $shipping->id) {
        $clone->setBillingAddress($clone->getShippingAddress());
      } else {
        $newBilling = Craft::$app->getElements()->duplicateElement($billing, [
          'owner' => $clone,
          'title' => Craft::t('commerce', 'Billing Address'),
        ]);
        $clone->setBillingAddress($newBilling);
      }
    } else {
      $clone->setBillingAddress(null);
    }

    $estShip = $template->getEstimatedShippingAddress();
    if ($estShip) {
      $newEstShip = Craft::$app->getElements()->duplicateElement($estShip, [
        'owner' => $clone,
      ]);
      $clone->setEstimatedShippingAddress($newEstShip);
    }
    if ($template->estimatedBillingSameAsShipping && $estShip) {
      $clone->setEstimatedBillingAddress($clone->getEstimatedShippingAddress());
    } else {
      $estBill = $template->getEstimatedBillingAddress();
      if ($estBill) {
        $newEstBill = Craft::$app->getElements()->duplicateElement($estBill, [
          'owner' => $clone,
        ]);
        $clone->setEstimatedBillingAddress($newEstBill);
      }
    }
  }

  /**
   * Clone template Commerce order for an autoship renewal. Stripe sends multiple success signals
   * (`invoice.paid`, `invoice.payment_succeeded`, sometimes `invoice_payment.paid`); we accept any
   * with the same invoice payload (or load invoice from invoice_payment) and idempotently skip
   * duplicate deliveries for the same Stripe invoice id.
   *
   * Only `billing_reason === subscription_cycle` is handled: the first subscription invoice
   * (`subscription_create`) must not clone Commerce orders — checkout already completed the real order.
   */
  private static function processAutoshipRenewalFromStripeInvoice(array $invoice, string $stripeEventType): void
  {
    $stripeInvoiceId = (string) ($invoice['id'] ?? '');
    $billingReason = (string) ($invoice['billing_reason'] ?? '');

    if ($billingReason !== 'subscription_cycle') {
      return;
    }

    if (!isset($invoice['lines']['data'])) {
      return;
    }

    $templateOrderNumber = self::resolveAutoshipCommerceOrderNumberFromInvoice($invoice);

    if (!$templateOrderNumber) {
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

    if (!$commerceOrder) {
      return;
    }

    $templateCommerceOrder = $commerceOrder;

    $lockKey = 'autoshipRenewalStripeInv:' . $stripeInvoiceId;
    if ($stripeInvoiceId !== '') {
      // One renewal Commerce order per Stripe invoice id (any combination of invoice.* webhooks).
      // Resending the same event returns here: HTTP 200 from Enupal, but no new order until the
      // cache entry expires (TTL below) or the key is cleared.
      if (!Craft::$app->cache->add($lockKey, 1, 3600)) {
        Craft::info(
          'StripeWebhookModule: renewal skipped — this Stripe invoice was already processed (idempotent lock): ' . $stripeInvoiceId,
          __METHOD__
        );

        return;
      }
    }

    try {
      // Webhooks run without a CP user; Commerce order duplicate/save expects an identity with
      // commerce-editOrders (or equivalent). Completed clones must get a new unique `number`.
      $originalIdentity = Craft::$app->getUser()->getIdentity();
      $admin = User::find()->admin()->status(null)->orderBy(['id' => SORT_ASC])->one();
      if ($admin) {
        Craft::$app->getUser()->setIdentity($admin);
      }
      try {
        $newOrderNumber = str_replace('-', '', StringHelper::UUID());
        // Clear paymentSourceId for the duplicate save: Order::afterSave reads getPaymentSource()
        // and throws if paymentSourceId is set but the customer user cannot be loaded (deleted user,
        // broken customerId, etc.). Gateway is restored from the template below.
        $clonedCommerceOrder = Craft::$app->getElements()->duplicateElement($commerceOrder, [
          'number' => $newOrderNumber,
          'isCompleted' => false,
          'dateOrdered' => null,
          'reference' => $commerceOrder->reference,
          'paymentSourceId' => null,
          'recalculationMode' => CommerceOrder::RECALCULATION_MODE_NONE,
          'billingAddressId' => null,
          'shippingAddressId' => null,
          'estimatedBillingAddressId' => null,
          'estimatedShippingAddressId' => null,
        ]);
        self::attachAutoshipRenewalAddressesFromTemplate($templateCommerceOrder, $clonedCommerceOrder);
      } catch (\Throwable $dupEx) {
        Craft::error(
          'StripeWebhookModule: duplicateElement failed (' . get_class($dupEx) . '): ' . $dupEx->getMessage(),
          __METHOD__
        );
        throw $dupEx;
      } finally {
        Craft::$app->getUser()->setIdentity($originalIdentity);
      }
      $clonedCommerceOrder->gatewayId = $templateCommerceOrder->gatewayId;
      if ($templateCommerceOrder->paymentSourceId !== null && $templateCommerceOrder->getCustomer() !== null) {
        $clonedCommerceOrder->paymentSourceId = $templateCommerceOrder->paymentSourceId;
      }
      $commercePlugin = Commerce::getInstance();
      $autoshipStatus = $commercePlugin->getOrderStatuses()->getOrderStatusByHandle('autoShip');
      if ($autoshipStatus) {
        $clonedCommerceOrder->orderStatusId = $autoshipStatus->id;
      }
      $clonedCommerceOrder->dateCreated = new \DateTime();
      $clonedCommerceOrder->dateOrdered = new \DateTime();
      $clonedCommerceOrder->isCompleted = true;
      Craft::$app->elements->saveElement($clonedCommerceOrder);

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
      $templateOrderId = (int) $templateCommerceOrder->id;
      $cloneOrderId = (int) $clonedCommerceOrder->id;

      // Line items must be inserted before adjustments: adjustment rows reference lineItemId, which
      // must point at rows on THIS order or be null. Copying template adjustments first kept old
      // template line item ids and caused SQL integrity failures (and Stripe 5xx after the order
      // element was already saved).
      $lineItemIdMap = [];
      $sql = "SELECT * FROM {{%commerce_lineitems}} WHERE [[orderId]] = :oid ORDER BY [[id]] ASC";
      $templateLines = Craft::$app->db->createCommand($sql, [':oid' => $templateOrderId])->queryAll();
      foreach ($templateLines as $liRow) {
        $oldLineItemId = isset($liRow['id']) ? (int) $liRow['id'] : 0;
        unset($liRow['id']);
        $liRow['orderId'] = $cloneOrderId;
        $liRow['uid'] = StringHelper::UUID();
        $liRow['dateCreated'] = $date;
        $liRow['dateUpdated'] = $date;
        $liRow['lineItemStatusId'] = null;
        Craft::$app->db->createCommand()->insert('{{%commerce_lineitems}}', $liRow)->execute();
        if ($oldLineItemId > 0) {
          $lineItemIdMap[$oldLineItemId] = (int) Craft::$app->db->getLastInsertID();
        }
      }

      $sql = "SELECT * FROM {{%commerce_orderadjustments}} WHERE [[orderId]] = :oid ORDER BY [[id]] ASC";
      $templateAdjustments = Craft::$app->db->createCommand($sql, [':oid' => $templateOrderId])->queryAll();
      foreach ($templateAdjustments as $adjRow) {
        unset($adjRow['id']);
        $adjRow['orderId'] = $cloneOrderId;
        if (array_key_exists('uid', $adjRow)) {
          $adjRow['uid'] = StringHelper::UUID();
        }
        if (!empty($adjRow['lineItemId'])) {
          $oldAdjLi = (int) $adjRow['lineItemId'];
          $adjRow['lineItemId'] = $lineItemIdMap[$oldAdjLi] ?? null;
        }
        Craft::$app->db->createCommand()->insert('{{%commerce_orderadjustments}}', $adjRow)->execute();
      }

      self::recordAutoshipRenewalPurchaseTransaction(
        (int) $clonedCommerceOrder->id,
        $templateCommerceOrder,
        $stripeInvoiceId
      );

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
      Craft::error(
        'StripeWebhookModule: autoship renewal failed (' . get_class($e) . '): ' . $e->getMessage(),
        __METHOD__
      );
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
          }
          break;
      }
    });
  }
}
