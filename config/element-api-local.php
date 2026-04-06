<?php
namespace Craft;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\models\LineItem;
use craft\commerce\base\Purchasable;
use craft\elements\User;
use craft\elements\Entry;
use craft\helpers\UrlHelper;

use craft\elements\Asset;
use verbb\supertable\SuperTable;

function requireLogin()
{

  $currentUser = false;

  if( $currentUser ) {
    return [
      'loggedIn' => true
    ];
  } else {
    return [
      'loggedIn' => false
    ];
  }
}

$myArray = [''];

$productsCriteria = Product::find()->all();

$products = $productsCriteria;
foreach($products as $product) {
  if(!$product->disableInNueroselect){
    $myArray[] = $product->id;
  }
}
$myProds = implode(", ",$myArray);

return [

  'endpoints' => [

    'user/info.json' => [
      'elementType' => 'craft\elements\User',
      //'first' => true,
      'transformer' => function($entry) {
        requireLogin();
      },
    ],


    'api/products' => [
      'elementType' => Product::class,
      'transformer' => function(Product $product) {
        return [
          'ProductId' => $product->id,
          'ProductImage' => $product->productFields->one()->productImages->one()->url,
          'ProductTitle' => $product->title,
          'ProductDescription' => $product->productFields->one()->shortDescription
        ];
      },
      'pretty' => true,
    ],


    // orders
    'sage/orders' => [
      'elementType' => Order::class,
      'criteria' => [
        'isCompleted' => '1',
        'order' => 'dateOrdered desc'
      ],
      'transformer' => function (Order $order) {

	      $neuroCash = '';
        if( $order->hcpEmail ) {
          $neuroCash = money_format('%i', ($order->itemTotal * .30));
        }

        $ttlQty = $order->totalQty;

        //requireLogin();
        //    if($order->orderStatus->name === 'Processing'){
        return [
          'id' => $order->id,
          'orderStatus' => $order->orderStatus->name,
          'shortNumber' => $order->shortNumber,
          'number' => $order->number,
          'dateOrdered' => $order->dateOrdered,
          'isPaid' => $order->isPaid,
          'paymentMethod' => $order->gateway->name,
          'totalQty' => $order->totalQty,
          'totalTaxIncluded' => $order->totalTax,
          'totalDiscount' => $order->totalDiscount,
          'couponCode' => $order->couponCode,
          'totalPrice' => money_format('%i', $order->totalPrice),
          'adjustments' => array_map(function( OrderAdjustment $adjustments  ){
            //$price = $lineItem->subtotal / $lineItem->qty;
            return [
              'name' => $adjustments->name,
              'description' => $adjustments->description,
              'amount' => $adjustments->amount,
              //'behaviors' => $adjustments->behaviors
            ];
          }, $order->adjustments),
          'lineItems' => array_map(function( LineItem $lineItem ) use ($ttlQty){
            $price = $lineItem->subtotal / $lineItem->qty;
            if( $ttlQty > 11 && $lineItem->discount ) {
              $neuroRewardsCount = -($lineItem->discount / $lineItem->price);
            } else {
              $neuroRewardsCount = 0;
            }
            return [
              'id' => $lineItem->id,
              // //'price' => $lineItem->price, // change to total/ qty
              'price' => "$price", // change to total/ qty
              'qty' => $lineItem->qty,
              'purchasableId' => $lineItem->purchasableId,
              'description' => $lineItem->getDescription(),
              'sku' => $lineItem->getSku(),
              'tax' => $lineItem->tax,
              'discount' => $lineItem->discount,
              'NeuroRewardsCount' => $neuroRewardsCount,
              'subtotal' => money_format('%i', $lineItem->subtotal),
              'total' => money_format('%i', $lineItem->total)
            ];
          }, $order->lineItems),
          'customerEmail' => $order->email,
          'billingAddress' => Array(
            'firstName' => $order->billingAddress->firstName,
            'lastName' => $order->billingAddress->lastName,
            'address1' => $order->billingAddress->address1,
            'address2' => $order->billingAddress->address2,
            'city' => $order->billingAddress->city,
            'zip' => $order->billingAddress->zipCode,
            'phone' => $order->billingAddress->phone,
            'businessName' => $order->billingAddress->businessName,
            'state' => $order->billingAddress->stateText,
            'country' => $order->billingAddress->countryText
          ),
          'shippingAddress' => Array(
            'firstName' => $order->shippingAddress->firstName,
            'lastName' => $order->shippingAddress->lastName,
            'address1' => $order->shippingAddress->address1,
            'address2' => $order->shippingAddress->address2,
            'city' => $order->shippingAddress->city,
            'zip' => $order->shippingAddress->zipCode,
            'phone' => $order->shippingAddress->phone,
            'businessName' => $order->shippingAddress->businessName,
            'state' => $order->shippingAddress->stateText,
            'country' => $order->shippingAddress->countryText
          ),
          'shippingMethodHandle' => $order->shippingMethodHandle,
          'totalShippingCost' => $order->totalShippingCost,
          'hcpEmail' => $order->hcpEmail,
          'neuroCash' => $neuroCash,
          'UDF_FRT_OVERRIDE' => ($order->hcpEmail != null) ? 'Y' : 'N',
          'FreightAmt' => ($order->itemSubtotal < 70) ? '5.99' : '0.00',
        ];

      },
    ],


    // orders
    'sage/orders/processing' => [
      'elementType' => Order::class,
      'criteria' => [
        'isCompleted' => '1',
        'order' => 'dateOrdered desc',
        'orderStatusId' => [1, 5]
      ],
      'transformer' => function (Order $order) {

	      $neuroCash = '';
        if( $order->hcpEmail ) {
          $neuroCash = money_format('%i', ($order->itemTotal * .30));
        }

        $ttlQty = $order->totalQty;

        //requireLogin();
        //    if($order->orderStatus->name === 'Processing'){
        return [
          'id' => $order->id,
          'orderStatus' => $order->orderStatus->name,
          'shortNumber' => $order->shortNumber,
          'number' => $order->number,
          'dateOrdered' => $order->dateOrdered,
          'isPaid' => $order->isPaid,
          'paymentMethod' => $order->gateway->name,
          'totalQty' => $order->totalQty,
          'totalTaxIncluded' => $order->totalTax,
          'totalDiscount' => $order->totalDiscount,
          'couponCode' => $order->couponCode,
          'totalPrice' => money_format('%i', $order->totalPrice),
          'adjustments' => array_map(function( OrderAdjustment $adjustments  ){
            //$price = $lineItem->subtotal / $lineItem->qty;
            return [
              'name' => $adjustments->name,
              'description' => $adjustments->description,
              'amount' => $adjustments->amount,
              //'behaviors' => $adjustments->behaviors
            ];
          }, $order->adjustments),

          'lineItems' => array_map(function( LineItem $lineItem ) use ($ttlQty){
            $price = $lineItem->subtotal / $lineItem->qty;
            if( $ttlQty > 11 && $lineItem->discount ) {
              $neuroRewardsCount = -($lineItem->discount / $lineItem->price);
            } else {
              $neuroRewardsCount = 0;
            }
            return [
              'id' => $lineItem->id,
              // //'price' => $lineItem->price, // change to total/ qty
              'price' => "$price", // change to total/ qty
              'qty' => $lineItem->qty,
              'purchasableId' => $lineItem->purchasableId,
              'description' => $lineItem->getDescription(),
              'sku' => $lineItem->getSku(),
              'tax' => $lineItem->tax,
              'discount' => $lineItem->discount,
              'NeuroRewardsCount' => $neuroRewardsCount,
              'subtotal' => money_format('%i', $lineItem->subtotal),
              'total' => money_format('%i', $lineItem->total)
            ];
          }, $order->lineItems),
          'customerEmail' => $order->email,
          'billingAddress' => Array(
            'firstName' => $order->billingAddress->firstName,
            'lastName' => $order->billingAddress->lastName,
            'address1' => $order->billingAddress->address1,
            'address2' => $order->billingAddress->address2,
            'city' => $order->billingAddress->city,
            'zip' => $order->billingAddress->zipCode,
            'phone' => $order->billingAddress->phone,
            'businessName' => $order->billingAddress->businessName,
            'state' => $order->billingAddress->stateText,
            'country' => $order->billingAddress->countryText
          ),
          'shippingAddress' => Array(
            'firstName' => $order->shippingAddress->firstName,
            'lastName' => $order->shippingAddress->lastName,
            'address1' => $order->shippingAddress->address1,
            'address2' => $order->shippingAddress->address2,
            'city' => $order->shippingAddress->city,
            'zip' => $order->shippingAddress->zipCode,
            'phone' => $order->shippingAddress->phone,
            'businessName' => $order->shippingAddress->businessName,
            'state' => $order->shippingAddress->stateText,
            'country' => $order->shippingAddress->countryText
          ),
          'shippingMethodHandle' => $order->shippingMethodHandle,
          'totalShippingCost' => $order->totalShippingCost,
          'hcpEmail' => $order->hcpEmail,
          'neuroCash' => $neuroCash,
          'UDF_FRT_OVERRIDE' => ($order->hcpEmail != null) ? 'Y' : 'N',
          'FreightAmt' => ($order->itemSubtotal < 70) ? '5.99' : '0.00',
          //'jsonUrl' => UrlHelper::getUrl("sage/orders/{$order->id}"),
        ];

      },
    ],



    // Order Single
    'sage/orders/<orderId:\d+>' => function($orderId) {
      return [
        'elementType' => Order::class,
        'criteria' => ['id' => $orderId],
        'transformer' => function (Order $order) {
          requireLogin();
          $neuroRewards = array();

          $neuroCash = '';
	        if( $order->hcpEmail ) {
	          $neuroCash = money_format('%i', ($order->itemTotal * .30));
	        }

	        $ttlQty = $order->totalQty;

          return [
            'id' => $order->id,
            'orderStatus' => $order->orderStatus->name,
            'shortNumber' => $order->shortNumber,
            'number' => $order->number,
            'dateOrdered' => $order->dateOrdered,
            'isPaid' => $order->isPaid,
            'paymentMethod' => $order->gateway->name,
            'totalQty' => $order->totalQty,
            'totalTaxIncluded' => $order->totalTax,
            'totalDiscount' => $order->totalDiscount,
            'couponCode' => $order->couponCode,
            'totalPrice' => money_format('%i', $order->totalPrice),

            'adjustments' => array_map(function( OrderAdjustment $adjustments  ){
              //$price = $lineItem->subtotal / $lineItem->qty;
              return [
                'name' => $adjustments->name,
                'description' => $adjustments->description,
                'amount' => $adjustments->amount,
                //'behaviors' => $adjustments->behaviors
              ];
            }, $order->adjustments),

            'lineItems' => array_map(function( LineItem $lineItem ) use ($ttlQty){
              $price = $lineItem->subtotal / $lineItem->qty;
              if( $ttlQty > 11 && $lineItem->discount ) {
                $neuroRewardsCount = -($lineItem->discount / $lineItem->price);
              } else {
                $neuroRewardsCount = 0;
              }

              return [
                'id' => $lineItem->id,
                //'price' => $lineItem->price, // change to total/ qty
                'price' => "$price", // change to total/ qty
                'qty' => $lineItem->qty,

                'purchasableId' => $lineItem->purchasableId,
                'description' => $lineItem->getDescription(),
                'sku' => $lineItem->getSku(),
                'tax' => $lineItem->tax,
                'discount' => $lineItem->discount,
                'NeuroRewardsCount' => $neuroRewardsCount,
                'subtotal' => money_format('%i', $lineItem->subtotal),
                'total' => money_format('%i', $lineItem->total)
              ];
            }, $order->lineItems),

            'customerEmail' => $order->email,
            'billingAddress' => Array(
              'firstName' => $order->billingAddress->firstName,
              'lastName' => $order->billingAddress->lastName,
              'address1' => $order->billingAddress->address1,
              'address2' => $order->billingAddress->address2,
              'city' => $order->billingAddress->city,
              'zip' => $order->billingAddress->zipCode,
              'phone' => $order->billingAddress->phone,
              'businessName' => $order->billingAddress->businessName,
              'state' => $order->billingAddress->stateText,
              'country' => $order->billingAddress->countryText
            ),
            'shippingAddress' => Array(
              'firstName' => $order->shippingAddress->firstName,
              'lastName' => $order->shippingAddress->lastName,
              'address1' => $order->shippingAddress->address1,
              'address2' => $order->shippingAddress->address2,
              'city' => $order->shippingAddress->city,
              'zip' => $order->shippingAddress->zipCode,
              'phone' => $order->shippingAddress->phone,
              'businessName' => $order->shippingAddress->businessName,
              'state' => $order->shippingAddress->stateText,
              'country' => $order->shippingAddress->countryText
            ),
            'shippingMethodHandle' => $order->shippingMethodHandle,
            'totalShippingCost' => $order->totalShippingCost,
            'hcpEmail' => $order->hcpEmail,
            'neuroCash' => $neuroCash,
            'UDF_FRT_OVERRIDE' => ($order->hcpEmail != null) ? 'Y' : 'N',
            'FreightAmt' => ($order->itemSubtotal < 70) ? '5.99' : '0.00',
          ];
        },
      ];
    }
  ]


];
