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
use craft\helpers\Html;

use craft\helpers\StringHelper;

use craft\elements\Asset;
use verbb\supertable\SuperTable;

use enupal\stripe\elements\Order as StripeOrder;

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
      'paginate' => true,
      'elementsPerPage' => 1,
      'cache' => 0,
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
    },

    ## Getting HCP's 
    'customers/hcps' => function() {
      return [
        'criteria' => ['group' => 'physicians'],
        'elementType' => User::class,
        'one' => true,
        'pretty' => true,
        'cache' => 1,
        'transformer' => function() {
          $page = Craft::$app->request->getQueryParam('page', 1);
          $limit = 100;
          $offset = ($page - 1) * $limit;
          $usersData = [];
          $usersQ = User::find()->group('physicians');
          $usersQL = $usersQ->limit($limit)->offset($offset);
          $users = $usersQL->all();
          foreach ($users as $key => $user) {

            $usersData[$key]['email'] = $user->email;
            $usersData[$key]['firstName'] = $user->firstName;
            $usersData[$key]['lastName'] = $user->lastName;
            $usersData[$key]['name'] = $user->fullName;
            $usersData[$key]['status'] = $user->status;
            $usersData[$key]['masId'] = $user->masId;
            $usersData[$key]['businessName'] = $user->businessName;
            $usersData[$key]['businessAddress'] = $user->address;
            $usersData[$key]['businessAddressLine2'] = $user->addressLine2;
            $usersData[$key]['businessCity'] = $user->city;
            $usersData[$key]['businessState'] = $user->state;
            $usersData[$key]['businessZip'] = $user->zip;
            $usersData[$key]['address'] = getAddress($user);
            $usersData[$key]['phone'] = $user->phone;

            //tax
            $usersData[$key]['salesTaxExempt'] = $user->salesTaxExempt;
            $usersData[$key]['avataxCustomerUsageType'] = $user->avataxCustomerUsageType;
            $usersData[$key]['taxAcknowledgement'] = $user->taxAcknowledgement;


            $usersData[$key]['hcpStorefrontName'] = $user->hcpStorefrontName;
            $usersData[$key]['hpcStoreFrontText'] = $user->hpcStoreFrontText;
            $usersData[$key]['hcpEmailNotifications'] = $user->hcpEmailNotifications;
            $usersData[$key]['patients'] = getPatients($user);
            $usersData[$key]['productSelection'] = getHcpProducts($user);
            $usersData[$key]['enabledAutopay'] = $user->enableAutopayForPatients;
            $usersData[$key]['sharingDiscountEnabled'] = $user->hcpStorefrontDiscount->value ? true : false;
            $usersData[$key]['sharingDiscount'] = $user->hcpStorefrontDiscount->value;

          }
          $total = $usersQ->count();
          $count = $usersQL->count();
          $total_pages = ceil($total/100);
          $links = [];
          if( $page == 1 && $page < $total_pages && $count > 100 ){
            $links = ['next' => UrlHelper::siteUrl() . 'customers/hcps' . '?page=' . ($page + 1) ];
          }elseif( $page > 1 && $page < $total_pages && $count > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/hcps' . '?page=' . ($page - 1), 'next' => UrlHelper::siteUrl() . 'customers/hcps' . '?page=' . ($page + 1) ];
          }elseif( $page == $total_pages && $count > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/hcps' . '?page=' . ($page - 1)];
          }
          $usersData['meta'] = ['pagination' => ['total' => $total, 'count' => $count, 'per_page' => 100, 'current_page' => $page, 'total_pages' => $total_pages, 'links' => $links]];
          return $usersData;
        }
      ];
    },

    ## Getting PUPs 
    'customers/pup' => function() {
      return [
        'criteria' => ['group' => 'personal'],
        'elementType' => User::class,
        'one' => true,
        'pretty' => true,
        'cache' => 1,
        'transformer' => function() {
          $page = Craft::$app->request->getQueryParam('page', 1);
          $limit = 100;
          $offset = ($page - 1) * $limit;
          $usersData = [];
          $usersQ = User::find()->group('personal');
          $usersQL = $usersQ->limit($limit)->offset($offset);
          $users = $usersQL->all();
          foreach ($users as $key => $user) {
            $orderCount = Order::find()->user($user)->orderStatus(['not', 'autoShip'])->count();
            $usersData[$key]['email'] = $user->email;
            $usersData[$key]['firstName'] = $user->firstName;
            $usersData[$key]['lastName'] = $user->lastName;
            $usersData[$key]['name'] = $user->fullName;
            $usersData[$key]['status'] = $user->status;
            $usersData[$key]['masId'] = $user->masId;
            $usersData[$key]['businessName'] = $user->businessName;
            $usersData[$key]['businessAddress'] = $user->address;
            $usersData[$key]['businessAddressLine2'] = $user->addressLine2;
            $usersData[$key]['businessCity'] = $user->city;
            $usersData[$key]['businessState'] = $user->state;
            $usersData[$key]['businessZip'] = $user->zip;
            $usersData[$key]['address'] = getAddress($user);
            $usersData[$key]['phone'] = $user->phone;
            //tax
            $usersData[$key]['salesTaxExempt'] = $user->salesTaxExempt;
            $usersData[$key]['avataxCustomerUsageType'] = $user->avataxCustomerUsageType;
            $usersData[$key]['taxAcknowledgement'] = $user->taxAcknowledgement;

            $usersData[$key]['hcpStorefrontName'] = $user->hcpStorefrontName;
            $usersData[$key]['hpcStoreFrontText'] = $user->hpcStoreFrontText;
            $usersData[$key]['hcpEmailNotifications'] = $user->hcpEmailNotifications;
            $usersData[$key]['patients'] = getPatients($user);
            $usersData[$key]['productSelection'] = getHcpProducts($user);
            $usersData[$key]['enabledAutopay'] = $user->enableAutopayForPatients;
            $usersData[$key]['sharingDiscountEnabled'] = $user->hcpStorefrontDiscount->value ? true : false;
            $usersData[$key]['sharingDiscount'] = $user->hcpStorefrontDiscount->value;
            $usersData[$key]['orders'] = $orderCount ? UrlHelper::siteUrl() . 'customers/pup/orders/' . $user->id : null;
          }
          $total = $usersQ->count();
          $count = $usersQL->count();
          $total_pages = ceil($total/100);
          $links = [];
          if( $page == 1 && $page < $total_pages && $count > 100 ){
            $links = ['next' => UrlHelper::siteUrl() . 'customers/pup' . '?page=' . ($page + 1) ];
          }elseif( $page > 1 && $page < $total_pages && $count > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/pup' . '?page=' . ($page - 1), 'next' => UrlHelper::siteUrl() . 'customers/pup' . '?page=' . ($page + 1) ];
          }elseif( $page == $total_pages && $count > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/pup' . '?page=' . ($page - 1)];
          }
          $usersData['meta'] = ['pagination' => ['total' => $total, 'count' => $count, 'per_page' => 100, 'current_page' => $page, 'total_pages' => $total_pages, 'links' => $links]];
          return $usersData;
        }
      ];
    },

    ## Getting patient's orders
    'customers/pup/orders/<userId:\d+>' => function($userId) {
      return [
        'elementType' => User::class,
        'one' => true,
        'pretty' => true,
        'cache' => 1,
        'criteria' => ['id' => $userId],
        'transformer' => function(User $user) {
          $page = Craft::$app->request->getQueryParam('page', 1);
          $limit = 100;
          $offset = ($page - 1) * $limit;
          $ordersData = [];
          $ordersQ = Order::find()->user($user)->orderStatus(['not', 'autoShip']);
          $ordersQL = $ordersQ->limit($limit)->offset($offset);
          $orders = $ordersQL->all();
          foreach ($orders as $key => $order) {
            $ordersData[$key]['orderNumber'] = $order->number;
            $ordersData[$key]['dateOrdered'] = $order->dateOrdered->format('D jS M Y');
            $ordersData[$key]['lineItems'] = $order->lineItems;
            $ordersData[$key]['status'] = $order->orderStatus->name;
            $ordersData[$key]['billingAddress'] = $order->billingAddress->addressLines;
            $ordersData[$key]['shippingAddress'] = $order->shippingAddress->addressLines;
            $ordersData[$key]['discount'] = $order->totalDiscount;
            $ordersData[$key]['subtotal'] = $order->itemTotal;
            $ordersData[$key]['total'] = $order->totalPrice;
          }
          $total = $ordersQ->count();
          $count = $ordersQL->count();
          $total_pages = ceil($total/100);
          $links = [];
          if( $page == 1 && $page < $total_pages && $count > 100 ){
            $links = ['next' => UrlHelper::siteUrl() . 'customers/pup/orders/' . $user->id . '?page=' . ($page + 1) ];
          }elseif( $page > 1 && $page < $total_pages && $count > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/pup/orders/' . $user->id . '?page=' . ($page - 1), 'next' => UrlHelper::siteUrl() . 'customers/pup/orders/' . $user->id . '?page=' . ($page + 1) ];
          }elseif( $page == $total_pages && $count > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/pup/orders/' . $user->id . '?page=' . ($page - 1)];
          }
          $ordersData['meta'] = ['pagination' => ['total' => $total, 'count' => $count, 'per_page' => 100, 'current_page' => $page, 'total_pages' => $total_pages, 'links' => $links]];
          return $ordersData;
        }
      ];
    },


    ## Getting Patients
    'customers/patients' => function() {
      return [
        'criteria' => ['group' => 'patients'],
        'elementType' => User::class,
        'one' => true,
        'pretty' => true,
        'cache' => 1,
        'transformer' => function() {
          $page = Craft::$app->request->getQueryParam('page', 1);
          $limit = 100;
          $offset = ($page - 1) * $limit;
          $usersData = [];
          $usersQ = User::find()->group('patients');
          $usersQL = $usersQ->limit($limit)->offset($offset);
          $users = $usersQL->all();
          foreach ($users as $key => $user) {
            $autoShipCount = Order::find()->user($user)->orderStatus('autoShip')->makeThisARecurringOrder(1)->count();
            $orderCount = Order::find()->user($user)->orderStatus(['not', 'autoShip'])->count();
            $usersData[$key]['email'] = $user->email;
            $usersData[$key]['id'] = $user->id;
            $usersData[$key]['firstName'] = $user->firstName;
            $usersData[$key]['lastName'] = $user->lastName;
            $usersData[$key]['name'] = $user->fullName;
            $usersData[$key]['masId'] = $user->masId;
            //tax
            $usersData[$key]['salesTaxExempt'] = $user->salesTaxExempt;
            $usersData[$key]['avataxCustomerUsageType'] = $user->avataxCustomerUsageType;
            $usersData[$key]['taxAcknowledgement'] = $user->taxAcknowledgement;

            $usersData[$key]['address'] = getAddress($user);
            $usersData[$key]['relatedHcpEmail'] = $user->relatedHcp->count() ? $user->relatedHcp->one()->email : '';
            $usersData[$key]['relatedHcpMasId'] = $user->relatedHcp->count() ? $user->relatedHcp->one()->masId : '';
            $usersData[$key]['enrolled'] = $user->patientEnrolled;
            $usersData[$key]['suspended'] = false;
            //$usersData[$key]['orders'] = getOrders($user, false);
            $usersData[$key]['orders'] = $orderCount ? UrlHelper::siteUrl() . 'customers/patient/orders/' . $user->id : null;
            $usersData[$key]['autoShipOrders'] = $autoShipCount ? UrlHelper::siteUrl() . 'customers/patient/autoship-orders/' . $user->id : null;
          }
          $total = $usersQ->count();
          $count = $usersQL->count();
          $total_pages = ceil($total/100);
          $links = [];
          if( $page == 1 && $page < $total_pages && $total > 100 ){
            $links = ['next' => UrlHelper::siteUrl() . 'customers/patients' . '?page=' . ($page + 1) ];
          }elseif( $page > 1 && $page < $total_pages && $total > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/patients' . '?page=' . ($page - 1), 'next' => UrlHelper::siteUrl() . 'customers/patients' . '?page=' . ($page + 1) ];
          }elseif( $page == $total_pages && $total > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/patients' . '?page=' . ($page - 1)];
          }
          $usersData['meta'] = ['pagination' => ['total' => $total, 'count' => $count, 'per_page' => 100, 'current_page' => $page, 'total_pages' => $total_pages, 'links' => $links]];
          return $usersData;
        }
      ];
    },

    ## Getting patient's orders
    'customers/patient/orders/<userId:\d+>' => function($userId) {
      return [
        'elementType' => User::class,
        'one' => true,
        'pretty' => true,
        'cache' => 1,
        'criteria' => ['id' => $userId],
        'transformer' => function(User $user) {
          $page = Craft::$app->request->getQueryParam('page', 1);
          $limit = 100;
          $offset = ($page - 1) * $limit;
          $ordersData = [];
          $ordersQ = Order::find()->user($user)->orderStatus(['not', 'autoShip']);
          $ordersQL = $ordersQ->limit($limit)->offset($offset);
          $orders = $ordersQL->all();
          foreach ($orders as $key => $order) {
            $ordersData[$key]['orderNumber'] = $order->number;
            $ordersData[$key]['dateOrdered'] = $order->dateOrdered->format('D jS M Y');
            $ordersData[$key]['lineItems'] = $order->lineItems;
            $ordersData[$key]['status'] = $order->orderStatus->name;
            $ordersData[$key]['billingAddress'] = $order->billingAddress->addressLines;
            $ordersData[$key]['shippingAddress'] = $order->shippingAddress->addressLines;
            $ordersData[$key]['discount'] = $order->totalDiscount;
            $ordersData[$key]['subtotal'] = $order->itemTotal;
            $ordersData[$key]['total'] = $order->totalPrice;
          }
          $total = $ordersQ->count();
          $count = $ordersQL->count();
          $total_pages = ceil($total/100);
          $links = [];
          if( $page == 1 && $page < $total_pages && $count > 100 ){
            $links = ['next' => UrlHelper::siteUrl() . 'customers/patient/orders/' . $user->id . '?page=' . ($page + 1) ];
          }elseif( $page > 1 && $page < $total_pages && $count > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/patient/orders/' . $user->id . '?page=' . ($page - 1), 'next' => UrlHelper::siteUrl() . 'customers/patient/orders/' . $user->id . '?page=' . ($page + 1) ];
          }elseif( $page == $total_pages && $count > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/patient/orders/' . $user->id . '?page=' . ($page - 1)];
          }
          $ordersData['meta'] = ['pagination' => ['total' => $total, 'count' => $count, 'per_page' => 100, 'current_page' => $page, 'total_pages' => $total_pages, 'links' => $links]];
          return $ordersData;
        }
      ];
    },

    ## Getting patient's auto ship orders
    'customers/patient/autoship-orders/<userId:\d+>' => function($userId) {
      return [
        'elementType' => User::class,
        'one' => true,
        'pretty' => true,
        'cache' => 1,
        'criteria' => ['id' => $userId],
        'transformer' => function(User $user) {
          $page = Craft::$app->request->getQueryParam('page', 1);
          $limit = 100;
          $offset = ($page - 1) * $limit;
          $ordersData = [];
          $ordersQ = Order::find()->user($user)->orderStatus('autoShip')->makeThisARecurringOrder(1)->isCompleted();
          $ordersQL = $ordersQ->limit($limit)->offset($offset);
          $orders = $ordersQL->all();
          foreach ($orders as $key => $order) {
            $periodStart = null;
            $autoShipStatus = null;

            $sql = "SELECT `number` FROM craft_enupalstripe_orders WHERE variants LIKE '%{$order->number}%' ORDER BY dateOrdered DESC LIMIT 1";
            $recOrderQ = Craft::$app->db->createCommand($sql)->queryOne();
            if( $recOrderQ ){
              $recOrder = StripeOrder::find()->number($recOrderQ['number'])->one();
              if( $recOrder ){
                $subscription = $recOrder->getSubscription();
                if( $subscription->data )
                  $periodStart = date('D jS M Y', $subscription->data->current_period_start);
                $autoShipStatus = $subscription->status;
              }
            }
            $ordersData[$key]['orderNumber'] = $order->number;
            $ordersData[$key]['dateOrdered'] = $order->dateOrdered->format('D jS M Y');

            $ordersData[$key]['autoshipStatus'] = $autoShipStatus;
            $ordersData[$key]['frequency'] = $order->recurringOrderFrequency->label;
            $ordersData[$key]['periodStart'] = $periodStart;
            $ordersData[$key]['paidStatus'] = $order->paidStatus;

            $ordersData[$key]['lineItems'] = $order->lineItems;
            $ordersData[$key]['billingAddress'] = $order->billingAddress->addressLines;
            $ordersData[$key]['shippingAddress'] = $order->shippingAddress->addressLines;
            $ordersData[$key]['discount'] = $order->totalDiscount;
            $ordersData[$key]['subtotal'] = $order->itemTotal;
            $ordersData[$key]['total'] = $order->totalPrice;

            $ordersData[$key]['orderStatus'] = $order->orderStatus->name;
          }
          $total = $ordersQ->count();
          $count = $ordersQL->count();
          $total_pages = ceil($total/100);
          $links = [];
          if( $page == 1 && $page < $total_pages && $count > 100 ){
            $links = ['next' => UrlHelper::siteUrl() . 'customers/patient/autoship-orders' . $user->id . '?page=' . ($page + 1) ];
          }elseif( $page > 1 && $page < $total_pages && $count > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/patient/autoship-orders' . $user->id . '?page=' . ($page - 1), 'next' => UrlHelper::siteUrl() . 'customers/patient/autoship-orders' . $user->id . '?page=' . ($page + 1) ];
          }elseif( $page == $total_pages && $count > 100 ){
            $links = ['previous' => UrlHelper::siteUrl() . 'customers/patient/autoship-orders' . $user->id . '?page=' . ($page - 1)];
          }
          $ordersData['meta'] = ['pagination' => ['total' => $total, 'count' => $count, 'per_page' => 100, 'current_page' => $page, 'total_pages' => $total_pages, 'links' => $links]];
          return $ordersData;
        }
      ];
    }

  ]
];

function getPatients($user){
  $patientsData = [];
  $patients = User::find()->group('patients')->relatedTo(['taregt' => $user, 'field' => 'relatedHcp'])->all();
  foreach ($patients as $key => $patient) {
    $patientsData[$key]['email'] = $patient->email;
    $patientsData[$key]['id'] = $patient->id;
    $patientsData[$key]['enrolled'] = $patient->patientEnrolled;
    $patientsData[$key]['suspended'] = false;
    $patientsData[$key]['totalSales'] = getTotalSales($user);
  }
  return $patientsData;
}

function getTotalSales($user){
  $sql = "SELECT SUM(`co`.`itemTotal`) AS totalSales FROM `craft_commerce_orders` AS `co` JOIN `craft_commerce_customers` AS `cc` ON `co`.`customerId` = `cc`.`id` WHERE `cc`.`userId` = {$user->id}";
  $query = Craft::$app->db->createCommand($sql)->queryOne();
  return $query['totalSales'];
}

function getHcpProducts($user){
  $hcpProducts = [];
  if( $user->hcpProductsStore->count() ){
    $products = Product::find()->id($user->hcpProductsStore->ids())->asArray()->all();
    foreach ($products as $key => $product) {
      $hcpProducts[$key]['id'] = $product['id'];
      $hcpProducts[$key]['title'] = $product['title'];
      $hcpProducts[$key]['slug'] = $product['slug'];
      $hcpProducts[$key]['uri'] = $product['uri'];
      $hcpProducts[$key]['sku'] = $product['defaultSku'];
      $hcpProducts[$key]['price'] = $product['defaultPrice'];
    }
  }
  return $hcpProducts;
}

function getAddress($user){
  $addresses = [];
  $customer = craft\commerce\Plugin::getInstance()->getCustomers()->getCustomerByUserId($user->id);
  if( $customer ){
    $addresses = $customer->getAddresses();
  }
  return $addresses;
}

function getOrders($user, $autoShip = false){
  $ordersData = [];
  if( $autoShip )
    $orders = Order::find()->user($user)->orderStatus('autoShip')->all();
  else
    $orders = Order::find()->user($user)->orderStatus(['not', 'autoShip'])->all();
  if( !is_null($orders) ){
    foreach ($orders as $key => $order) {
      $ordersData[$key]['email'] = $order->email;
      $ordersData[$key]['orderNumber'] = $order->number;
      //$ordersData[$key]['dateOrdered'] = $order->dateOrdered->format('D jS M Y');
      $ordersData[$key]['billingAddress'] = $order->billingAddress->addressLines;
      $ordersData[$key]['shippingAddress'] = $order->shippingAddress->addressLines;
      $ordersData[$key]['lineItems'] = $order->lineItems;
      $ordersData[$key]['status'] = $order->orderStatus->name;
      $ordersData[$key]['shippingMethod'] = $order->shippingMethod->name;
      $ordersData[$key]['discount'] = $order->totalDiscount;
      //$ordersData[$key]['subtotal'] = $order->subtotal;
      $ordersData[$key]['total'] = $order->totalPrice;
    }
  }
  return $ordersData;
}