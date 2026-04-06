<?php

namespace modules;

use Craft;
use craft\commerce\elements\Order as CommerceOrder;
use craft\commerce\Plugin as Commerce;
use enupal\stripe\services\Orders;
use enupal\stripe\elements\Order;
use enupal\stripe\records\OrderStatus;
use enupal\stripe\events\WebhookEvent;

use craft\mail\Message;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\helpers\UrlHelper;
use craft\mail\Mailer;
use craft\elements\Entry;

use yii\base\Event;

class StripeWebhookModule extends \yii\base\Module
{
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
        if (isset($webhookData['data']['object']['lines']['data'])) {
          foreach ($webhookData['data']['object']['lines']['data'] as $invoiceLine) {
            $orderNumber = $invoiceLine['metadata']['orderNumber'] ?? false;
            if ($orderNumber) {
              $order = Order::find()->where(['variants' => '{"orderNumber":"' . $orderNumber . '"}'])->one();
              if ($order) {
                $entryStatus = OrderStatus::find()->where(['isDefault' => 1])->one();
                if ($entryStatus) {
                  $order->orderStatusId = $entryStatus->id;
                  Craft::$app->elements->saveElement($order);
                }
              }
              $commerceOrder = CommerceOrder::find()->where(['number' => $orderNumber])->orderBy(['id' => 'DESC'])->one();
              if ($commerceOrder) {
                $clonedCommerceOrder = Craft::$app->getElements()->duplicateElement($commerceOrder);
                $commercePlugin = new Commerce('commerce');
                $autoshipStatus = $commercePlugin->getOrderStatuses()->getOrderStatusByHandle('autoShip');
                if ($autoshipStatus) {
                  $clonedCommerceOrder->orderStatusId = $autoshipStatus->id;
                }
                $clonedCommerceOrder->dateCreated = new \DateTime();
                $clonedCommerceOrder->dateOrdered = new \DateTime();
                Craft::$app->elements->saveElement($clonedCommerceOrder);

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
            }
          }
          
        }
        break;
      }
    });
  }
}
