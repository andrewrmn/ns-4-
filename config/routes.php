<?php
/**
 * Site URL Rules
 *
 * You can define custom site URL rules here, which Craft will check in addition
 * to any routes you’ve defined in Settings → Routes.
 *
 * See http://www.yiiframework.com/doc-2.0/guide-runtime-routing.html for more
 * info about URL rules.
 *
 * In addition to Yii’s supported syntaxes, Craft supports a shortcut syntax for
 * defining template routes:
 *
 *     'blog/archive/<year:\d{4}>' => ['template' => 'blog/_archive'],
 *
 * That example would match URIs such as `/blog/archive/2012`, and pass the
 * request along to the `blog/_archive` template, providing it a `year` variable
 * set to the value `2012`.
 */

 return [
     'appConnector/authentication' => 'neuroselect-module/api/login',
     'appConnector/updateUsers' => 'neuroselect-module/api/update-users',
     'appConnector/qrscan' => 'neuroselect-module/api/qr-scan',
     'appConnector/pathway' => 'neuroselect-module/api/pathway',
     'appConnector/clinicalindication' => 'neuroselect-module/api/clinical-indication',
     'appConnector/products' => 'neuroselect-module/api/products',
     'appConnector/sleep' => 'neuroselect-module/api/sleep',
     'account/orders' => ['template' => '/shop/customer/orders.html'],
     'account/product-information-requests' => ['template' => 'shop/customer/pir.html'],
     'account' => ['template' => 'shop/customer/profile.html'],
     'account/addresses' => ['template' => 'shop/customer/addresses.html'],
     'reports/product-information-requests' => ['template' => '/admin/pir-reports.html'],
     'account/product-information-requests/edit' => ['template' => '/shop/customer/submissions/edit.html'],
     'account/neuroselect/sleep/edit' => ['template' => 'neuroselect/sleep/edit.html'],
     'account/neuroselect/qrscan/<number>' => ['template' => 'neuroselect/submissions/submission.html'],
     'account/neuroselect/clinicalindication/<number>' => ['template' => 'neuroselect/submissions/submission.html'],
     'account/neuroselect/pathway/<number>' => ['template' => 'neuroselect/submissions/submission.html'],
     'account/neuroselect/products/<number>' => ['template' => 'neuroselect/submissions/submission.html'],
     'account/neuroselect/sleep/<number>' => ['template' => 'neuroselect/submissions/submission.html'],
     'account/neuroselect/neurocore/<number>' => ['template' => 'neuroselect/submissions/neurocore.html'],
     'account/neuroselect' => ['template' => 'neuroselect/index.html'],
     'account/neuro-q' => ['template' => 'neuroselect/survey/index.html'],
     'account/neuroselect/edit' => ['template' => 'neuroselect/submissions/edit.html'],
     'product-detail/<slug>' => ['template' => '/products/_detail'],
     'account/neuroselect/pathway/<number>' => ['template' => 'neuroselect/submissions/submission.html'],
     'Neuro-Q' => ['template' => 'neuroselect/survey/questions.html'],
     'Neuro-Q/report/<number>' => ['template' => 'neuroselect/survey/report.html'],
     'survey' => ['template' => 'neuroselect/survey/questions.html'],
     'survey/report/<number>' => ['template' => 'neuroselect/survey/report.html'],
     'neuro-q/report/<number>' => ['template' => 'neuroselect/survey/report.html'],
     'appConnector/survey' => 'neuroselect-module/survey/survey-submission',
      'apply-coupon' => 'commerce/cart/applyCoupon',
 ];
