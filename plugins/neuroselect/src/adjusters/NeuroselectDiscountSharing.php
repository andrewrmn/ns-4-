<?php
/**
 * neuroselect plugin for Craft CMS 3.x
 *
 * Pull Data from the NeuroScience app and display in User Profiles
 *
 * @link      andrewross.co
 * @copyright Copyright (c) 2020 Andrew Ross
 */

namespace neuroscience\neuroselect\adjusters;
use Craft;
use craft\base\Component;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderAdjustment;

class NeuroselectDiscountSharing extends Component implements AdjusterInterface
{
    public function adjust(Order $order): array
    {
        $adjustments = [];

        $user = Craft::$app->getUser()->getIdentity();
        if( ! is_null($user) && $user->isInGroup('patients') ){
            $relatedHcp = $user->relatedHcp->count() ? $user->relatedHcp->one() : null;

            if( !is_null($relatedHcp) ){
                $enableProviderEarnings = $relatedHcp->disableProviderEarnings ? false : true;
                if( $enableProviderEarnings && $relatedHcp->hcpStorefrontDiscount->value ){
                    $itemTotal = $order->itemTotal;
                    $discount = $itemTotal * ($relatedHcp->hcpStorefrontDiscount->value / 100);
                    $adjustment = new OrderAdjustment;
                    $adjustment->type = 'discount';
                    $adjustment->name = 'Provider Discount';
                    $adjustment->description = '';
                    $adjustment->sourceSnapshot = [ 'data' => 'value' ];
                    $adjustment->amount = -$discount;
                    $adjustment->setOrder($order);
                    $adjustments[] = $adjustment;
                }
            }
        }
        return $adjustments;
    }
}