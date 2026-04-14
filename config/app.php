<?php
/**
 * Yii Application Config
 *
 * Edit this file at your own risk!
 *
 * The array returned by this file will get merged with
 * vendor/craftcms/cms/src/config/app.php and app.[web|console].php, when
 * Craft's bootstrap script is defining the configuration for the entire
 * application.
 *
 * You can define custom modules and system components, and even override the
 * built-in system components.
 *
 * If you want to modify the application config for *only* web requests or
 * *only* console requests, create an app.web.php or app.console.php file in
 * your config/ folder, alongside this one.
 */


return [
    'modules' => [
        'pending-user-module' => [
            'class' => \modules\pendingusermodule\PendingUserModule::class,
        ],
        'stripe-webhook-module' => [
            'class' => \modules\StripeWebhookModule::class,
        ],
        'admin-emails-module' => [
            'class' => \modules\AdminEmailsModule::class,
        ],
        'neuro-rewards-module' => [
            'class' => \modules\NeuroRewardsModule::class,
        ],
        'paya' => [
            'class' => \modules\PayaModule::class,
        ],
        'guest-pricing' => [
            'class' => \modules\GuestPricingModule::class,
        ],
        'hcp-workspace' => [
            'class' => \modules\HcpWorkspaceModule::class,
        ],
        'patient-shop' => [
            'class' => \modules\PatientShopModule::class,
        ],
        'autoship-schedule' => [
            'class' => \modules\AutoshipScheduleModule::class,
        ],
        'neuroselect-module' => [
            'class' => \modules\NeuroSelectModule::class,
        ],
        'checkout-address-debug' => [
            'class' => \modules\CheckoutAddressDebugModule::class,
        ],
    ],
    'bootstrap' => [
        'pending-user-module',
        'stripe-webhook-module',
        // 'admin-emails-module', // disabled — re-add to enable
        'neuro-rewards-module',
        'paya',
        'guest-pricing',
        'hcp-workspace',
        'patient-shop',
        'autoship-schedule',
        'neuroselect-module',
        'checkout-address-debug',
    ],
];
