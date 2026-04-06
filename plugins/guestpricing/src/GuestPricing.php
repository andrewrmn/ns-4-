<?php
/**
 * Guest Pricing plugin for Craft CMS 3.x
 *
 * Use suggested retail pricing for guests
 *
 * @link      andrewross.co
 * @copyright Copyright (c) 2020 Andrew Ross Co.
 */

namespace neuroscience\guestpricing;


use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;

use craft\commerce\events\LineItemEvent;
use craft\commerce\services\LineItems;

use craft\commerce\adjusters\Discount;
use craft\commerce\services\OrderAdjustments;
use craft\commerce\events\DiscountAdjustmentsEvent;

use yii\base\Event;


/**
 * Class GuestPricing
 *
 * @author    Andrew Ross Co.
 * @package   GuestPricing
 * @since     1.0.0
 *
 */
class GuestPricing extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var GuestPricing
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public $schemaVersion = '1.0.0';

    /**
     * @var bool
     */
    public $hasCpSettings = false;

    /**
     * @var bool
     */
    public $hasCpSection = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                }
            }
        );

        Craft::info(
            Craft::t(
                'guest-pricing',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );


        Event::on(LineItems::class, LineItems::EVENT_POPULATE_LINE_ITEM, function(LineItemEvent $event) {
            $options = $event->lineItem->getOptions();

            if (isset($options['guest'])) {
              $isGuest = $options['guest'];
            } else {
              $isGuest = 'yes';
            }

            if( $options && $isGuest == 'yes') {
                $lineItem = $event->lineItem;
                $purchasable = $lineItem->getPurchasable();
                $lineItem->price = $purchasable->suggestedRetailPrice;
                $lineItem->salePrice = $purchasable->suggestedRetailPrice;
            }
        });
    }
}
