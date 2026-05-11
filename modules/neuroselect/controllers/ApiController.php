<?php
/**
 * neuroselect plugin for Craft CMS 3.x
 *
 * Pull Data from the NeuroScience app and display in User Profiles
 *
 * @link      andrewross.co
 * @copyright Copyright (c) 2020 Andrew Ross
 */

namespace modules\neuroselect\controllers;


use modules\neuroselect\NeuroselectProductHelper;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use yii\web\Security;
use craft\elements\User;
use craft\elements\Entry;
use verbb\supertable\SuperTable;

/**
 * Api Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Andrew Ross
 * @package   Neuroselect
 * @since     1.0.0
 */
class ApiController extends Controller
{

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    //protected array|int|bool $allowAnonymous = ['index', 'login'];
    protected bool|int|array $allowAnonymous = true;

    public $enableCsrfValidation = false;

    // Public Methods
    // =========================================================================

    ////////////////////////// Login //////////////////////////
    public function actionLogin()
    {
        $this->requirePostRequest();
        $username = Craft::$app->request->getBodyParam('Username');
        $user = Craft::$app->users->getUserByUsernameOrEmail($username);
        $postedPassword = Craft::$app->request->getBodyParam('Password');

        if($user) {
            if ( Craft::$app->security->validatePassword($postedPassword, $user->password)) {
                $token = bin2hex(random_bytes(32));
                $user->setFieldValue('appToken', $token);
                $curerntToken = $user->getFieldValue('appToken');
                Craft::$app->elements->saveElement($user);
                $response = [
                    'success' => true,
                    'token' => $user->getFieldValue('appToken')
                ];
            } else {
                header("HTTP/1.1 400 Bad Request");
                $response = [
                    'error' => 'Password does not match'
                ];
            }

        } else {
            header("HTTP/1.1 400 Bad Request");
            $response = [
                'error' => 'No user found with this username or email'
            ];
        }
        // return $this->asJson([
        //     $response
        // ]);
        return $this->asJson($response);
    }


    ////////////////////////// QR SCAN SUBMISSIONS //////////////////////////
    // public function actionUpdateUsers()
    // {
    //     $this->requirePostRequest();
    //     $data = Craft::$app->request->getBodyParam('Data');
    //     if( empty($data) ) {
    //         header("HTTP/1.1 400 Bad Request");
    //         $response = [
    //             'status' => 'Error: Incomplete submission',
    //             'resultUrl' => ''
    //         ];
    //     } else {
    //         // Get User based on token
    //         //$users = User::find()->getPost('salesTaxExempt')->all();
    //         $userQuery = User::find();
    //         //$userQuery->salesTaxExempt = [1, 'true', true];
    //         $userQuery->salesTaxExempt = true;
    //         $users = $userQuery->all();
    //         //$max = sizeof($users);
    //         //header("HTTP/1.1 201 Created");
    //         // $response = [
    //         //     'status' => 'success',
    //         //     'sizeOf' => $max
    //         // ];
    //         $success = true;
    //         foreach($users as $user) {
    //             $user->setFieldValues(['avataxCustomerUsageType' => 'N']);
    //             // Save the user
    //             Craft::$app->getElements()->saveElement($user);
    //             // Check for successful save
    //             $success = Craft::$app->getElements()->saveElement($user);
    //             if(!$success) {
    //                 $success = false;
    //             }
    //         }
    //     }
    //
    //     if($success) {
    //         $response = [
    //             'status' => 'success'
    //         ];
    //     } else {
    //         $response = [
    //             'status' => 'fail brah'
    //         ];
    //     }
    //     return $this->asJson([
    //         $response
    //     ]);
    // }


    ////////////////////////// QR SCAN SUBMISSIONS //////////////////////////
    public function actionQrScan()
    {

        $this->requirePostRequest();

        $data = Craft::$app->request->getBodyParam('Data');
        $category = Craft::$app->request->getBodyParam('Category');
        $userToken = Craft::$app->request->getBodyParam('Token');

        if( empty($data) || empty($category) || empty($userToken) ) {
            header("HTTP/1.1 400 Bad Request");
            $response = [
                'status' => 'Error: Incomplete submission',
                'resultUrl' => ''
            ];

        } else {
            // Get User based on token
            $users = User::find()
                ->appToken($userToken)
                ->all();

            if( $users ) {
                // if user is found
                $user = $users[0];

                // Access Super Table Field
                $superTableData = array();
                $field = Craft::$app->getFields()->getFieldByHandle('qrScanSubmissions');
                $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($field->id);
                $blockType = $blockTypes[0];

                $randId = str_pad(mt_rand(1,99999999),8,'0',STR_PAD_LEFT);

                $superTableData['new1'] = array(
                    'type' => $blockType->id,
                    'enabled' => true,
                    'fields' => array(
                        'submissionId' => $randId,
                        'date' => date('Y-m-d'),
                        'data' => $data,
                        'category' => $category[0],
                        'pdfGenerated' => 0
                    )
                );

                // Don't lose current Super Table Field rows
                $currentSubmissions = $user->qrScanSubmissions;
                $i = 2;
                foreach ($currentSubmissions as $block){
                  $superTableData[$i] = array(
                      'type' => $blockType->id,
                      'enabled' => true,
                      'fields' => array(
                          'submissionId' => $block->submissionId,
                          'date' => $block->date,
                          'data' => $block->data,
                          'category' => $block->category,
                          'pdfGenerated' => $block->pdfGenerated
                      )
                  );
                  $i++;
                }

                // Update Super Table with new content
                //$user->setContentFromPost(array('qrScanSubmissions' => $superTableData));
                $user->setFieldValues(['qrScanSubmissions' => $superTableData]);

                // Save the user
                //craft()->users->saveUser($user);
                Craft::$app->getElements()->saveElement($user);

                // Check for successful save
                $success = Craft::$app->getElements()->saveElement($user);

                if($success) {
                    header("HTTP/1.1 201 Created");

                    $returnUrl = 'https://www.neuroscienceinc.com/account/neuroselect/qrscan/' . $randId . '?q=' . $user->email;

                    $response = [
                        'status' => 'success',
                        'resultUrl' => $returnUrl
                    ];

                } else {
                    header("HTTP/1.1 400 Bad Request");

                    $response = [
                        'status' => 'Error: Submission was not saved to the users profile',
                        'resultUrl' => ''
                    ];

                    $email = new EmailModel();
                    $email->toEmail = 'andrewross.mn@gmail.com';
                    $email->subject = 'Error adding QR Scan Submission';
                    $email->body = 'For user: ' . $userEmail;
                    craft()->email->sendEmail($email);
                }

            } else {

                // User not found
                header("HTTP/1.1 400 Bad Request");

                $response = [
                    'status' => 'No user match for this Token',
                    'resultUrl' => ''
                ];
            }
        }

        return $this->asJson($response);
    }



    ////////////////////////// Pathway SUBMISSIONS //////////////////////////
    public function actionPathway()
    {

        $this->requirePostRequest();

        //get the post data now that it is stored in $_POST. Under the hood this is the same thing as doing $statusText = $_POST['OrderStatus'];
        $pathways = Craft::$app->request->getBodyParam('Pathways');
        $pathways = implode(", ", $pathways);
        $category = Craft::$app->request->getBodyParam('Category');
        $age = Craft::$app->request->getBodyParam('Age');
        $gender = Craft::$app->request->getBodyParam('Gender');
        $userToken = Craft::$app->request->getBodyParam('Token');

        if( empty($pathways) || empty($category) || empty($age) || empty($gender) || empty($userToken) ) {

            header("HTTP/1.1 400 Bad Request");

            $response = [
                'status' => 'Error: Incomplete submission',
                'resultUrl' => ''
            ];

        } else {


            // Get User based on token
            $users = User::find()
                ->appToken($userToken)
                ->all();

            if( $users ) {

                // if user is found
                $user = $users[0];

                // Access Super Table Field
                $superTableData = array();
                $field = Craft::$app->getFields()->getFieldByHandle('pathwaySubmissions');
                $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($field->id);
                $blockType = $blockTypes[0];

                $randId = str_pad(mt_rand(1,99999999),8,'0',STR_PAD_LEFT);

                $superTableData['new1'] = array(
                    'type' => $blockType->id,
                    'enabled' => true,
                    'fields' => array(
                        'submissionId' => $randId,
                        'date' => date('Y-m-d'),
                        'pathways' => $pathways,
                        'category' => $category[0],
                        'age' => $age,
                        'gender' => $gender,
                        'pdfGenerated' => 0
                    )
                );

                // Don't lose current Super Table Field rows
                $currentSubmissions = $user->pathwaySubmissions;
                $i = 2;
                foreach ($currentSubmissions as $block){
                  $superTableData[$i] = array(
                      'type' => $blockType->id,
                      'enabled' => true,
                      'fields' => array(
                          'submissionId' => $block->submissionId,
                          'date' => $block->date,
                          'pathways' => $block->pathways,
                          'category' => $block->category,
                          'age' => $block->age,
                          'gender' => $block->gender,
                          'pdfGenerated' => $block->pdfGenerated
                      )
                  );
                  $i++;
                }

                // Update Super Table with new content
                $user->setFieldValues(['pathwaySubmissions' => $superTableData]);
                // Save the user
                Craft::$app->getElements()->saveElement($user);

                // Check for successful save
                $success = Craft::$app->getElements()->saveElement($user);

                if($success) {
                    header("HTTP/1.1 201 Created");

					$returnUrl = 'https://www.neuroscienceinc.com/account/neuroselect/pathway/' . $randId . '?q=' . $user->email;

                    $response = [
                        'status' => 'success',
                        'resultUrl' => $returnUrl
                    ];

                } else {
                    header("HTTP/1.1 400 Bad Request");

                    $response = [
                        'status' => 'Error: Submission was not saved to the users profile',
                        'resultUrl' => ''
                    ];

                    $email = new EmailModel();
                    $email->toEmail = 'andrewross.mn@gmail.com';
                    $email->subject = 'Error adding QR Scan Submission';
                    $email->body = 'For user: ' . $userEmail;
                    craft()->email->sendEmail($email);
                }

            } else {

                // User not found
                header("HTTP/1.1 400 Bad Request");

                $response = [
                    'status' => 'Error: No user match for this Token',
                    'resultUrl' => ''
                ];
            }
        }

        return $this->asJson($response);

    }


    ////////////////////////// Sleep SUBMISSIONS //////////////////////////
    public function actionSleep()
    {
        $this->requirePostRequest();

        //get the post data now that it is stored in $_POST. Under the hood this is the same thing as doing $statusText = $_POST['OrderStatus'];
        $secondaryConcerns = Craft::$app->request->getBodyParam('Sleep');
        $secondaryConcerns = implode(", ", $secondaryConcerns);

        $relevantPathways = Craft::$app->request->getBodyParam('SleepPathways');
        $relevantPathways = implode(", ", $relevantPathways);

        $excludedIngredients = Craft::$app->request->getBodyParam('SleepIngredientExclusions');
        $excludedIngredients = implode(", ", $excludedIngredients);

        $preferredPhase = Craft::$app->request->getBodyParam('PreferredSleepPathwaySupport');
        $age = Craft::$app->request->getBodyParam('Age');
        $gender = Craft::$app->request->getBodyParam('Gender');
        $userToken = Craft::$app->request->getBodyParam('Token');


        if( empty($preferredPhase) || empty($age) || empty($userToken) ) {
            header("HTTP/1.1 400 Bad Request");
            $response = [
                'status' => 'Error: Incomplete submission',
                'resultUrl' => ''
            ];
        } else {

            // Get User based on token
            $users = User::find()
                ->appToken($userToken)
                ->all();

            if( $users ) {

                // if user is found
                $user = $users[0];

                // Access Super Table Field
                $superTableData = array();
                $field = Craft::$app->getFields()->getFieldByHandle('sleepSubmission');
                $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($field->id);
                $blockType = $blockTypes[0];

                $randId = str_pad(mt_rand(1,99999999),8,'0',STR_PAD_LEFT);

                $superTableData['new1'] = array(
                    'type' => $blockType->id,
                    'enabled' => true,
                    'fields' => array(
                        'submissionId' => $randId,
                        'date' => date('Y-m-d'),
                        'concerns' => $secondaryConcerns,
                        'relevantPathways' => $relevantPathways,
                        'excludedIngredients' => $excludedIngredients,
                        'preferredPhase' => $preferredPhase,
                        'age' => $age,
                        'gender' => $gender,
                        'pdfGenerated' => 0
                    )
                );

                // Don't lose current Super Table Field rows
                $currentSubmissions = $user->sleepSubmission;
                $i = 2;
                foreach ($currentSubmissions as $block){
                  $superTableData[$i] = array(
                      'type' => $blockType->id,
                      'enabled' => true,
                      'fields' => array(
                          'submissionId' => $block->submissionId,
                          'date' => $block->date,
                          'concerns' => $block->concerns,
                          'relevantPathways' => $block->relevantPathways,
                          'excludedIngredients' => $block->excludedIngredients,
                          'preferredPhase' => $block->preferredPhase,
                          'age' => $block->age,
                          'gender' => $block->gender,
                          'pdfGenerated' => $block->pdfGenerated
                      )
                  );
                  $i++;
                }

                // Update Super Table with new content
                $user->setFieldValues(['sleepSubmission' => $superTableData]);
                // Save the user
                Craft::$app->getElements()->saveElement($user);

                // Check for successful save
                $success = Craft::$app->getElements()->saveElement($user);

                if($success) {
                    header("HTTP/1.1 201 Created");

					$returnUrl = 'https://www.neuroscienceinc.com/account/neuroselect/sleep/' . $randId . '?q=' . $user->email;

                    $response = [
                        'status' => 'success',
                        'resultUrl' => $returnUrl
                    ];

                } else {
                    header("HTTP/1.1 400 Bad Request");

                    $response = [
                        'status' => 'Error: Submission was not saved to the users profile',
                        'resultUrl' => ''
                    ];

                    $email = new EmailModel();
                    $email->toEmail = 'andrewross.mn@gmail.com';
                    $email->subject = 'Error adding QR Scan Submission';
                    $email->body = 'For user: ' . $userEmail;
                    craft()->email->sendEmail($email);
                }

            } else {

                // User not found
                header("HTTP/1.1 400 Bad Request");

                $response = [
                    'status' => 'Error: No user match for this Token',
                    'resultUrl' => ''
                ];
            }
        }

        return $this->asJson($response);

    }



    ////////////////////////// Clinical Indication SUBMISSIONS //////////////////////////
    public function actionClinicalIndication()
    {

        $this->requirePostRequest();


        //get the post data now that it is stored in $_POST. Under the hood this is the same thing as doing $statusText = $_POST['OrderStatus'];
        $clinicalIndicators = Craft::$app->request->getBodyParam('ClinicalIndicators');
        $clinicalIndicators = implode(", ", $clinicalIndicators);

        $category = Craft::$app->request->getBodyParam('Category');
        $age = Craft::$app->request->getBodyParam('Age');
        $gender = Craft::$app->request->getBodyParam('Gender');
        $userToken = Craft::$app->request->getBodyParam('Token');


        if( empty($clinicalIndicators) || empty($category) || empty($age) || empty($gender) || empty($userToken) ) {

            header("HTTP/1.1 400 Bad Request");

            $response = [
                'status' => 'Error: Incomplete submission',
                'resultUrl' => ''
            ];

        } else {

            // Get User based on token
            $users = User::find()
                ->appToken($userToken)
                ->all();

            if( $users ) {

                // if user is found
                $user = $users[0];

                // Access Super Table Field
                $superTableData = array();
                $field = Craft::$app->getFields()->getFieldByHandle('clinicalIndicationSubmission');
                $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($field->id);
                $blockType = $blockTypes[0];

                $randId = str_pad(mt_rand(1,99999999),8,'0',STR_PAD_LEFT);

                $superTableData['new1'] = array(
                    'type' => $blockType->id,
                    'enabled' => true,
                    'fields' => array(
                        'submissionId' => $randId,
                        'date' => date('Y-m-d'),
                        'clinicalindicators' => $clinicalIndicators,
                        'category' => $category[0],
                        'age' => $age,
                        'gender' => $gender,
                        'pdfGenerated' => 0
                    )
                );

                // Don't lose current Super Table Field rows
                $currentSubmissions = $user->clinicalIndicationSubmission;
                $i = 2;
                foreach ($currentSubmissions as $block){
                  $superTableData[$i] = array(
                      'type' => $blockType->id,
                      'enabled' => true,
                      'fields' => array(
                          'submissionId' => $block->submissionId,
                          'date' => $block->date,
                          'clinicalindicators' => $block->clinicalindicators,
                          'category' => $block->category,
                          'age' => $block->age,
                          'gender' => $block->gender,
                          'pdfGenerated' => $block->pdfGenerated
                      )
                  );
                  $i++;
                }

                // Update Super Table with new content
                $user->setFieldValues(['clinicalIndicationSubmission' => $superTableData]);

                // Save the user
                Craft::$app->getElements()->saveElement($user);

                // Check for successful save
                $success = Craft::$app->getElements()->saveElement($user);

                if($success) {
                    header("HTTP/1.1 201 Created");

                    $returnUrl = 'https://www.neuroscienceinc.com/account/neuroselect/clinicalindication/' . $randId . '?q=' . $user->email;

                    $response = [
                        'status' => 'success',
                        'resultUrl' => $returnUrl
                    ];

                } else {
                    header("HTTP/1.1 400 Bad Request");

                    $response = [
                        'status' => 'Error: Submission was not saved to the users profile',
                        'resultUrl' => ''
                    ];


                    $email = new EmailModel();
                    $email->toEmail = 'andrewross.mn@gmail.com';
                    $email->subject = 'Error adding QR Scan Submission';
                    $email->body = 'For user: ' . $userEmail;
                    craft()->email->sendEmail($email);
                }

            } else {

                // User not found
                header("HTTP/1.1 400 Bad Request");

                $response = [
                    'status' => 'Error: No user match for this Token',
                    'resultUrl' => ''
                ];
            }
        }

        return $this->asJson($response);
    }



    ////////////////////////// Product SUBMISSIONS //////////////////////////
    public function actionProducts()
    {
        $this->requirePostRequest();

        //get the post data now that it is stored in $_POST. Under the hood this is the same thing as doing $statusText = $_POST['OrderStatus'];
        $productField = Craft::$app->request->getBodyParam('Products');
        $products = NeuroselectProductHelper::normalizeProductsPostParam($productField);
        $age = Craft::$app->request->getBodyParam('Age');
        $gender = Craft::$app->request->getBodyParam('Gender');
        $userToken = Craft::$app->request->getBodyParam('Token');

        if( empty($products) || empty($age) || empty($gender) || empty($userToken) ) {

            header("HTTP/1.1 400 Bad Request");

            $response = [
                'status' => 'Error: Incomplete submission',
                'resultUrl' => ''
            ];

        } else {


            // Get User based on token
            $users = User::find()
                ->appToken($userToken)
                ->all();

            if( $users ) {

                // if user is found
                $user = $users[0];

                // Access Super Table Field
                $superTableData = array();
                $field = Craft::$app->getFields()->getFieldByHandle('productSubmission');
                $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($field->id);
                $blockType = $blockTypes[0];

                $randId = str_pad(mt_rand(1,99999999),8,'0',STR_PAD_LEFT);

                $superTableData['new1'] = array(
                    'type' => $blockType->id,
                    'enabled' => true,
                    'fields' => array(
                        'submissionId' => $randId,
                        'date' => date('Y-m-d'),
                        'products' => $products,
                        'age' => $age,
                        'gender' => $gender,
                        'pdfGenerated' => 0
                    )
                );

                // Don't lose current Super Table Field rows
                $currentSubmissions = $user->productSubmission;
                $i = 2;
                foreach ($currentSubmissions as $block){
                  $superTableData[$i] = array(
                      'type' => $blockType->id,
                      'enabled' => true,
                      'fields' => array(
                          'submissionId' => $block->submissionId,
                          'date' => $block->date,
                          'products' => $block->products,
                          'age' => $block->age,
                          'gender' => $block->gender,
                          'pdfGenerated' => $block->pdfGenerated
                      )
                  );
                  $i++;
                }

                // Update Super Table with new content
                $user->setFieldValues(['productSubmission' => $superTableData]);

                // Save the user
                Craft::$app->getElements()->saveElement($user);

                // Check for successful save
                $success = Craft::$app->getElements()->saveElement($user);

                if($success) {
                    header("HTTP/1.1 201 Created");

					$returnUrl = 'https://www.neuroscienceinc.com/account/neuroselect/products/' . $randId . '?q=' . $user->email;

                    $response = [
                        'status' => 'success',
                        'resultUrl' => $returnUrl
                    ];

                } else {
                    header("HTTP/1.1 400 Bad Request");

                    $response = [
                        'status' => 'Error: Submission was not saved to the users profile',
                        'resultUrl' => ''
                    ];

                    $email = new EmailModel();
                    $email->toEmail = 'andrewross.mn@gmail.com';
                    $email->subject = 'Error adding QR Scan Submission';
                    $email->body = 'For user: ' . $userEmail;
                    craft()->email->sendEmail($email);
                }

            } else {

                // User not found
                header("HTTP/1.1 400 Bad Request");

                $response = [
                    'status' => 'Error: No user match for this Token',
                    'resultUrl' => ''
                ];
            }
        }

        return $this->asJson($response);
    }

}
