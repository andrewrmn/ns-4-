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


use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\web\Controller;
use modules\neuroselect\ChromiumPdfRenderer;
use modules\neuroselect\HtmlToPdfRenderer;
use modules\neuroselect\PdfGenerationEngine;
use modules\neuroselect\PdfShiftRenderer;
use modules\neuroselect\WkhtmlPdfRenderer;
use craft\elements\GlobalSet;
use verbb\supertable\SuperTable;
use craft\helpers\DateTimeHelper;
use craft\mail\Message;

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
class SurveyController extends Controller
{
  protected bool|int|array $allowAnonymous = true;

  public function actionSurveySubmission()
  {

    $age = $_POST['age'];
    $name = $_POST['name'];
    $sex = $_POST['sex'];
    $state = $_POST['state'];
    $subject = $_POST['subject'];

    $date = DateTimeHelper::currentTimeStamp();
    $submissionId = $date;
    if( isset($_POST['submissionId']) ) {
      $submissionId = $_POST['submissionId'];
    }

    $product1 = '';
    if( isset($_POST['product1']) ) {
      $product1 = $_POST['product1'];
    }
    $product2 = '';
    if( isset($_POST['product2']) ) {
      $product2 = $_POST['product2'];
    }
    $product3 = '';
    if( isset($_POST['product3']) ) {
      $product3 = $_POST['product3'];
    }
    $product4 = '';
    if( isset($_POST['product4']) ) {
      $product4 = $_POST['product4'];
    }
    $product5 = '';
    if( isset($_POST['product5']) ) {
      $product5 = $_POST['product5'];
    }

    $email = '';
    if( isset($_POST['email']) ) {
      $email = $_POST['email'];
    }


    $sleepScore = '';
    if( isset($_POST['sleepScore']) ) {
      $sleepScore = $_POST['sleepScore'];
    }
    $stressScore = '';
    if( isset($_POST['stressScore']) ) {
      $stressScore = $_POST['stressScore'];
    }
    $wellnessScore = '';
    if( isset($_POST['wellnessScore']) ) {
      $wellnessScore = $_POST['wellnessScore'];
    }


    $fruitsVegies = '';
    if( isset($_POST['fruitsVegies']) ) {
      $fruitsVegies = $_POST['fruitsVegies'];
    }
    $fattyAcids = '';
    if( isset($_POST['fattyAcids']) ) {
      $fattyAcids = $_POST['fattyAcids'];
    }
    $fiber = '';
    if( isset($_POST['fiber']) ) {
      $fiber = $_POST['fiber'];
    }
    $protein = '';
    if( isset($_POST['protein']) ) {
      $protein = $_POST['protein'];
    }
    $calcium = '';
    if( isset($_POST['calcium']) ) {
      $calcium = $_POST['calcium'];
    }
    $exercise = '';
    if( isset($_POST['exercise']) ) {
      $exercise = $_POST['exercise'];
    }

    $finalQuality = '';
    if( isset($_POST['finalQuality']) ) {
      $finalQuality = $_POST['finalQuality'];
    }
    $fallingAsleep = '';
    if( isset($_POST['fallingAsleep']) ) {
      $fallingAsleep = $_POST['fallingAsleep'];
    }
    $wakeUp = '';
    if( isset($_POST['wakeUp']) ) {
      $wakeUp = $_POST['wakeUp'];
    }
    $vasomotorIssues = '';
    if( isset($_POST['vasomotorIssues']) ) {
      $vasomotorIssues = $_POST['vasomotorIssues'];
    }
    $finalPain = '';
    if( isset($_POST['finalPain']) ) {
      $finalPain = $_POST['finalPain'];
    }
    $daytimeFatigue = '';
    if( isset($_POST['daytimeFatigue']) ) {
      $daytimeFatigue = $_POST['daytimeFatigue'];
    }
    $daytimeMotivation = '';
    if( isset($_POST['daytimeMotivation']) ) {
      $daytimeMotivation = $_POST['daytimeMotivation'];
    }


    $generalWellness = '';
    if( isset($_POST['generalWellness']) ) {
      $generalWellness = $_POST['generalWellness'];
    }

    $physicalWellness = '';
    if( isset($_POST['physicalWellness']) ) {
      $physicalWellness = $_POST['physicalWellness'];
    }
    $activityWellness = '';
    if( isset($_POST['activityWellness']) ) {
      $activityWellness = $_POST['activityWellness'];
    }
    $motivationWellness = '';
    if( isset($_POST['motivationWellness']) ) {
      $motivationWellness = $_POST['motivationWellness'];
    }
    $mentalWellness = '';
    if( isset($_POST['mentalWellness']) ) {
      $mentalWellness = $_POST['mentalWellness'];
    }

    $globalSetHandle = 'surveySubmissions';
    $globalSet = Craft::$app->globals->getSetById('436182');


    // Access Super Table Field
    $superTableData = array();
    $currentSubmissions = '';

    $userId = $_POST['userId'];
    $user = Craft::$app->users->getUserById($userId);
    $currentSubmissions = $user->surveySubmissions;
    $oldSubmissions = $globalSet->surveySubmissions;

    // get super table field
    $field = Craft::$app->getFields()->getFieldByHandle('surveySubmissions');
    $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($field->id);
    $blockType = $blockTypes[0];

    $superTableData['new1'] = array(
      'type' => $blockType->id,
      'enabled' => true,
      'fields' => array(
        'submissionId' => $submissionId,
        'email' => $email,
        'subName' => $name,
        'age' => $age,
        'sex' => $sex,
        'state' => $state,
        'subject' => $subject,
        'product1' => $product1,
        'product2' => $product2,
        'product3' => $product3,
        'product4' => $product4,
        'product5' => $product5,
        'sleepScore' => $sleepScore,
        'stressScore' => $stressScore,
        'wellnessScore' => $wellnessScore,
        'fruitsVegies' => $fruitsVegies,
        'fattyAcids' => $fattyAcids,
        'fiber' => $fiber,
        'protein' => $protein,
        'calcium' => $calcium,
        'exercise' => $exercise,
        'finalQuality' => $finalQuality,
        'fallingAsleep' => $fallingAsleep,
        'wakeUp' => $wakeUp,
        'vasomotorIssues' => $vasomotorIssues,
        'finalPain' => $finalPain,
        'daytimeFatigue' => $daytimeFatigue,
        'daytimeMotivation' => $daytimeMotivation,
        'generalWellness' => $generalWellness,
        'physicalWellness' => $physicalWellness,
        'activityWellness' => $activityWellness,
        'motivationWellness' => $motivationWellness,
        'mentalWellness' => $mentalWellness
      )
    );

    // Make sure old submissions are imap_saved
    $i = 2;
    foreach ($currentSubmissions as $block){
      $superTableData[$i] = array(
        'type' => $blockType->id,
        'enabled' => true,
        'fields' => array(
          'submissionId' => $block->submissionId,
          'email' => $block->email,
          'subName' => $block->subName,
          'age' => $block->age,
          'sex' => $block->sex,
          'state' => $block->state,
          'subject' => $block->subject,
          'product1' => $block->product1,
          'product2' => $block->product2,
          'product3' => $block->product3,
          'product4' => $block->product4,
          'product5' => $block->product5,
          'sleepScore' => $block->sleepScore,
          'stressScore' => $block->stressScore,
          'wellnessScore' => $block->wellnessScore,
          'fruitsVegies' => $block->fruitsVegies,
          'fattyAcids' => $block->fattyAcids,
          'fiber' => $block->fiber,
          'protein' => $block->protein,
          'calcium' => $block->calcium,
          'exercise' => $block->exercise,
          'finalQuality' => $block->finalQuality,
          'fallingAsleep' => $block->fallingAsleep,
          'wakeUp' => $block->wakeUp,
          'vasomotorIssues' => $block->vasomotorIssues,
          'finalPain' => $block->finalPain,
          'daytimeFatigue' => $block->daytimeFatigue,
          'daytimeMotivation' => $block->daytimeMotivation,
          'generalWellness' => $block->generalWellness,
          'physicalWellness' => $block->physicalWellness,
          'activityWellness' => $block->activityWellness,
          'motivationWellness' => $block->motivationWellness,
          'mentalWellness' => $block->mentalWellness
        )
      );
      $i++;
    }

    $user->setFieldValues(['surveySubmissions' => $superTableData]);
    Craft::$app->getElements()->saveElement($user);
    $success = Craft::$app->getElements()->saveElement($user);

    if($success) {
      $save = 'success';
    } else {
      $save = 'fail';
    }



    if( !empty($_POST['guestUser']) ) {

      $source = 'https://www.neuroscienceinc.com/neuro-q/report/'.$submissionId.'?account='.$email;
      //$source = 'http://local.neuroscience/neuro-q/report/'.$submissionId.'?account='.$email;
      $toEmail = $_POST['email'];

      $headerHtml = "<div style=\"border-bottom: 1px solid #F4F5F7; padding: 4px 3%; width: 100%; margin: 0 auto; font-family: sans-serif; \"><img style=\"width: 110px; float: left; height: auto; display: inline-block; vertical-align: middle;\" src=\"https://www.neuroscienceinc.com/images/NeuroScience-logo.svg\" /> <span style=\"font-size: 6pt; font-weight: bold; color: #0081B6; font-family: sans-serif; float: right; margin: 8px 0 0; display: inline-block; vertical-align: middle; \">(888) 342-7272</div></div>";

      $footerHtml = "<div style=\"border-top: 1px solid #F4F5F7; padding: 8px 20px 0; width: 90%; margin: 0 auto; font-family: sans-serif; \"><div style=\"font-size: 4pt; font-weight: bold; font-family: sans-serif; padding: 2px; border: 1px solid #000; margin-bottom: 5px; \">*These statements have not been evaluated by the Food and Drug Administration.</div><div style=\"font-size: 4pt; font-family: sans-serif;\">Recommendations and product information provided was requested by the user and is not intended to diagnose, treat, cure or prevent any disease.</div></div>";

      $pdfErr = null;
      $emailResponse = $this->renderSurveyPdfBody($source, $headerHtml, $footerHtml, $pdfErr);

      // Create the filename
      $filename = 'NS-SURVEY-' . $submissionId . '.pdf';
      $surveysDir = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'surveys';
      FileHelper::createDirectory($surveysDir);
      $saveTo = $surveysDir . DIRECTORY_SEPARATOR . $filename;

      if (is_string($emailResponse) && $emailResponse !== '' && strncmp($emailResponse, '%PDF', 4) === 0) {
        file_put_contents($saveTo, $emailResponse);
      } else {
        Craft::warning('Guest survey PDF generation failed: ' . ($pdfErr ?? 'invalid output'), __METHOD__);
      }

      $name = '';
      if( isset($_POST['name']) && !empty($_POST['name']) ) {
        $name = 'Your patient, ' . $_POST['name'] . ', has just submitted a NeuroQ survey.';
      }

      $filename = 'NS-SURVEY-' . $submissionId . '.pdf';
      $filePath = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'surveys' . DIRECTORY_SEPARATOR . $filename;

      $settings = Craft::$app->systemSettings->getSettings('email');
      $message = new Message();

      // Send to
      $mail = $toEmail;

      // Subject
      $subject = "A patient has submitted NeuroScience Survey #" . $submissionId;
      // Body of the email
      $html   = '<img style="margin: 24px auto 36px;" width="60" src="https://www.neuroscienceinc.com/images/general/NeuroScience-logomark.png" alt="NeuroScience logo" /><br />';
      $html   .= $name;
      $html   .= ' Please access your <a href="https://www.neuroscienceinc.com/account/neuro-q" target="_blank">NeuroQ dashboard</a> to view the submission.<br /><br />';
      $html   .= 'If you have any questions, please contact our Customer Support team at <a href="tel+18883427272">888-342-7272</a>.<br /><br /> Please do not reply to this email.<br /><br />';
      $html   .= 'As always, we appreciate your business.<br /><br />';
      $html   .= 'Thank you!<br />NeuroScience<br /><br />';

      $html   .= '<span style="width: 600px; display: block; height: 1px; background: #0081B6;"></span><br />';
      $html   .= '<img width="110" src="https://www.neuroscienceinc.com/images/general/Neuro-Q.png" alt="Neuro Q logo" /> <br />';

      $html   .= 'Clinically validated. Scientific symptom assessment. Targeted supplement selection.';

      $message->setFrom([$settings['fromEmail'] => $settings['fromName']]);
      $message->setTo($mail);
      $message->setSubject($subject);
      $message->setHtmlBody($html);
      if (is_file($filePath)) {
        $message->attach($filePath, []);
      }

      Craft::$app->mailer->send($message);

      $response = [
        'status' => $save,
        'submissionId' => $submissionId
      ];

      return $this->asJson($response);

    } else {
      $response = [
        'status' => $save,
        'submissionId' => $submissionId
      ];
      return $this->asJson($response);
    }
  }


  public function actionSurveyPdf()
  {

    if( !empty($_POST['source']) ) {

      $source = $_POST['source'];
      $submissionId = $_POST['submissionId'];

      $headerHtml = "<div style=\"border-bottom: 1px solid #F4F5F7; padding: 4px 3%; width: 100%; margin: 0 auto; font-family: sans-serif; \"><img style=\"width: 110px; float: left; height: auto; display: inline-block; vertical-align: middle;\" src=\"https://www.neuroscienceinc.com/images/NeuroScience-logo.svg\" /> <span style=\"font-size: 6pt; font-weight: bold; color: #0081B6; font-family: sans-serif; float: right; margin: 8px 0 0; display: inline-block; vertical-align: middle; \">(888) 342-7272</div></div>";

      $footerHtml = "<div style=\"border-top: 1px solid #F4F5F7; padding: 8px 20px 0; width: 90%; margin: 0 auto; font-family: sans-serif; \"><div style=\"font-size: 4pt; font-weight: bold; font-family: sans-serif; padding: 2px; border: 1px solid #000; margin-bottom: 5px; \">*These statements have not been evaluated by the Food and Drug Administration.</div><div style=\"font-size: 4pt; font-family: sans-serif;\">Recommendations and product information provided was requested by the user and is not intended to diagnose, treat, cure or prevent any disease.</div></div>";

      $pdfErr = null;
      $response = $this->renderSurveyPdfBody($source, $headerHtml, $footerHtml, $pdfErr);

      // Create the filename
      $filename = 'NS-SURVEY-' . $submissionId . '.pdf';
      $surveysDir = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'surveys';
      FileHelper::createDirectory($surveysDir);
      $saveTo = $surveysDir . DIRECTORY_SEPARATOR . $filename;

      if (is_string($response) && $response !== '' && strncmp($response, '%PDF', 4) === 0) {
        file_put_contents($saveTo, $response);
        $status = 'success';
      } else {
        Craft::warning('Survey PDF generation failed: ' . ($pdfErr ?? 'invalid output'), __METHOD__);
        $status = 'error';
      }

      $response = [
        'Status ' => $status,
        'filename' => $filename
      ];

      return $this->asJson($response);
    }
  }


  public function actionEmailSurvey()
  {
    $this->requirePostRequest();

    if( !empty($_POST['email']) ) {

      $submissionId = $_POST['submissionId'];
      $toEmail = $_POST['email'];

      $name = 'Hello, <br /><br />';
	    if( isset($_POST['name']) && !empty($_POST['name']) ) {
	      $name = 'Dear ' . $_POST['name'] . ', <br /><br />';
	    }

      $filename = 'NS-SURVEY-' . $submissionId . '.pdf';
      $filePath = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'surveys' . DIRECTORY_SEPARATOR . $filename;

      $mailSettings = App::mailSettings();
      $fromEmail = App::parseEnv($mailSettings->fromEmail);
      $fromName = App::parseEnv($mailSettings->fromName) ?: '';
      $message = new Message();

      // Send to
      $mail = $toEmail;

      // Subject
      $subject = "NeuroScience Survey #" . $submissionId;

      // Body of the email
      $html   = '<img style="margin: 24px auto 36px;" width="60" src="https://www.neuroscienceinc.com/images/general/NeuroScience-logomark.png" alt="NeuroScience logo" /><br />';
      $html   .= $name;
      $html   .= 'Thank you for using Neuro-Q! Product and lifestyle recommendations are attached<br />for the recent Neuro-Q submission. Please download the report to keep for your <br />records and print/email to your patient for reference after their appointment.<br /><br />';
      $html   .= 'If you have any questions, please contact our Customer Support team at <a href="tel+18883427272">888-342-7272</a>.<br /><br /> Please do not reply to this email.<br /><br />';
      $html   .= 'As always, we appreciate your business.<br /><br />';
      $html   .= 'Thank you!<br />NeuroScience<br /><br />';

	    $html   .= '<span style="width: 600px; display: block; height: 1px; background: #0081B6;"></span><br />';
      $html   .= '<img width="110" src="https://www.neuroscienceinc.com/images/general/Neuro-Q.png" alt="Neuro Q logo" /> <br />';

      $html   .= 'Clinically validated. Scientific symptom assessment. Targeted supplement selection.';

      $message->setFrom([$fromEmail => $fromName]);
      $message->setTo($mail);
      $message->setSubject($subject);
      $message->setHtmlBody($html);
      if (is_file($filePath)) {
        $message->attach($filePath, []);
      }

      Craft::$app->mailer->send($message);

      return $this->asJson([
        'Status ' => 'Email Sent',
      ]);
    }

    return $this->asJson([
      'Status ' => 'missing info',
    ]);
  }

    /**
     * Neuro Q survey PDF: same PIR_PDF_ENGINE as PIR (chromium ≈ browser, wkhtml header/footer, dompdf fallback).
     *
     * @param string|null $errorDetail
     * @return string|false raw PDF bytes
     */
    private function renderSurveyPdfBody(string $source, string $headerHtml, string $footerHtml, ?string &$errorDetail = null): string|false
    {
        $errorDetail = null;
        $engine = PdfGenerationEngine::engineId();
        $surveyCss = $this->resolveSurveyStylesheetPathOrUrl();

        if ($engine === PdfGenerationEngine::CHROMIUM) {
            return ChromiumPdfRenderer::renderUrlToPdf($source, $errorDetail);
        }

        if ($engine === PdfGenerationEngine::PDFSHIFT) {
            return PdfShiftRenderer::renderUrlToPdf($source, $footerHtml, $headerHtml, null, $errorDetail);
        }

        if ($engine === PdfGenerationEngine::WKHTML) {
            return WkhtmlPdfRenderer::render($source, $footerHtml, $surveyCss, $errorDetail, $headerHtml);
        }

        return HtmlToPdfRenderer::fromUrl($source, $surveyCss, $headerHtml, $footerHtml, null, null, $errorDetail);
    }

    private function resolveSurveyStylesheetPathOrUrl(): string
    {
        $env = App::env('NEUROQ_PDF_STYLESHEET_URL');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        $local = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'pdf-survey.css';
        if (is_file($local)) {
            return $local;
        }

        return 'https://www.neuroscienceinc.com/css/pdf-survey.css';
    }
}
