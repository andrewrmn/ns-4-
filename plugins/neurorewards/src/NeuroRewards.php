<?php
/**
 * NeuroRewards plugin for Craft CMS 3.x
 *
 * Buy 11, get 1 free
 *
 * @link      andrewross.co
 * @copyright Copyright (c) 2020 Andrew Ross Co.
 */

namespace neuroscience\neurorewards;
use neuroscience\neurorewards\adjusters\Discount3for2;
//use neuroscience\neurorewards\adjusters\Bundles;

use Craft;
use craft\base\Plugin;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\commerce\adjusters\Discount as CommerceDiscount;
use craft\commerce\services\OrderAdjustments;
use craft\commerce\events\DiscountAdjustmentsEvent;

use yii\base\Event;

/**
 * Class NeuroRewards
 *
 * @author    Andrew Ross Co.
 * @package   NeuroRewards
 * @since     1.0.0
 *
 */
class NeuroRewards extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * @var NeuroRewards
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var bool
     */
    public bool $hasCpSettings = false;

    /**
     * @var bool
     */
    public bool $hasCpSection = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
		self::$plugin = $this;

		Event::on(OrderAdjustments::class, OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS, function(RegisterComponentTypesEvent $e) {

			$adjusters = [
				Discount3for2::class,
				//Bundles::class,
				//Trade::class,
			];

			$existing = [];
			foreach ($e->types as $type)
			{
				$key = explode('\\',$type);
				$existing[] = end($key);
			}

			foreach ($adjusters as $type)
			{
				$key = explode('\\',$type);
				if (!in_array(end($key), $existing)) {
					$e->types = array_merge([$type], $e->types);
				}
			}
			//Craft::dd($e->types);
		});

		Event::on(CommerceDiscount::class, CommerceDiscount::EVENT_AFTER_DISCOUNT_ADJUSTMENTS_CREATED, function(DiscountAdjustmentsEvent $e) {

			if (strpos(strtolower($e->discount->name), 'neurorewards') !== false) {
				$e->isValid = false;
			}

		});

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
                'neuro-rewards',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    // Protected Methods
    // =========================================================================

}
