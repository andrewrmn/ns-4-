<?php

namespace modules;

use Craft;
use craft\console\Application as ConsoleApplication;
use enupal\stripe\events\OrderCompleteEvent;
use enupal\stripe\services\Orders;
use yii\base\Event;
use yii\base\Module;
use yii\db\Query;

class AutoshipScheduleModule extends Module
{
    public function init(): void
    {
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'modules\\autoshipschedule\\console\\controllers';
        }

        parent::init();

        Event::on(Orders::class, Orders::EVENT_AFTER_ORDER_COMPLETE, function (OrderCompleteEvent $e): void {
            $order = $e->order;

            // Only recurring / subscription Stripe orders populate autoship_schedule. One-time checkouts
            // still fire this event and getSubscription() can be null — that previously caused a 500.
            $subscription = $order->getSubscription();
            if ($subscription === null || empty($subscription->data)) {
                return;
            }

            $dataObj = $subscription->data;
            $periodStart = $dataObj->current_period_start ?? null;
            $periodEnd = $dataObj->current_period_end ?? null;
            if ($periodStart === null || $periodEnd === null) {
                Craft::warning(
                    'Autoship schedule: subscription missing current_period_start/end for Stripe order '
                    . ($order->number ?? ''),
                    __METHOD__
                );

                return;
            }

            $currentPeriodStart = date('Y-m-d', (int)$periodStart);
            $currentPeriodEnd = date('Y-m-d', (int)$periodEnd);
            $orderId = $order->number;
            $formData = $order->getFormFields();
            $craftOrderNumber = is_array($formData) ? ($formData['orderNumber'] ?? null) : null;

            if ($craftOrderNumber === null || $craftOrderNumber === '') {
                Craft::warning(
                    'Autoship schedule: form field orderNumber missing for Stripe order ' . ($orderId ?? ''),
                    __METHOD__
                );

                return;
            }

            $db = Craft::$app->db;
            $table = $db->tablePrefix . 'autoship_schedule';

            $row = (new Query())
                ->from($table)
                ->where(['orderId' => $orderId])
                ->one($db);

            $data = [
                'comOrderId' => $craftOrderNumber,
                'currentPeriodStart' => $currentPeriodStart,
                'currentPeriodEnd' => $currentPeriodEnd,
            ];

            if ($row) {
                $db->createCommand()->update($table, $data, ['id' => $row['id']])->execute();
            } else {
                // MySQL error 1364: this table's `id` is often defined without AUTO_INCREMENT. Supply next id.
                $maxId = (new Query())->from($table)->max('id', $db);
                $nextId = ($maxId !== null && $maxId !== false) ? ((int)$maxId + 1) : 1;
                $db->createCommand()->insert(
                    $table,
                    array_merge(['id' => $nextId, 'orderId' => $orderId], $data)
                )->execute();
            }
        });

        Craft::info('Autoship schedule module loaded', __METHOD__);
    }
}
