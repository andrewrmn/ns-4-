<?php
/**
 * neuroselect plugin for Craft CMS 3.x
 *
 * Pull Data from the NeuroScience app and display in User Profiles
 *
 * @link      andrewross.co
 * @copyright Copyright (c) 2020 Andrew Ross
 */

namespace neuroscience\neuroselect\controllers;

use neuroscience\neuroselect\Neuroselect;

use Craft;
use craft\web\Controller;
use verbb\supertable\SuperTable;
use craft\mail\Message;

/**
 * Pdf Controller
 *
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Andrew Ross
 * @package   Neuroselect
 * @since     1.0.0
 */
class PdfController extends Controller
{

    protected $allowAnonymous = true;

    public function actionGeneratePdf()
    {

        //$this->requirePostRequest();

        if( !empty($_POST['source']) ) {

            $source = $_POST['source'];
            $submissionId = $_POST['submissionId'];
            $userId = $_POST['userId'];
            $submissionType = $_POST['submissionType'];

            $footer = array(
                "source" => "<div style=\"border-top: 1px solid #F4F5F7; padding: 8px 20px 0; width: 90%; margin: 0 auto; font-family: sans-serif; \"><div style=\"font-size: 4pt; font-family: sans-serif;\">***Do not exceed suggested use</div><div style=\"font-size: 4pt; font-weight: bold; font-family: sans-serif; padding: 2px; border: 1px solid #000; margin-bottom: 5px; \">*These statements have not been evaluated by the Food and Drug Administration. This product is not intended to diagnose, treat, cure or prevent any disease.</div><div style=\"font-size: 4pt; font-family: sans-serif;\">Product information was requested by a healthcare provider and is not intended to diagnose, treat, cure or prevent any diseases. References provided are not specific to an individual and do not change based on the product information request. Products selected are based on specific requests or information presented indicating the goal is to select ingredients with mechanisms that scientifically promote biochemical pathway(s) or clinical indication(s) to theoretically shift toward the statistical median or have research indicating a symptom could be correlated to an element in a pathway.</div></div>",
                "spacing" => '80px'
            );

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.pdfshift.io/v2/convert/",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(array("source" => $source, "css" => 'https://www.neuroscienceinc.com/css/pdf9.css', "sandbox" => false, "use_print" => false, "footer" => $footer )),
                CURLOPT_HTTPHEADER => array('Content-Type:application/json'),
                CURLOPT_USERPWD => 'e53c829dce5f4013a28cf3eee62b731c'
            ));

            $response = curl_exec($curl);

            // Create the filename
            $filename = 'NS-PIR-' . $userId . '-' . $submissionId . '.pdf';

            // Save to correct folder
            $saveTo = './pir-documents/' . $filename;

            file_put_contents($saveTo, $response);

            if ($response) {

                //Get Current User
                $user = Craft::$app->users->getUserById($userId);
                //$user = Craft::$app->getUser()->getIdentity();

                // Access Super Table Field
                $superTableData = array();
                $currentSubmissions = '';

                if ( $submissionType == 'qrscan' ) {
                    $field = Craft::$app->getFields()->getFieldByHandle('qrScanSubmissions');
                    $currentSubmissions = $user->qrScanSubmissions;
                }
                if ( $submissionType == 'pathway' ) {
                    $field = Craft::$app->getFields()->getFieldByHandle('pathwaySubmissions');
                    $currentSubmissions = $user->pathwaySubmissions;
                }
                if ( $submissionType == 'clinicalindication' ) {
                    $field = Craft::$app->getFields()->getFieldByHandle('clinicalIndicationSubmission');
                    $currentSubmissions = $user->clinicalIndicationSubmission;
                }
                if ( $submissionType == 'products' ) {
                    $field = Craft::$app->getFields()->getFieldByHandle('productSubmission');
                    $currentSubmissions = $user->productSubmission;
                }


                $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($field->id);
                $blockType = $blockTypes[0];



                $i = 2;
                foreach ($currentSubmissions as $block){

                    if ($block->submissionId != $submissionId) {
                        $pdfGen = $block->pdfGenerated;
                    } else {
                        $pdfGen = 1;
                    }

                    if ( $submissionType == 'qrscan' ) {
                        $superTableData[$i] = array(
                            'type' => $blockType->id,
                            'enabled' => true,
                            'fields' => array(
                                'submissionId' => $block->submissionId,
                                'date' => $block->date,
                                'data' => $block->data,
                                'category' => $block->category,
                                'pdfGenerated' => $pdfGen
                            )
                        );
                    }
                    if ( $submissionType == 'pathway' ) {
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
                                'pdfGenerated' => $pdfGen
                            )
                        );
                    }
                    if ( $submissionType == 'clinicalindication' ) {
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
                                'pdfGenerated' => $pdfGen
                            )
                        );
                    }
                    if ( $submissionType == 'products' ) {
                        $superTableData[$i] = array(
                            'type' => $blockType->id,
                            'enabled' => true,
                            'fields' => array(
                                'submissionId' => $block->submissionId,
                                'date' => $block->date,
                                'products' => $block->products,
                                'age' => $block->age,
                                'gender' => $block->gender,
                                'pdfGenerated' => $pdfGen
                            )
                        );
                    }

                    $i++;
                }

                // Update Super Table with new content
                if ( $submissionType == 'qrscan' ) {
                    $user->setFieldValues(['qrScanSubmissions' => $superTableData]);
                }
                if ( $submissionType == 'pathway' ) {
                    $user->setFieldValues(['pathwaySubmissions' => $superTableData]);
                }
                if ( $submissionType == 'clinicalindication' ) {
                    $user->setFieldValues(['clinicalIndicationSubmission' => $superTableData]);
                }
                if ( $submissionType == 'products' ) {
                    $user->setFieldValues(['productSubmission' => $superTableData]);
                }

                // Save the user
                Craft::$app->getElements()->saveElement($user);
                // Check for successful save
                $success = Craft::$app->getElements()->saveElement($user);
            }

            $response = [
                'Status ' => 'Success',
                'filename' => $filename
            ];

            return $this->asJson($response);
        }
    }


    public function actionEmailPdf()
    {

        $this->requirePostRequest();

        if( !empty($_POST['submissionId']) ) {

            $submissionId = $_POST['submissionId'];
            $userId = $_POST['userId'];
            $user = Craft::$app->users->getUserById($userId);
            $filename = 'NS-PIR-' . $userId . '-' . $submissionId . '.pdf';
            $filePath = './pir-documents/' . $filename;


            $settings = Craft::$app->systemSettings->getSettings('email');
            $message = new Message();

            // Send to
            $mail = $user->email;

            // Subject
            $subject = "NeuroScience PIR #" . $submissionId;

            // Body of the email
            $html    = 'Dear Healthcare Provider, <br /><br />';
            $html   .= 'The requested NeuroScience Product Information Request (PIR) is attached.<br /><br />';
            $html   .= 'If you have any questions, please contact Customer Service at <a href="tel+18883427272">888-342-7272.</a>. Please do not reply to this email.<br /><br />';
            $html   .= 'As always, we appreciate your business.<br /><br />';
            $html   .= 'Sincerely,<br /><br /><br />';

            $html   .= 'NeuroScience<br />';
            $html   .= '<a href="https://goo.gl/maps/3JaY1gXbiE92">373 280th Street</a><br />';
            $html   .= '<a href="https://goo.gl/maps/3JaY1gXbiE92">Osceola, WI 54020</a><br />';
            $html   .= 'P: <a href="tel:+18883427272">888-342-7272</a><br />';
            $html   .= 'F: <a href="tel:+17152943921">715-294-3921</a>';

            $message->setFrom([$settings['fromEmail'] => $settings['fromName']]);
            $message->setTo($mail);
            $message->setSubject($subject);
            $message->setHtmlBody($html);
            $message->attach($filePath, []);

            return Craft::$app->mailer->send($message);

        }

    }
    
    
    public function actionGenerateLab()
    {
	    
	    //$this->requirePostRequest();
      $userId = $_POST['userId'];
      //EYB4-X91X
      $activationCode = $_POST['activationCode'];

      if( !empty($_POST['userId']) ) {

          $curl = curl_init();

          curl_setopt_array($curl, array(
              CURLOPT_URL => "https://core.neurorelief.com/api/v1/labkits/getlabkitresultbyactivitationcode",
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_POST => true,
              CURLOPT_POSTFIELDS => json_encode(array("activationCode" => $activationCode)),
              CURLOPT_HTTPHEADER => array('Content-Type:application/json', 'x-api-key:ce63e72e16db4a70ac0c2aa4e5e5b68d7ce8275ccc104888ae4cc13ce0d2774c')
          ));

          $response = curl_exec($curl);
          $responseObject = json_decode($response);
          $products = $responseObject->recommendations;
          $reportUrl = $responseObject->reportUrl;
          $labKitId = $responseObject->labKitId;
          $submissionProducts = array();
          foreach($products as $product) {
            $productName = $product->name;
            $prods = \craft\commerce\elements\Product::find()->all();
            foreach($prods as $prod) {
              if( $prod->title == $productName ) {
                array_push($submissionProducts, $prod->id);
              }
            }
          }

          //$submissionProductss = implode(',', $submissionProducts);
          //$submissionProductIds = implode(',', $submissionProductIds);

          if ($response) {
            // Get User
            $user = Craft::$app->users->getUserById($userId);

            // Access Super Table Field
            $superTableData = array();
            $currentSubmissions = '';

            $field = Craft::$app->getFields()->getFieldByHandle('neuroCoreSubmissions');
            $currentSubmissions = $user->neuroCoreSubmissions;

            $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($field->id);
            $blockType = $blockTypes[0];

            $i = 1;
            // Loop through all current submissions
            foreach ($currentSubmissions as $block){

              $superTableData[$i] = array(
                  'type' => $blockType->id,
                  'enabled' => true,
                  'fields' => array(
                    'activationCode' => $block->activationCode,
                    'date' => $block->date,
                    'products' => $submissionProducts,
                    'reportUrl' => $reportUrl,
                    'labKitId' => $labKitId
                  )
              );
              $i++;
            }


            $user->setFieldValues(['neuroCoreSubmissions' => $superTableData]);

            // Save the user
            Craft::$app->getElements()->saveElement($user);
            // Check for successful save
            $success = Craft::$app->getElements()->saveElement($user);

            if($success) {
                $save = 'success';
            } else {
                $save = 'fail';
            }
          }

          $myResponse = [
            'Saved ' => $save
          ];

          return $this->asJson($myResponse);
        }
    }

}
