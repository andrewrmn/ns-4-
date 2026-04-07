<?php

namespace modules\neurorewards\adjusters;

use craft\base\Component;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\events\DiscountAdjustmentsEvent;
use craft\commerce\models\Discount as DiscountModel;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;

/**
 * Buy 11 qualifying units, get the 12th unit free (order adjuster).
 *
 * Only Commerce discounts whose name is exactly `NeuroRewards` run the SKU logic; other enabled
 * discounts whose name contains "neurorewards" are still considered for coupon gating only.
 *
 * Qualifying units: each line-item unit in reverse line-item order, minus {@see self::EXCLUDE_FROM_COUNT_SKUS}.
 * Every 12th qualifying unit is free unless its SKU is in {@see self::EXCLUDE_FROM_FREE_SKUS} (promo
 * defers to the next qualifying unit).
 */
class Discount3for2 extends Component implements AdjusterInterface
{
    public const ADJUSTMENT_TYPE = 'discount';

    /** SKUs that do not count toward the 11 paid units. */
    private const EXCLUDE_FROM_COUNT_SKUS = ['20039', '20043', '20048', '20047', '20044S', '20045S', '20041S', '20054S', '20067K'];

    /** SKUs that cannot receive the free-12th discount (slot rolls forward). */
    private const EXCLUDE_FROM_FREE_SKUS = ['2056', '2046', '20050', '20067B'];

    private Order $_order;

    public function adjust(Order $order): array
    {
        $this->_order = $order;

        $discounts = Commerce::getInstance()->getDiscounts()->getAllDiscounts();

        $availableDiscounts = [];
        foreach ($discounts as $discount) {
            if (!$discount->enabled) {
                continue;
            }
            if (strpos(strtolower($discount->name), 'neurorewards') !== false) {
                if ($this->_discountAppliesForOrderCoupon($discount)) {
                    $availableDiscounts[] = $discount;
                }
                continue;
            }
        }

        $adjustments = [];

        foreach ($availableDiscounts as $discount) {
            $newAdjustments = $this->_getAdjustments($discount);
            if ($newAdjustments) {
                $adjustments = array_merge($adjustments, $newAdjustments);

                if ($discount->stopProcessing) {
                    break;
                }
            }
        }

        return $adjustments;
    }

    /**
     * Commerce 4+: coupon codes live on {@see \craft\commerce\models\Coupon}; `requireCouponCode` gates whether a code is needed.
     */
    private function _discountAppliesForOrderCoupon(DiscountModel $discount): bool
    {
        if (!$discount->requireCouponCode) {
            return true;
        }

        $orderCode = $this->_order->couponCode;
        if ($orderCode === null || $orderCode === '') {
            return false;
        }

        foreach ($discount->getCoupons() as $coupon) {
            if (strcasecmp($orderCode, (string) $coupon->code) === 0) {
                return true;
            }
        }

        return false;
    }

    private function _createOrderAdjustment(DiscountModel $discount, array $data): OrderAdjustment
    {
        $adjustment = new OrderAdjustment();
        $adjustment->type = self::ADJUSTMENT_TYPE;
        $adjustment->name = $discount->name;
        $adjustment->orderId = $this->_order->id;
        $adjustment->description = $discount->description;
        $adjustment->sourceSnapshot = array_merge($discount->attributes, $data);

        return $adjustment;
    }

    /**
     * @return OrderAdjustment[]|false
     */
    private function _getAdjustments(DiscountModel $discount)
    {
        if ($discount->name !== 'NeuroRewards') {
            return false;
        }

        $adjustments = [];

        // Commerce 4+: user group rules live on the discount customer condition (see Discounts::matchOrder()).
        if ($discount->hasCustomerCondition()) {
            $customer = $this->_order->getCustomer();
            if (!$customer || !$discount->getCustomerCondition()->matchElement($customer)) {
                return false;
            }
        }

        $now = new \DateTime();
        $from = $discount->dateFrom;
        $to = $discount->dateTo;
        if (($from && $from > $now) || ($to && $to < $now)) {
            return false;
        }

        $ti = 0;
        foreach (array_reverse($this->_order->getLineItems()) as $item) {
            $q = $item->qty;
            $sku = (string) $item->sku;

            for ($i = 0; $i < $q; $i++) {
                if (in_array($sku, self::EXCLUDE_FROM_COUNT_SKUS, true)) {
                    continue;
                }

                if (($ti + 1) % 12 === 0) {
                    if (in_array($sku, self::EXCLUDE_FROM_FREE_SKUS, true)) {
                        $ti--;
                        continue;
                    }

                    $adjustment = $this->_createOrderAdjustment($discount, ['qty' => 1]);
                    $adjustment->lineItemId = $item->id;

                    $adjustment->description = 'Buy 11 products, get 1 free (' . $item->description . ')';
                    $adjustment->sourceSnapshot = ['data' => $item->description];

                    $adjustment->amount = !empty($item->salePrice) ? 0 - $item->salePrice : 0 - $item->price;

                    $adjustments[] = $adjustment;

                    $ti++;
                    continue;
                }

                $ti++;
            }
        }

        if ($discount->baseDiscount !== null && (float) $discount->baseDiscount != 0.0) {
            $baseDiscountAdjustment = $this->_createOrderAdjustment($discount, []);
            $baseDiscountAdjustment->lineItemId = null;
            $baseDiscountAdjustment->amount = $discount->baseDiscount;
            $adjustments[] = $baseDiscountAdjustment;
        }

        if (!count($adjustments)) {
            return false;
        }

        $event = new DiscountAdjustmentsEvent([
            'order' => $this->_order,
            'discount' => $discount,
            'adjustments' => $adjustments,
        ]);

        return $event->adjustments;
    }
}
