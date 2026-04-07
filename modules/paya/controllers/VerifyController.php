<?php

namespace modules\paya\controllers;

use craft\web\Controller;

class VerifyController extends Controller
{
    /** @inheritdoc Match untyped parent property (PHP 8.3+); do not add a native type. */
    public $enableCsrfValidation = false;

    /**
     * @var bool|int|array<string>
     */
    protected array|int|bool $allowAnonymous = ['index', 'verify-response'];

    /**
     * @return mixed
     */
    public function actionIndex()
    {
        return 'Welcome to the VerifyController actionIndex() method';
    }

    public function actionVerifyResponse()
    {
        if (!empty($_POST['response'])) {
            $respString = $_POST['response'];

            $respObj = json_decode($respString);

            $resp = json_encode($respObj->response);

            $hash = $respObj->hash;

            $calcHash = base64_encode(hash_hmac('sha512', $resp, 'rrvHwB3EensEuAFC', true));

            $item = ($hash === $calcHash ? 'true' : 'false');
        } else {
            $item = 'empty';
        }

        $response = [
            'Hash Match' => $item,
        ];

        return $this->asJson([
            $response,
        ]);
    }
}
