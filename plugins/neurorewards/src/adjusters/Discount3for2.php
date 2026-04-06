<?php
/**
 * Promotions plugin for Craft CMS 3.x
 *
 * Adds promotions
 *
 * @link      https://kurious.agency
 * @copyright Copyright (c) 2018 Kurious Agency
 */

namespace neuroscience\neurorewards\adjusters;


use Craft;
use craft\base\Component;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\events\DiscountAdjustmentsEvent;
use craft\commerce\helpers\Currency;
use craft\commerce\models\Discount as DiscountModel;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Discount as DiscountRecord;

/**
 * Discount Adjuster
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Discount3for2 extends Component implements AdjusterInterface
{
    // Constants
    // =========================================================================

    /**
     * The discount adjustment type.
     */
    const ADJUSTMENT_TYPE = 'discount';


    // Properties
    // =========================================================================

    /**
     * @var Order
     */
    private $_order;

    /**
     * @var
     */
    private $_discount;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function adjust(Order $order): array
    {
        $this->_order = $order;

        $discounts = Commerce::getInstance()->getDiscounts()->getAllDiscounts();

        // Find discounts with no coupon or the coupon that matches the order.
        $availableDiscounts = [];
        foreach ($discounts as $discount) {

            if (!$discount->enabled) {
                continue;
			}
			//Craft::dd($discount->name);
			if (strpos(strtolower($discount->name), 'neurorewards') !== false) {
				//Craft::dump(strpos($discount->name, '3for2'));

				if ($discount->code == null) {
					$availableDiscounts[] = $discount;
				} else {
					if ($this->_order->couponCode && (strcasecmp($this->_order->couponCode, $discount->code) == 0)) {
						$availableDiscounts[] = $discount;
					}
				}
				continue;
			}
        }

        $adjustments = [];

		//Craft::dd($availableDiscounts);

        foreach ($availableDiscounts as $discount) {
            $newAdjustments = $this->_getAdjustments($discount);
            if ($newAdjustments) {
                $adjustments = array_merge($adjustments, $newAdjustments);

                if ($discount->stopProcessing) {
                    break;
                }
            }
		}
		//Craft::dd($adjustments);

        return $adjustments;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param DiscountModel $discount
     * @return OrderAdjustment
     */
    private function _createOrderAdjustment(DiscountModel $discount, $data): OrderAdjustment
    {
        //preparing model
        $adjustment = new OrderAdjustment();
        $adjustment->type = self::ADJUSTMENT_TYPE;
        $adjustment->name = $discount->name;
        $adjustment->orderId = $this->_order->id;
        $adjustment->description = $discount->description;
        $adjustment->sourceSnapshot = array_merge($discount->attributes, $data);

        return $adjustment;
    }

    /**
     * @param DiscountModel $discount
     * @return OrderAdjustment[]|false
     */
    private function _getAdjustments(DiscountModel $discount)
    {

        if( $discount->name == 'NeuroRewards' ) {
            $adjustments = [];

            $this->_discount = $discount;

            // Make sure customer is in the correct group

            $inGroup = false;
            if (!$this->_discount->userGroupsCondition) {
                $customer = $this->_order->getCustomer();
                $user = $customer ? $customer->getUser() : null;
                $userGroups = Commerce::getInstance()->getCustomers()->getUserGroupIdsForUser($user);

                if ($user && array_intersect($userGroups, $this->_discount->getUserGroupIds())) {
                    $inGroup = true;
                }
            } else {
                $inGroup = true;
            }

            if($inGroup){


                $now = new \DateTime();
                $from = $this->_discount->dateFrom;
                $to = $this->_discount->dateTo;
                if (($from && $from > $now) || ($to && $to < $now)) {
                    return false;
                }


                $ti = 0;
                // reverse loop
                foreach ( array_reverse( $this->_order->getLineItems() ) as $item) {
                    //Craft::dump($item);
                    $q = $item->qty;
                    $sku = strval($item->sku);

                    // Loop through all multiples
                    for ( $i = 0; $i < $q; $i++ ) {

                        // Check for every 12th item
                        if ( ($ti + 1) % 12 == 0 ) {

                            // Can't be the free product
                            if ( $sku == '2056' or $sku == '2046' or $sku == '20050' or $sku == '20067B' ) { $ti--; continue; }

                            $adjustment = $this->_createOrderAdjustment($this->_discount, ['qty'=>1]);
                            $adjustment->lineItemId = $item->id;

                            $adjustment->description = 'Buy 11 products, get 1 free (' . $item->description . ')';
                            // Add product name to discount data
                            $adjustment->sourceSnapshot = [ 'data' => $item->description];

                            // Add item to the "Free Products" array
                            if( !empty($item->salePrice) ) {
                                $adjustment->amount = 0-$item->salePrice;
                            } else {
                                $adjustment->amount = 0-$item->price;
                            }

                            $adjustments[] = $adjustment;

                            $ti++;
                            continue;
                        }


                        // Doesn't count towards the 12 so skip them
                        if ( $sku == '20039' or $sku == '20043' or $sku == '20048' or $sku == '20047' or $sku == '20044S' or $sku == '20045S' or $sku == '20041S' or $sku == '20054S' or $sku == '20067K' ) {
                            $ti--;
                        }

                        $ti++;
                    }
        		}


                if ($discount->baseDiscount !== null && $discount->baseDiscount != 0) {
                    $baseDiscountAdjustment = $this->_createOrderAdjustment($discount);
                    $baseDiscountAdjustment->lineItemId = null;
                    $baseDiscountAdjustment->amount = $discount->baseDiscount;
                    $adjustments[] = $baseDiscountAdjustment;
                }

                // only display adjustment if an amount was calculated
                if (!count($adjustments)) {
                    return false;
                }

                // Raise the 'beforeMatchLineItem' event
                $event = new DiscountAdjustmentsEvent([
                    'order' => $this->_order,
                    'discount' => $discount,
                    'adjustments' => $adjustments
                ]);

                return $event->adjustments;
            }
        }
    }
}
