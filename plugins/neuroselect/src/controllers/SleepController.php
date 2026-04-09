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
 * Sleep Controller
 *
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Andrew Ross
 * @package   Neuroselect
 * @since     1.0.0
 */
class SleepController extends Controller
{


    protected bool|int|array $allowAnonymous = true;

    public function actionSleepPir()
    {

        if(isset($_POST['userId'])) {

            $userId = $_POST['userId'];
            $user = Craft::$app->users->getUserById($userId);
            $sex = $_POST['Gender'];
            $age = $_POST['Age'];
            $submissionId = $_POST['submissionId'];


            if( isset($_POST['secondaryConcerns']) ) {
                $secondaryConcerns = $_POST['secondaryConcerns'];
                $secondaryConcerns = implode (", ", $secondaryConcerns);
            } else {
                $secondaryConcerns = '';
            }

            if( isset($_POST['relevantPathways']) ) {
                $relevantPathways = $_POST['relevantPathways'];
                $relevantPathways = implode (", ", $relevantPathways);
            } else {
                $relevantPathways = '';
            }

            if( isset($_POST['excludedIngredients']) ) {
                $excludedIngredients = $_POST['excludedIngredients'];
                $excludedIngredients = implode (", ", $excludedIngredients);
            } else {
                $excludedIngredients = '';
            }

            if( isset($_POST['preferredPhase']) ) {
                $preferredPhase = $_POST['preferredPhase'];
            }


            $newSub = 'no';

            // Access Super Table Field
            $superTableData = array();
            $currentSubmissions = '';

            $field = Craft::$app->getFields()->getFieldByHandle('sleepSubmission');
            $currentSubmissions = $user->sleepSubmission;
            $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($field->id);
            $blockType = $blockTypes[0];


            if( $submissionId == '' ) {
                $newSub = 'yes';
                $randId = str_pad(mt_rand(1,99999999),8,'0',STR_PAD_LEFT);
                $submissionId = $randId;
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
                        'gender' => $sex,
                        'pdfGenerated' => 0
                    )
                );
            }


            $i = 2;
            foreach ($currentSubmissions as $block){

                if ($block->submissionId == $submissionId) {

                    $blockSecondaryConcerns = $secondaryConcerns;
                    $blockRelevantPathways = $relevantPathways;
                    $blockExcludedIngredients = $excludedIngredients;
                    $blockPreferredPhase = $preferredPhase;
                    $blockAge = $age;
                    $blockSex = $sex;
                    $pdfGen = 0;

                } else {

                    $blockSecondaryConcerns = $block->concerns;
                    $blockRelevantPathways = $block->relevantPathways;
                    $blockExcludedIngredients = $block->excludedIngredients;
                    $blockPreferredPhase = $block->preferredPhase;
                    $blockAge = $block->age;
                    $blockSex = $block->gender;
                    $pdfGen = 0;
                }

                $superTableData[$i] = array(
                    'type' => $blockType->id,
                    'enabled' => true,
                    'fields' => array(
                        'submissionId' => $block->submissionId,
                        'date' => $block->date,
                        'concerns' => $blockSecondaryConcerns,
                        'relevantPathways' => $blockRelevantPathways,
                        'excludedIngredients' => $blockExcludedIngredients,
                        'preferredPhase' => $blockPreferredPhase,
                        'age' => $blockAge,
                        'gender' => $blockSex,
                        'pdfGenerated' => $pdfGen
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
                $save = 'success';
            } else {
                $save = 'fail';
            }

            $response = [
                'saved' => $save,
                'New Submission' => $newSub,
                'submissionId' => $submissionId
            ];


            // $response = [
            //     'status' => $preferredPhase
            // ];

        } else {
            $response = [
                'status' => 'No submission ID received -- fail'
            ];
        }

        return $this->asJson($response);
    }

}
