<?php

namespace modules;

use craft\commerce\events\LineItemEvent;
use craft\commerce\services\LineItems;
use Craft;
use yii\base\Event;
use yii\base\Module;

/**
 * Guest pricing: for cart line items with a guest flag, use suggested retail price (former guest-pricing plugin).
 */
class GuestPricingModule extends Module
{
    public function init(): void
    {
        parent::init();

        Event::on(
            LineItems::class,
            LineItems::EVENT_POPULATE_LINE_ITEM,
            static function (LineItemEvent $event): void {
                $lineItem = $event->lineItem;
                $options = $lineItem->getOptions();

                if (isset($options['guest'])) {
                    $isGuest = $options['guest'];
                } else {
                    $isGuest = 'yes';
                }

                if ($options && $isGuest == 'yes') {
                    $purchasable = $lineItem->getPurchasable();
                    $lineItem->price = $purchasable->suggestedRetailPrice;
                    $lineItem->salePrice = $purchasable->suggestedRetailPrice;
                }
            }
        );

        Craft::info('Guest pricing module loaded', __METHOD__);
    }
}
