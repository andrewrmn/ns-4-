<?php

namespace modules;

use Craft;
use craft\commerce\adjusters\Discount as CommerceDiscount;
use craft\commerce\events\DiscountAdjustmentsEvent;
use craft\commerce\services\OrderAdjustments;
use craft\events\RegisterComponentTypesEvent;
use modules\neurorewards\adjusters\Discount3for2;
use yii\base\Event;
use yii\base\Module;

/**
 * NeuroRewards: buy 11 get 1 free via custom order adjuster + Commerce discount integration.
 */
class NeuroRewardsModule extends Module
{
    public function init(): void
    {
        Craft::setAlias('@neuroRewardsModule', __DIR__ . '/neurorewards');
        parent::init();

        Event::on(OrderAdjustments::class, OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS, function (RegisterComponentTypesEvent $e): void {
            $adjusters = [
                Discount3for2::class,
            ];

            $existing = [];
            foreach ($e->types as $type) {
                $parts = explode('\\', $type);
                $existing[] = end($parts);
            }

            foreach ($adjusters as $type) {
                $parts = explode('\\', $type);
                if (!in_array(end($parts), $existing, true)) {
                    $e->types = array_merge([$type], $e->types);
                }
            }
        });

        Event::on(CommerceDiscount::class, CommerceDiscount::EVENT_AFTER_DISCOUNT_ADJUSTMENTS_CREATED, function (DiscountAdjustmentsEvent $e): void {
            if (strpos(strtolower($e->discount->name), 'neurorewards') !== false) {
                $e->isValid = false;
            }
        });

        Craft::info('NeuroRewards module loaded', __METHOD__);
    }
}
