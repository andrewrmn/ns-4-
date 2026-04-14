<?php

namespace modules;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Application;
use yii\base\Event;
use yii\base\Model;
use yii\base\Module;

/**
 * Temporary debug: logs cart address validation for checkout (session 435c2e).
 */
class CheckoutAddressDebugModule extends Module
{
    private static function debugLog(string $hypothesisId, string $location, string $message, array $data): void
    {
        // #region agent log
        $path = dirname(__DIR__) . '/.cursor/debug-435c2e.log';
        $payload = [
            'sessionId' => '435c2e',
            'hypothesisId' => $hypothesisId,
            'location' => $location,
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) round(microtime(true) * 1000),
        ];
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        // #endregion
    }

    private static function strSnapshot(?string $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return '(set)';
    }

    private static function addressSnapshot($addr): array
    {
        if (!$addr) {
            return ['present' => false];
        }
        $phone = '';
        if (method_exists($addr, 'getFieldValue')) {
            $raw = $addr->getFieldValue('addressPhone');
            $phone = $raw === null || $raw === '' ? '' : '(set)';
        }

        return [
            'present' => true,
            'id' => $addr->id ?? null,
            'firstName' => self::strSnapshot($addr->firstName ?? null),
            'lastName' => self::strSnapshot($addr->lastName ?? null),
            'addressLine1' => self::strSnapshot($addr->addressLine1 ?? null),
            'locality' => self::strSnapshot($addr->locality ?? null),
            'postalCode' => self::strSnapshot($addr->postalCode ?? null),
            'administrativeArea' => self::strSnapshot($addr->administrativeArea ?? null),
            'addressPhoneField' => $phone,
        ];
    }

    public function init(): void
    {
        parent::init();

        if (!class_exists(Order::class)) {
            return;
        }

        Event::on(
            Application::class,
            Application::EVENT_INIT,
            static function (): void {
                $req = Craft::$app->getRequest();
                if ($req->getIsConsoleRequest() || $req->getMethod() !== 'GET') {
                    return;
                }
                $path = $req->getPathInfo();
                if (!is_string($path) || strpos($path, 'shop/checkout/addresses') === false) {
                    return;
                }
                if (!class_exists(\craft\commerce\Plugin::class)) {
                    return;
                }
                try {
                    $cart = \craft\commerce\Plugin::getInstance()->getCarts()->getCart();
                } catch (\Throwable $e) {
                    self::debugLog('H5', 'CheckoutAddressDebugModule::GET', 'no cart', ['error' => $e->getMessage()]);

                    return;
                }
                $user = Craft::$app->getUser()->getIdentity();
                $savedCount = $user && method_exists($user, 'getAddresses') ? count($user->getAddresses()) : 0;
                self::debugLog('H5', 'CheckoutAddressDebugModule::GET checkout addresses', 'page load snapshot', [
                    'cartId' => $cart->id ?? null,
                    'shippingAddressId' => $cart->shippingAddressId ?? null,
                    'billingAddressId' => $cart->billingAddressId ?? null,
                    'savedAddressCount' => $savedCount,
                    'shipping' => self::addressSnapshot($cart->getShippingAddress()),
                    'billing' => self::addressSnapshot($cart->getBillingAddress()),
                ]);
            }
        );

        Event::on(
            Order::class,
            Model::EVENT_BEFORE_VALIDATE,
            static function ($event): void {
                /** @var Order $order */
                $order = $event->sender;
                if (!$order->getIsActiveCart()) {
                    return;
                }
                self::debugLog('H1', 'CheckoutAddressDebugModule::BEFORE_VALIDATE', 'cart order validate start', [
                    'cartId' => $order->id ?? null,
                    'shippingAddressId' => $order->shippingAddressId ?? null,
                    'billingAddressId' => $order->billingAddressId ?? null,
                    'shipping' => self::addressSnapshot($order->getShippingAddress()),
                    'billing' => self::addressSnapshot($order->getBillingAddress()),
                ]);
            }
        );

        Event::on(
            Order::class,
            Model::EVENT_AFTER_VALIDATE,
            static function ($event): void {
                /** @var Order $order */
                $order = $event->sender;
                if (!$order->getIsActiveCart()) {
                    return;
                }
                $ship = $order->getShippingAddress();
                self::debugLog('H2', 'CheckoutAddressDebugModule::AFTER_VALIDATE', 'cart order validate end', [
                    'orderHasErrors' => $order->hasErrors(),
                    'orderErrors' => $order->getErrors(),
                    'shippingHasErrors' => $ship ? $ship->hasErrors() : null,
                    'shippingErrors' => $ship ? $ship->getErrors() : null,
                ]);
            }
        );
    }
}
