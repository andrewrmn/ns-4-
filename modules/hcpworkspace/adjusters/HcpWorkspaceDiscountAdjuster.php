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
                    // #region agent log
                    $logPayload = json_encode([
                        'sessionId' => '5f5fa1',
                        'runId' => 'pre-fix',
                        'hypothesisId' => 'H1',
                        'location' => 'HcpWorkspaceDiscountAdjuster.php:adjust',
                        'message' => 'provider adjustment after create',
                        'data' => [
                            'lineItemId' => $adjustment->lineItemId,
                            'getLineItemNull' => $adjustment->getLineItem() === null,
                        ],
                        'timestamp' => (int) round(microtime(true) * 1000),
                    ]) . "\n";
                    @file_put_contents(dirname(__DIR__, 3) . '/.cursor/debug-5f5fa1.log', $logPayload, FILE_APPEND);
                    // #endregion
                    $adjustments[] = $adjustment;
                }
            }
        }

        return $adjustments;
    }
}
