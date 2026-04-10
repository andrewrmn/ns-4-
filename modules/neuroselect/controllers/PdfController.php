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

use modules\neuroselect\ChromiumPdfRenderer;
use modules\neuroselect\HtmlToPdfRenderer;
use modules\neuroselect\PdfGenerationEngine;
use modules\neuroselect\WkhtmlPdfRenderer;

use Craft;
use craft\web\Controller;
use craft\helpers\FileHelper;
use craft\mail\Message;
use verbb\supertable\SuperTable;
use yii\web\Response;

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

    protected bool|int|array $allowAnonymous = true;

    public function actionGeneratePdf()
    {
        if (empty($_POST['source'])) {
            return $this->pdfErrorResponse('Missing page URL for PDF conversion.', 400);
        }

        $source = (string)$_POST['source'];
        $submissionId = $_POST['submissionId'];
        $userId = $_POST['userId'];
        $submissionType = $_POST['submissionType'];

        // Chromium loads the URL like a real browser (best WYSIWYG). Dompdf/wkhtmltopdf also fetch server-side.
        // Set PIR_PDF_FETCH_BASE_URL if the browser URL is not reachable from this server (no trailing slash).
        $fetchBase = getenv('PIR_PDF_FETCH_BASE_URL');
        if (is_string($fetchBase) && $fetchBase !== '') {
            $parts = parse_url($source);
            if ($parts !== false && !empty($parts['path'])) {
                $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
                $source = rtrim($fetchBase, '/') . $parts['path'] . $query;
            }
        }

        $footer = [
            'source' => '<div style="border-top: 1px solid #F4F5F7; padding: 8px 20px 0; width: 90%; margin: 0 auto; font-family: sans-serif; "><div style="font-size: 4pt; font-family: sans-serif;">***Do not exceed suggested use</div><div style="font-size: 4pt; font-weight: bold; font-family: sans-serif; padding: 2px; border: 1px solid #000; margin-bottom: 5px; ">*These statements have not been evaluated by the Food and Drug Administration. This product is not intended to diagnose, treat, cure or prevent any disease.</div><div style="font-size: 4pt; font-family: sans-serif;">Product information was requested by a healthcare provider and is not intended to diagnose, treat, cure or prevent any diseases. References provided are not specific to an individual and do not change based on the product information request. Products selected are based on specific requests or information presented indicating the goal is to select ingredients with mechanisms that scientifically promote biochemical pathway(s) or clinical indication(s) to theoretically shift toward the statistical median or have research indicating a symptom could be correlated to an element in a pathway.</div></div>',
            'height' => '80px',
        ];

        $engine = PdfGenerationEngine::engineId();
        $pdfEngineDetail = null;
        $pirSheet = $this->resolvePirPdfStylesheet();

        if ($engine === PdfGenerationEngine::CHROMIUM) {
            $pdfBody = ChromiumPdfRenderer::renderUrlToPdf($source, $pdfEngineDetail);
        } elseif ($engine === PdfGenerationEngine::WKHTML) {
            $pdfBody = WkhtmlPdfRenderer::render(
                $source,
                $footer['source'],
                $pirSheet['wkhtmlArg'],
                $pdfEngineDetail,
                null
            );
        } else {
            $pdfBody = HtmlToPdfRenderer::fromUrl(
                $source,
                $pirSheet['dompdfUrl'],
                null,
                $footer['source'],
                $pirSheet['dompdfInline'],
                $pirSheet['dompdfAppend'] ?? null,
                $pdfEngineDetail
            );
        }

        if ($pdfBody === false || $pdfBody === '') {
            $suffix = $pdfEngineDetail ? ' ' . $pdfEngineDetail : ' See storage logs.';
            $msg = match ($engine) {
                PdfGenerationEngine::CHROMIUM => 'PDF generation failed (Chromium).' . $suffix
                    . ' Install Chrome/Chromium or set CHROMIUM_BIN; use PIR_PDF_ENGINE=dompdf as a fallback.',
                PdfGenerationEngine::WKHTML => 'PDF generation failed (wkhtmltopdf). Install the binary (e.g. brew install wkhtmltopdf) and set WKHTMLTOPDF_BIN if it is not on PATH.' . $suffix,
                PdfGenerationEngine::DOMPDF => 'PDF generation failed (Dompdf).' . $suffix,
                default => 'PDF generation failed.' . $suffix,
            };

            return $this->pdfErrorResponse(trim($msg), 422);
        }

        if (strncmp($pdfBody, '%PDF', 4) !== 0) {
            Craft::warning('PDF engine returned non-PDF (first 400 chars): ' . substr($pdfBody, 0, 400), __METHOD__);

            return $this->pdfErrorResponse(
                'PDF conversion did not return a valid file. Ensure the report URL returns HTML Craft can fetch (set PIR_PDF_FETCH_BASE_URL if needed).',
                422
            );
        }

        $filename = 'NS-PIR-' . $userId . '-' . $submissionId . '.pdf';
        $dir = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'pir-documents';
        FileHelper::createDirectory($dir);
        $saveTo = $dir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($saveTo, $pdfBody);

        $user = Craft::$app->users->getUserById($userId);
        if (!$user) {
            return $this->pdfErrorResponse('User not found.', 404);
        }

        $superTableData = [];
        $currentSubmissions = null;
        $field = null;

        if ($submissionType === 'qrscan') {
            $field = Craft::$app->getFields()->getFieldByHandle('qrScanSubmissions');
            $currentSubmissions = $user->qrScanSubmissions;
        } elseif ($submissionType === 'pathway') {
            $field = Craft::$app->getFields()->getFieldByHandle('pathwaySubmissions');
            $currentSubmissions = $user->pathwaySubmissions;
        } elseif ($submissionType === 'clinicalindication') {
            $field = Craft::$app->getFields()->getFieldByHandle('clinicalIndicationSubmission');
            $currentSubmissions = $user->clinicalIndicationSubmission;
        } elseif ($submissionType === 'products') {
            $field = Craft::$app->getFields()->getFieldByHandle('productSubmission');
            $currentSubmissions = $user->productSubmission;
        }

        if ($field && $currentSubmissions !== null) {
            $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($field->id);
            $blockType = $blockTypes[0];

            $i = 1;
            foreach ($currentSubmissions as $block) {
                $pdfGen = $block->submissionId != $submissionId ? $block->pdfGenerated : 1;

                if ($submissionType === 'qrscan') {
                    $superTableData[$i] = [
                        'type' => $blockType->id,
                        'enabled' => true,
                        'fields' => [
                            'submissionId' => $block->submissionId,
                            'date' => $block->date,
                            'data' => $block->data,
                            'category' => $block->category,
                            'pdfGenerated' => $pdfGen,
                        ],
                    ];
                }
                if ($submissionType === 'pathway') {
                    $superTableData[$i] = [
                        'type' => $blockType->id,
                        'enabled' => true,
                        'fields' => [
                            'submissionId' => $block->submissionId,
                            'date' => $block->date,
                            'pathways' => $block->pathways,
                            'category' => $block->category,
                            'age' => $block->age,
                            'gender' => $block->gender,
                            'pdfGenerated' => $pdfGen,
                        ],
                    ];
                }
                if ($submissionType === 'clinicalindication') {
                    $superTableData[$i] = [
                        'type' => $blockType->id,
                        'enabled' => true,
                        'fields' => [
                            'submissionId' => $block->submissionId,
                            'date' => $block->date,
                            'clinicalindicators' => $block->clinicalindicators,
                            'category' => $block->category,
                            'age' => $block->age,
                            'gender' => $block->gender,
                            'pdfGenerated' => $pdfGen,
                        ],
                    ];
                }
                if ($submissionType === 'products') {
                    $superTableData[$i] = [
                        'type' => $blockType->id,
                        'enabled' => true,
                        'fields' => [
                            'submissionId' => $block->submissionId,
                            'date' => $block->date,
                            'products' => $block->products,
                            'age' => $block->age,
                            'gender' => $block->gender,
                            'pdfGenerated' => $pdfGen,
                        ],
                    ];
                }

                ++$i;
            }

            if ($submissionType === 'qrscan') {
                $user->setFieldValues(['qrScanSubmissions' => $superTableData]);
            }
            if ($submissionType === 'pathway') {
                $user->setFieldValues(['pathwaySubmissions' => $superTableData]);
            }
            if ($submissionType === 'clinicalindication') {
                $user->setFieldValues(['clinicalIndicationSubmission' => $superTableData]);
            }
            if ($submissionType === 'products') {
                $user->setFieldValues(['productSubmission' => $superTableData]);
            }

            Craft::$app->getElements()->saveElement($user);
        }

        $payload = [
            'success' => true,
            'Status ' => 'Success',
            'filename' => $filename,
        ];

        // Second POST (email-pdf) reuses the same form; refresh token when CSRF is on.
        $general = Craft::$app->getConfig()->getGeneral();
        if ($general->enableCsrfProtection) {
            $req = Craft::$app->getRequest();
            $payload['csrfParam'] = $req->csrfParam;
            $payload['csrfTokenValue'] = $req->getCsrfToken(true);
        }

        return $this->asJson($payload);
    }

    /**
     * PIR print CSS: optional PIR_PDF_STYLESHEET_URL, else web/css/pdf9.css under @webroot, else production URL.
     * Dompdf: HtmlToPdfRenderer fetches HTTP(S) URLs with Guzzle and inlines CSS (Dompdf often skips remote &lt;link&gt; on hosts).
     *
     * @return array{dompdfUrl: ?string, dompdfInline: ?string, dompdfAppend: ?string, wkhtmlArg: string}
     */
    private function resolvePirPdfStylesheet(): array
    {
        $appendPath = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'pdf9-dompdf.css';
        $dompdfAppend = null;
        if (is_file($appendPath)) {
            $appendCss = file_get_contents($appendPath);
            if (is_string($appendCss) && $appendCss !== '') {
                $dompdfAppend = $appendCss;
            }
        }

        $env = getenv('PIR_PDF_STYLESHEET_URL');
        if (is_string($env) && $env !== '') {
            return [
                'dompdfUrl' => $env,
                'dompdfInline' => null,
                'dompdfAppend' => $dompdfAppend,
                'wkhtmlArg' => $env,
            ];
        }

        $path = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'pdf9.css';
        if (is_file($path)) {
            $css = file_get_contents($path);
            if ($css !== false && $css !== '') {
                return [
                    'dompdfUrl' => null,
                    'dompdfInline' => $css,
                    'dompdfAppend' => $dompdfAppend,
                    'wkhtmlArg' => $path,
                ];
            }
        }

        $fallback = 'https://www.neuroscienceinc.com/css/pdf9.css';

        return [
            'dompdfUrl' => $fallback,
            'dompdfInline' => null,
            'dompdfAppend' => $dompdfAppend,
            'wkhtmlArg' => $fallback,
        ];
    }

    private function pdfErrorResponse(string $message, int $statusCode): Response
    {
        $response = $this->asJson([
            'success' => false,
            'message' => $message,
        ]);
        $response->setStatusCode($statusCode);

        return $response;
    }

    public function actionEmailPdf()
    {

        $this->requirePostRequest();

        if (empty($_POST['submissionId'])) {
            return $this->emailPdfErrorResponse('Invalid request.', 400);
        }

        $submissionId = $_POST['submissionId'];
        $userId = isset($_POST['userId']) ? (int)$_POST['userId'] : 0;
        $user = $userId > 0 ? Craft::$app->users->getUserById($userId) : null;

        if (!$user) {
            return $this->emailPdfErrorResponse('User not found.', 404);
        }

        $email = $user->email;
        if ($email === null || $email === '') {
            return $this->emailPdfErrorResponse('This account has no email address on file.', 422);
        }

        $filename = 'NS-PIR-' . $userId . '-' . $submissionId . '.pdf';
        $filePath = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'pir-documents' . DIRECTORY_SEPARATOR . $filename;

        if (!is_file($filePath)) {
            return $this->emailPdfErrorResponse('PDF file not found. Generate the report first.', 422);
        }

        $settings = Craft::$app->systemSettings->getSettings('email');
        $fromEmail = $settings['fromEmail'] ?? null;
        if ($fromEmail === null || $fromEmail === '') {
            Craft::error('Email settings are missing a From address.', __METHOD__);

            return $this->emailPdfErrorResponse('Email is not configured on this site.', 500);
        }

        $message = new Message();

        $subject = 'NeuroScience PIR #' . $submissionId;

        $html = 'Dear Healthcare Provider, <br /><br />';
        $html .= 'The requested NeuroScience Product Information Request (PIR) is attached.<br /><br />';
        $html .= 'If you have any questions, please contact Customer Service at <a href="tel:+18883427272">888-342-7272</a>. Please do not reply to this email.<br /><br />';
        $html .= 'As always, we appreciate your business.<br /><br />';
        $html .= 'Sincerely,<br /><br /><br />';

        $html .= 'NeuroScience<br />';
        $html .= '<a href="https://goo.gl/maps/3JaY1gXbiE92">373 280th Street</a><br />';
        $html .= '<a href="https://goo.gl/maps/3JaY1gXbiE92">Osceola, WI 54020</a><br />';
        $html .= 'P: <a href="tel:+18883427272">888-342-7272</a><br />';
        $html .= 'F: <a href="tel:+17152943921">715-294-3921</a>';

        $message->setFrom([$fromEmail => $settings['fromName'] ?? '']);
        $message->setTo($email);
        $message->setSubject($subject);
        $message->setHtmlBody($html);
        $message->attach($filePath, []);

        try {
            $sent = Craft::$app->getMailer()->send($message);
        } catch (\Throwable $e) {
            Craft::error('PIR email send exception: ' . $e->getMessage(), __METHOD__);

            return $this->emailPdfErrorResponse('Mailer error. Check storage logs.', 500);
        }

        if (!$sent) {
            Craft::warning('PIR email mailer->send() returned false for ' . $email, __METHOD__);

            return $this->emailPdfErrorResponse('Mailer could not send. Check email transport settings.', 422);
        }

        return $this->asJson(['success' => true]);
    }

    private function emailPdfErrorResponse(string $message, int $statusCode): Response
    {
        $response = $this->asJson([
            'success' => false,
            'message' => $message,
        ]);
        $response->setStatusCode($statusCode);

        return $response;
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
