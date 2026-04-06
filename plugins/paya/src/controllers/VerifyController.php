<?php
/**
 * Paya plugin for Craft CMS 3.x
 *
 * Checkout with Paya via Payments JS
 *
 * @link      andrewross.co
 * @copyright Copyright (c) 2020 Andrew Ross Co.
 */

namespace neuroscience\paya\controllers;

use neuroscience\paya\Paya;

use Craft;
use craft\web\Controller;

/**
 * @author    Andrew Ross Co.
 * @package   Paya
 * @since     1.0.0
 */
class VerifyController extends Controller
{
	
		public $enableCsrfValidation = false;

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    //protected $allowAnonymous = ['index', 'do-something'];
    protected $allowAnonymous = ['index', 'do-something', 'verify-response'];

    // Public Methods
    // =========================================================================

    /**
     * @return mixed
     */
    public function actionIndex()
    {
        $result = 'Welcome to the VerifyController actionIndex() method';

        return $result;
    }

    public function actionVerifyResponse()
    {
        //$this->requireAjaxRequest();

        if(!empty($_POST['response'])){
            //$item = 'pass';
            //$item = $_POST['response'];

            $respString = $_POST['response'];

            $respObj = json_decode($respString);

            $resp = json_encode($respObj->response);

            $hash = $respObj->hash;

            $calcHash = base64_encode(hash_hmac('sha512', $resp, 'rrvHwB3EensEuAFC', true));

            $item = ($hash === $calcHash ? "true" : "false");
            //$item = 'Hash = ' . $hash . '  calcHash = ' . $calcHash;

        } else {
            $item = 'empty';
        }

        $response = [
            'Hash Match' => $item
        ];

        return $this->asJson([
            $response
        ]);
    }
}
