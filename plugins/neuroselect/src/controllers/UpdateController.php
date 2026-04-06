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

/**
 * Update Controller
 *
 *
 * @author    Andrew Ross
 * @package   Neuroselect
 * @since     1.0.0
 */
class UpdateController extends Controller
{

    protected $allowAnonymous = true;


    public function actionUpdatePir()
    {

        if( isset($_POST['submissionType']) ) {

            $submissionType = $_POST['submissionType'];
            $userId = $_POST['userId'];
            $user = Craft::$app->users->getUserById($userId);
            $sex = $_POST['Gender'];
            $age = $_POST['Age'];
            $submissionId = $_POST['submissionId'];

            if( isset($_POST['Category']) ) {
                $category = $_POST['Category'];
            }
            if( isset($_POST['clinicalindicators']) ) {
                $clinicalindicators = $_POST['clinicalindicators'];
                $clinicalindicators = implode (", ", $clinicalindicators);
            }
            if( isset($_POST['pathways']) ) {
                $pathways = $_POST['pathways'];
                $pathways = implode (", ", $pathways);
            }
            if( isset($_POST['products']) ) {
                $products = $_POST['products'];
                $products = implode (", ", $products);
            }
            $newSub = 'no';

            // Access Super Table Field
            $superTableData = array();
            $currentSubmissions = '';

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


            if( $submissionId == '' || empty($submissionId) ) {
                $newSub = 'yes';

                if ( $submissionType == 'pathway' ) {
                    $randId = str_pad(mt_rand(1,99999999),8,'0',STR_PAD_LEFT);
                    $submissionId = $randId;
                    $superTableData['new1'] = array(
                        'type' => $blockType->id,
                        'enabled' => true,
                        'fields' => array(
                            'submissionId' => $randId,
                            'date' => date('Y-m-d'),
                            'pathways' => $pathways,
                            'category' => $category,
                            'age' => $age,
                            'gender' => $sex,
                            'pdfGenerated' => 0
                        )
                    );
                }
                if ( $submissionType == 'clinicalindication' ) {
                    $randId = str_pad(mt_rand(1,99999999),8,'0',STR_PAD_LEFT);
                    $submissionId = $randId;
                    $superTableData['new1'] = array(
                        'type' => $blockType->id,
                        'enabled' => true,
                        'fields' => array(
                            'submissionId' => $randId,
                            'date' => date('Y-m-d'),
                            'clinicalindicators' => $clinicalindicators,
                            'category' => $category,
                            'age' => $age,
                            'gender' => $sex,
                            'pdfGenerated' => 0
                        )
                    );
                }
                if ( $submissionType == 'products' ) {
                    $randId = str_pad(mt_rand(1,99999999),8,'0',STR_PAD_LEFT);
                    $submissionId = $randId;
                    $superTableData['new1'] = array(
                        'type' => $blockType->id,
                        'enabled' => true,
                        'fields' => array(
                            'submissionId' => $randId,
                            'date' => date('Y-m-d'),
                            'products' => $products,
                            'age' => $age,
                            'gender' => $sex,
                            'pdfGenerated' => 0
                        )
                    );
                }
            }


            $i = 2;
            foreach ($currentSubmissions as $block){
                if ( $submissionType == 'pathway' ) {
                    if ($block->submissionId == $submissionId) {
                        $pdfGen = 0;
                        $blockAge = $age;
                        $blockSex = $sex;
                        $blockCategory = $category;
                        $blockPathways = $pathways;
                    } else {
                        $blockAge = $block->age;
                        $blockSex = $block->gender;
                        $blockCategory = $block->category;
                        $pdfGen = $block->pdfGenerated;
                        $blockPathways = $block->pathways;
                    }

                    $superTableData[$i] = array(
                        'type' => $blockType->id,
                        'enabled' => true,
                        'fields' => array(
                            'submissionId' => $block->submissionId,
                            'date' => $block->date,
                            'pathways' => $blockPathways,
                            'category' => $blockCategory,
                            'age' => $blockAge,
                            'gender' => $blockSex,
                            'pdfGenerated' => $pdfGen
                        )
                    );
                }

                if ( $submissionType == 'clinicalindication' ) {
                    if ($block->submissionId == $submissionId) {
                        $pdfGen = 0;
                        $blockAge = $age;
                        $blockSex = $sex;
                        $blockCategory = $category;
                        $blockClinicalindicators = $clinicalindicators;
                    } else {
                        $blockAge = $block->age;
                        $blockSex = $block->gender;
                        $blockCategory = $block->category;
                        $pdfGen = $block->pdfGenerated;
                        $blockClinicalindicators = $block->clinicalindicators;
                    }

                    $superTableData[$i] = array(
                        'type' => $blockType->id,
                        'enabled' => true,
                        'fields' => array(
                            'submissionId' => $block->submissionId,
                            'date' => $block->date,
                            'clinicalindicators' => $blockClinicalindicators,
                            'category' => $blockCategory,
                            'age' => $blockAge,
                            'gender' => $blockSex,
                            'pdfGenerated' => $pdfGen
                        )
                    );
                }
                if ( $submissionType == 'products' ) {
                    if ($block->submissionId == $submissionId) {
                        $pdfGen = 0;
                        $blockAge = $age;
                        $blockSex = $sex;
                        $blockProducts = $products;
                    } else {
                        $blockAge = $block->age;
                        $blockSex = $block->gender;
                        $blockProducts = $block->products;
                        $pdfGen = $block->pdfGenerated;
                    }

                    $superTableData[$i] = array(
                        'type' => $blockType->id,
                        'enabled' => true,
                        'fields' => array(
                            'submissionId' => $block->submissionId,
                            'date' => $block->date,
                            'products' => $blockProducts,
                            'age' => $blockAge,
                            'gender' => $blockSex,
                            'pdfGenerated' => $pdfGen
                        )
                    );
                }
                $i++;
            }

            // Update Super Table with new content
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

            if($success) {
                $save = 'success';
            } else {
                $save = 'fail';
            }

            $response = [
                'saved' => $save,
                'New Submission' => $newSub,
                'submissionId' => $submissionId
            ];

        } else {
            $response = [
                'Status ' => 'No submission ID received'
            ];
        }



        // if( isset($_POST['submissionType']) ) {
        //     $response = [
        //         'saved' => 'success',
        //         'New Submission' => 'no',
        //         'submissionId' => '69999'
        //     ];
        //     return $this->asJson([
        //         $response
        //     ]);
        // }

        return $this->asJson($response);
    }

}
