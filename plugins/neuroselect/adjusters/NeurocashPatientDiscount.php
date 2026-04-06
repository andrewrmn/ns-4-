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

use craft\base\Component;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderAdjustment;

class NeurocashPatientDiscount extends Component implements AdjusterInterface
{
    public function adjust(Order $order): array
    {
        $adjustments = [];

        $user = Craft::$app->getUser()->getIdentity();

        if( ! is_null($user) && $user->isInGroup('patients') && $user->patientNeurocashDiscount ){
            $relatedHcp = $user->relatedHcp->one()->fullName ? $user->relatedHcp->one()->fullName : $user->relatedHcp->one()->username;
            $itemTotal = $order->itemTotal;
            $discount = $itemTotal * ($user->patientNeurocashDiscount / 100);
            $adjustment = new OrderAdjustment;
            $adjustment->type = 'discount';
            $adjustment->name = 'NeuroCash Discount ' . $user->patientNeurocashDiscount . '%';
            $adjustment->description = $user->patientNeurocashDiscount . '% discount by HCP ' . $relatedHcp;
            $adjustment->sourceSnapshot = [ 'data' => 'value' ];
            $adjustment->amount = $discount;
            $adjustment->setOrder($order);
            $adjustment->setLineItem($item);
            $adjustments[] = $adjustment;
        }

        return $adjustments;
    }
}
