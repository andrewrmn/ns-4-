<?php

namespace modules\hcpworkspace\adjusters;

use Craft;
use craft\base\Component;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderAdjustment;

class HcpWorkspaceDiscountAdjuster extends Component implements AdjusterInterface
{
    public function adjust(Order $order): array
    {
        $adjustments = [];

        $user = Craft::$app->getUser()->getIdentity();
        if (!is_null($user) && $user->isInGroup('patients')) {
            $relatedHcp = $user->relatedHcp->count() ? $user->relatedHcp->one() : null;

            if (!is_null($relatedHcp)) {
                $enableProviderEarnings = $relatedHcp->disableProviderEarnings ? false : true;
                $storefrontPct = $relatedHcp->hcpStorefrontDiscount->value ?? null;
                if ($enableProviderEarnings && $storefrontPct) {
                    $itemTotal = $order->itemTotal;
                    $discount = $itemTotal * ($storefrontPct / 100);
                    $adjustment = new OrderAdjustment();
                    $adjustment->type = 'discount';
                    $adjustment->name = 'Provider Discount';
                    $adjustment->description = '';
                    $adjustment->sourceSnapshot = ['data' => 'value'];
                    $adjustment->amount = -$discount;
                    $adjustment->setOrder($order);
                    $adjustments[] = $adjustment;
                }
            }
        }

        return $adjustments;
    }
}
