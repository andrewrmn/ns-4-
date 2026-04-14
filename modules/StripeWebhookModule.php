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
use Stripe\Subscription as StripeSubscription;

use craft\mail\Message;
use craft\elements\User;
use craft\events\ModelEvent;
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
      $inv = $webhookData['data']['object'] ?? [];
      self::debugAgentLog('enupal_after_process_webhook', [
        'event_type' => $webhookData['type'] ?? null,
        'invoice_id' => is_array($inv) ? ($inv['id'] ?? null) : null,
        'billing_reason' => is_array($inv) ? ($inv['billing_reason'] ?? null) : null,
      ], 'A');
      // #endregion
      switch ($webhookData['type']) {
        case 'invoice.paid':
        $invoice = $webhookData['data']['object'] ?? [];
        if (!isset($invoice['lines']['data'])) {
          // #region agent log
          self::debugAgentLog('invoice_paid_missing_lines_data', [
            'invoice_id' => $invoice['id'] ?? null,
          ], 'B');
          // #endregion
          break;
        }
        $templateOrderNumber = self::resolveAutoshipCommerceOrderNumberFromInvoice($invoice);
          // #region agent log
          $sub = $invoice['subscription'] ?? null;
          self::debugAgentLog('invoice_paid_resolution', [
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
        if ($templateOrderNumber) {
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
              ], 'C');
              // #endregion
              if ($commerceOrder) {
                try {
                  $clonedCommerceOrder = Craft::$app->getElements()->duplicateElement($commerceOrder);
                } catch (\Throwable $dupEx) {
                  // #region agent log
                  self::debugAgentLog('duplicate_element_failed', [
                    'message' => $dupEx->getMessage(),
                  ], 'D');
                  // #endregion
                  throw $dupEx;
                }
                $commercePlugin = new Commerce('commerce');
                $autoshipStatus = $commercePlugin->getOrderStatuses()->getOrderStatusByHandle('autoShip');
                if ($autoshipStatus) {
                  $clonedCommerceOrder->orderStatusId = $autoshipStatus->id;
                }
                $clonedCommerceOrder->dateCreated = new \DateTime();
                $clonedCommerceOrder->dateOrdered = new \DateTime();
                Craft::$app->elements->saveElement($clonedCommerceOrder);
                // #region agent log
                self::debugAgentLog('renewal_order_saved', [
                  'cloned_commerce_order_id' => (int) $clonedCommerceOrder->id,
                  'cloned_number_len' => strlen((string) $clonedCommerceOrder->number),
                  'autoship_status_assigned' => (bool) $autoshipStatus,
                ], 'D');
                // #endregion

                // single value
                //$sql = "UPDATE craft_commerce_orders SET totalPaid='99.99' WHERE id = $clonedCommerceOrder->id";
                //$newRef = $clonedCommerceOrder->reference . '1';
                // multiple values
                $sql = "UPDATE `craft_commerce_orders` SET
                  `totalPaid` = '{$commerceOrder->totalPaid}',
                  `totalPrice` = '{$commerceOrder->totalPrice}',
                  `itemTotal` = '{$commerceOrder->itemTotal}',
                  `total` = '{$commerceOrder->total}',
                  `totalDiscount` = '{$commerceOrder->totalDiscount}',
                  `totalTax` = '{$commerceOrder->totalTax}',
                  `totalTaxIncluded` = '{$commerceOrder->totalTaxIncluded}',
                  `shippingMethodName` = '{$commerceOrder->shippingMethodName}',
                  `totalShippingCost` = '{$commerceOrder->totalShippingCost}',
                  `itemSubTotal` = '{$commerceOrder->itemSubTotal}'
                WHERE id = $clonedCommerceOrder->id";
                $success = Craft::$app->db->createCommand($sql)->execute();

                $sql = "SELECT * FROM craft_commerce_orderadjustments WHERE orderId = $commerceOrder->id";
                $orderlineQ = Craft::$app->db->createCommand($sql)->queryAll();
                if($orderlineQ){
                  $date=date('Y-m-d H:i:s');
                  foreach($orderlineQ as $lineitemsdup){
                    $sql = "INSERT INTO craft_commerce_orderadjustments (
                      `orderId`,
                      `type`,
                      `name`,
                      `description`,
                      `amount`,
                      `included`,
                      `sourceSnapshot`
                    ) VALUES (
                      '{$clonedCommerceOrder->id}',
                      '{$lineitemsdup["type"]}',
                      '{$lineitemsdup["name"]}',
                      '{$lineitemsdup["description"]}',
                      '{$lineitemsdup["amount"]}',
                      '{$lineitemsdup["included"]}',
                      '{$lineitemsdup["sourceSnapshot"]}'
                    )";
                    $success = Craft::$app->db->createCommand($sql)->execute();
                  }
                }

                $sql = "SELECT * FROM craft_commerce_lineitems WHERE orderId = $commerceOrder->id";
                $orderlineQ = Craft::$app->db->createCommand($sql)->queryAll();
                if($orderlineQ){
                  $date=date('Y-m-d H:i:s');
                  foreach($orderlineQ as $lineitemsdup){
                    $sql = "INSERT INTO craft_commerce_lineitems (
                      `orderId`,
                      `purchasableId`,
                      `options`,
                      `optionsSignature`,
                      `price`,
                      `saleAmount`,
                      `salePrice`,
                      `weight`,
                      `height`,
                      `length`,
                      `width`,
                      `total`,
                      `qty`,
                      `note`,
                      `snapshot`,
                      `taxCategoryId`,
                      `shippingCategoryId`,
                      `dateCreated`,
                      `dateUpdated`,
                      `uid`,
                      `subtotal`,
                      `lineItemStatusId`,
                      `privateNote`,
                      `sku`,
                      `description`
                    ) VALUES (
                      '{$clonedCommerceOrder->id}',
                      '{$lineitemsdup["purchasableId"]}',
                      '{$lineitemsdup["options"]}',
                      '{$lineitemsdup["optionsSignature"]}',
                      '{$lineitemsdup["price"]}',
                      '{$lineitemsdup["saleAmount"]}',
                      '{$lineitemsdup["salePrice"]}',
                      '{$lineitemsdup["weight"]}',
                      '{$lineitemsdup["height"]}',
                      '{$lineitemsdup["length"]}',
                      '{$lineitemsdup["width"]}',
                      '{$lineitemsdup["total"]}',
                      '{$lineitemsdup["qty"]}',
                      '{$lineitemsdup["note"]}',
                      '{$lineitemsdup["snapshot"]}',
                      '{$lineitemsdup["taxCategoryId"]}',
                      '{$lineitemsdup["shippingCategoryId"]}',
                      '{$date}',
                      '{$date}',
                      '{$lineitemsdup["uid"]}',
                      '{$lineitemsdup["subtotal"]}',
                      null,
                      '{$lineitemsdup["privateNote"]}',
                      '{$lineitemsdup["sku"]}',
                      '{$lineitemsdup["description"]}'
                    )";
                    $success = Craft::$app->db->createCommand($sql)->execute();
                  }
                }

                
  							// trigger emails
  							$orderNumber = $clonedCommerceOrder->number;
                Craft::info("StripeWebhookModule: Starting email trigger for order number: {$orderNumber}", __METHOD__);
                
                $order = Order::find()->where(['variants' => '{"orderNumber":"' . $orderNumber . '"}'])->one();
                $commerceOrder = CommerceOrder::find()->where(['number' => $orderNumber])->orderBy(['id' => 'DESC'])->one();
                
                Craft::info("StripeWebhookModule: Order check - order: " . ($order ? "found (ID: {$order->id})" : "not found"), __METHOD__);
                Craft::info("StripeWebhookModule: CommerceOrder check - commerceOrder: " . ($commerceOrder ? "found (ID: {$commerceOrder->id})" : "not found"), __METHOD__);
                Craft::info("StripeWebhookModule: Reference check - reference: " . ($commerceOrder && $commerceOrder->reference ? $commerceOrder->reference : "not found"), __METHOD__);
                
                if ($order && $commerceOrder && $commerceOrder->reference) {
                  Craft::info("StripeWebhookModule: All checks passed, sending patient email to: {$order->email}", __METHOD__);
                  
                  // patient email
                  try {
                    $body = Craft::$app->getView()->renderTemplate(
                        'shop/emails/_orderReceivedPatient',
                        [
                            //'commerceOrder' => $commerceOrder,
                            'order' => $commerceOrder,
                            //'subscription' => $subscription,
                        ]
                    );
                    $subject = "Your Autoship Order Has Been Placed!";
                    $mailer = Craft::$app->getMailer();
                    $message = new Message();
                    $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
                    $message->setTo($order->email);
                    //$message->setTo('andrewross.mn@gmail.com');
                    $message->setSubject($subject);
                    $message->setHtmlBody($body);
                    $result = $mailer->send($message);
                    Craft::info("StripeWebhookModule: Patient email sent successfully: " . ($result ? "yes" : "no"), __METHOD__);
                  } catch (\Exception $e) {
                    Craft::error("StripeWebhookModule: Error sending patient email: " . $e->getMessage(), __METHOD__);
                  }

                  // HCP email
                  try {
                    $patient = Craft::$app->getUsers()->getUserByUsernameOrEmail($order->email);
                    $hcp = $patient && $patient->relatedHcp ? ($patient->relatedHcp->count() ? $patient->relatedHcp->one() : null) : null;
                    if( !is_null($hcp) && $hcp->hcpEmailNotifications ){
                      Craft::info("StripeWebhookModule: Sending HCP email to: {$hcp->email}", __METHOD__);
                      $body = Craft::$app->getView()->renderTemplate(
                          'shop/emails/_hcpPatientPlacedOrder',
                          [
                          	'order' => $commerceOrder,
                          	'hcp' => $hcp,
                          ]
                      );
                      $subject = "New order on your NeuroScience storefront";
                      $mailer = Craft::$app->getMailer();
                      $message = new Message();
                      $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
                      $message->setTo($hcp->email);
                      $message->setSubject($subject);
                      $message->setHtmlBody($body);
                      $result = $mailer->send($message);
                      Craft::info("StripeWebhookModule: HCP email sent successfully: " . ($result ? "yes" : "no"), __METHOD__);
                    } else {
                      Craft::info("StripeWebhookModule: HCP email skipped - hcp: " . ($hcp ? "found but notifications disabled" : "not found"), __METHOD__);
                    }
                  } catch (\Exception $e) {
                    Craft::error("StripeWebhookModule: Error sending HCP email: " . $e->getMessage(), __METHOD__);
                  }

                  // admin email
                  try {
                    Craft::info("StripeWebhookModule: Sending admin email to: customerservice@neurorelief.com", __METHOD__);
                    $body = Craft::$app->getView()->renderTemplate(
                      'shop/emails/_orderReceivedAdmin',
                      [
                          //'commerceOrder' => $commerceOrder,
                          'order' => $commerceOrder,
                          //'subscription' => $subscription,
                      ]
                    );
                    $subject = "An autoship order has renewed";
                    $mailer = Craft::$app->getMailer();
                    $message = new Message();
                    $message->setFrom(['info@neuroscienceinc.com' => 'NeuroScience']);
                    $message->setTo('customerservice@neurorelief.com');
                    $message->setSubject($subject);
                    $message->setHtmlBody($body);
                    $result = $mailer->send($message);
                    Craft::info("StripeWebhookModule: Admin email sent successfully: " . ($result ? "yes" : "no"), __METHOD__);
                  } catch (\Exception $e) {
                    Craft::error("StripeWebhookModule: Error sending admin email: " . $e->getMessage(), __METHOD__);
                  }
                } else {
                  Craft::warning("StripeWebhookModule: Email sending skipped - order: " . ($order ? "yes" : "no") . ", commerceOrder: " . ($commerceOrder ? "yes" : "no") . ", reference: " . ($commerceOrder && $commerceOrder->reference ? $commerceOrder->reference : "no"), __METHOD__);
                }
              } // if commerce order
        } else {
          // #region agent log
          self::debugAgentLog('order_number_unresolved', [
            'invoice_id' => $invoice['id'] ?? null,
          ], 'B');
          // #endregion
          Craft::warning('StripeWebhookModule: invoice.paid — could not resolve Commerce orderNumber (lines, invoice, or subscription metadata); renewal order not created.', __METHOD__);
        }
        break;
      }
    });
  }
}
