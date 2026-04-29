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
use modules\neuroselect\PdfDebugSessionLog;
use modules\neuroselect\PdfGenerationEngine;
use modules\neuroselect\PdfShiftRenderer;
use modules\neuroselect\WkhtmlPdfRenderer;

use Craft;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\UrlHelper;
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

    private function pirPdfFooterHtml(): string
    {
        return '<div style="border-top: 1px solid #F4F5F7; padding: 8px 20px 0; width: 90%; margin: 0 auto; font-family: sans-serif; "><div style="font-size: 4pt; font-family: sans-serif;">***Do not exceed suggested use</div><div style="font-size: 4pt; font-weight: bold; font-family: sans-serif; padding: 2px; border: 1px solid #000; margin-bottom: 5px; ">*These statements have not been evaluated by the Food and Drug Administration. This product is not intended to diagnose, treat, cure or prevent any disease.</div><div style="font-size: 4pt; font-family: sans-serif;">Product information was requested by a healthcare provider and is not intended to diagnose, treat, cure or prevent any diseases. References provided are not specific to an individual and do not change based on the product information request. Products selected are based on specific requests or information presented indicating the goal is to select ingredients with mechanisms that scientifically promote biochemical pathway(s) or clinical indication(s) to theoretically shift toward the statistical median or have research indicating a symptom could be correlated to an element in a pathway.</div></div>';
    }

    /**
     * Rewrite report URL for server-side/PDFShift fetch when PIR_PDF_FETCH_BASE_URL is set.
     */
    private function normalizePirPdfSource(string $source): string
    {
        $fetchBase = App::env('PIR_PDF_FETCH_BASE_URL');
        if (is_string($fetchBase) && $fetchBase !== '') {
            $parts = parse_url($source);
            if ($parts !== false && !empty($parts['path'])) {
                $query = isset($parts['query']) ? ('?' . $parts['query']) : '';

                return rtrim($fetchBase, '/') . $parts['path'] . $query;
            }
        }

        return $source;
    }

    /**
     * HTTP(S) URL for PDFShift `css` — matches legacy packaged plugin passing https://…/pdf9.css (see xx_legacy_plugins).
     */
    private function pirPdfShiftCssUrl(array $pirResolved): string
    {
        $override = App::env('PIR_PDFSHIFT_CSS_URL');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        $u = $pirResolved['dompdfUrl'] ?? null;
        if (is_string($u) && $u !== '') {
            return $u;
        }
        $p9 = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'pdf9.css';
        if (is_file($p9) && (int) @filesize($p9) > 0) {
            return UrlHelper::siteUrl('css/pdf9.css');
        }

        return 'https://www.neuroscienceinc.com/css/pdf9.css';
    }

    /**
     * @param string|null $pdfEngineDetail
     */
    private function renderPirPdfBody(string $normalizedSource, ?string &$pdfEngineDetail = null): string|false
    {
        $pdfEngineDetail = null;
        $footerInner = $this->pirPdfFooterHtml();
        $engine = PdfGenerationEngine::engineId();
        $pirSheet = $this->resolvePirPdfStylesheet();

        // #region agent log
        $pu = parse_url($normalizedSource);
        $sheetBranch = 'fallback_url';
        $pirSheetUrl = App::env('PIR_PDF_STYLESHEET_URL');
        if (is_string($pirSheetUrl) && $pirSheetUrl !== '') {
            $sheetBranch = 'env_url';
        } else {
            $p9 = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'pdf9.css';
            if (is_file($p9) && (int) @filesize($p9) > 0) {
                $sheetBranch = 'webroot_pdf9';
            }
        }
        $resolvedChromeBin = ChromiumPdfRenderer::binaryPath();
        $fetchBaseLog = App::env('PIR_PDF_FETCH_BASE_URL');
        PdfDebugSessionLog::write('H1,H4,H5', 'PdfController::renderPirPdfBody', 'pre_render', [
            'engine' => $engine,
            'fetch_base_set' => is_string($fetchBaseLog) && $fetchBaseLog !== '',
            'source_host' => $pu['host'] ?? '',
            'source_path' => $pu['path'] ?? '',
            'sheet_branch' => $sheetBranch,
            'dompdf_inline_len' => strlen($pirSheet['dompdfInline'] ?? ''),
            'dompdf_url_set' => ($pirSheet['dompdfUrl'] ?? '') !== '',
            'dompdf_append_len' => strlen($pirSheet['dompdfAppend'] ?? ''),
            'php_can_exec' => \function_exists('exec'),
            'php_can_proc_open' => \function_exists('proc_open'),
            'chromium_bin_resolved' => $resolvedChromeBin !== null,
            'chromium_bin_basename' => $resolvedChromeBin ? basename($resolvedChromeBin) : null,
            'pdfshift_configured' => PdfShiftRenderer::isConfigured(),
            'pdfshift_sandbox' => PdfShiftRenderer::useSandbox(),
            'pdfshift_use_print' => $engine === PdfGenerationEngine::PDFSHIFT ? PdfShiftRenderer::usePrint() : false,
            'pdfshift_css_preview' => $engine === PdfGenerationEngine::PDFSHIFT ? substr($this->pirPdfShiftCssUrl($pirSheet), 0, 96) : '',
        ]);
        // #endregion

        if ($engine === PdfGenerationEngine::CHROMIUM) {
            return ChromiumPdfRenderer::renderUrlToPdf($normalizedSource, $pdfEngineDetail);
        }
        if ($engine === PdfGenerationEngine::PDFSHIFT) {
            $shiftCssUrl = $this->pirPdfShiftCssUrl($pirSheet);

            return PdfShiftRenderer::renderUrlToPdf(
                $normalizedSource,
                $footerInner,
                null,
                $shiftCssUrl,
                $pdfEngineDetail
            );
        }
        if ($engine === PdfGenerationEngine::WKHTML) {
            return WkhtmlPdfRenderer::render(
                $normalizedSource,
                $footerInner,
                $pirSheet['wkhtmlArg'],
                $pdfEngineDetail,
                null
            );
        }

        return HtmlToPdfRenderer::fromUrl(
            $normalizedSource,
            $pirSheet['dompdfUrl'],
            null,
            $footerInner,
            $pirSheet['dompdfInline'],
            $pirSheet['dompdfAppend'] ?? null,
            $pdfEngineDetail
        );
    }

    private function pirPdfGenerationErrorResponse(string $engine, ?string $pdfEngineDetail): Response
    {
        $suffix = $pdfEngineDetail ? ' ' . $pdfEngineDetail : ' See storage logs.';
        $msg = match ($engine) {
            PdfGenerationEngine::CHROMIUM => 'PDF generation failed (Chromium).' . $suffix
                . ' Install Chrome/Chromium or set CHROMIUM_BIN; use PIR_PDF_ENGINE=dompdf as a fallback.',
            PdfGenerationEngine::PDFSHIFT => 'PDF generation failed (PDFShift).' . $suffix
                . ' Set PDFSHIFT_API_KEY (or PIR_PDFSHIFT_API_KEY) and ensure the report URL is publicly reachable.',
            PdfGenerationEngine::WKHTML => 'PDF generation failed (wkhtmltopdf). Install the binary (e.g. brew install wkhtmltopdf) and set WKHTMLTOPDF_BIN if it is not on PATH.' . $suffix,
            PdfGenerationEngine::DOMPDF => 'PDF generation failed (Dompdf).' . $suffix,
            default => 'PDF generation failed.' . $suffix,
        };

        return $this->pdfErrorResponse(trim($msg), 422);
    }

    private function writePirPdfToDisk(int|string $userId, mixed $submissionId, string $pdfBody): void
    {
        $filename = 'NS-PIR-' . $userId . '-' . $submissionId . '.pdf';
        $dir = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'pir-documents';
        FileHelper::createDirectory($dir);
        $saveTo = $dir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($saveTo, $pdfBody);
    }

    private function markPirPdfGeneratedOnUser(User $user, mixed $submissionId, string $submissionType): void
    {
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

        if (!$field || $currentSubmissions === null) {
            return;
        }

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

    public function actionGeneratePdf()
    {
        if (empty($_POST['source'])) {
            return $this->pdfErrorResponse('Missing page URL for PDF conversion.', 400);
        }

        $source = $this->normalizePirPdfSource((string)$_POST['source']);
        $submissionId = $_POST['submissionId'];
        $userId = $_POST['userId'];
        $submissionType = (string)$_POST['submissionType'];
        $engine = PdfGenerationEngine::engineId();

        $pdfEngineDetail = null;
        $pdfBody = $this->renderPirPdfBody($source, $pdfEngineDetail);

        if ($pdfBody === false || $pdfBody === '') {
            return $this->pirPdfGenerationErrorResponse($engine, $pdfEngineDetail);
        }

        if (strncmp($pdfBody, '%PDF', 4) !== 0) {
            Craft::warning('PDF engine returned non-PDF (first 400 chars): ' . substr($pdfBody, 0, 400), __METHOD__);

            return $this->pdfErrorResponse(
                'PDF conversion did not return a valid file. Ensure the report URL returns HTML Craft can fetch (set PIR_PDF_FETCH_BASE_URL if needed).',
                422
            );
        }

        $this->writePirPdfToDisk($userId, $submissionId, $pdfBody);

        $user = Craft::$app->users->getUserById((int)$userId);
        if (!$user) {
            return $this->pdfErrorResponse('User not found.', 404);
        }

        $this->markPirPdfGeneratedOnUser($user, $submissionId, $submissionType);

        $filename = 'NS-PIR-' . $userId . '-' . $submissionId . '.pdf';
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

        $env = App::env('PIR_PDF_STYLESHEET_URL');
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
            $regenSource = isset($_POST['source']) ? trim((string)$_POST['source']) : '';
            $regenType = isset($_POST['submissionType']) ? trim((string)$_POST['submissionType']) : '';
            if ($regenSource !== '' && $regenType !== '') {
                $normalized = $this->normalizePirPdfSource($regenSource);
                $pdfEngineDetail = null;
                $pdfBody = $this->renderPirPdfBody($normalized, $pdfEngineDetail);
                if ($pdfBody === false || $pdfBody === '') {
                    $suffix = $pdfEngineDetail ? ' ' . $pdfEngineDetail : '';

                    return $this->emailPdfErrorResponse('Could not generate PDF for email.' . $suffix, 422);
                }
                if (strncmp($pdfBody, '%PDF', 4) !== 0) {
                    Craft::warning('PIR email regenerate: non-PDF body (first 200 chars): ' . substr($pdfBody, 0, 200), __METHOD__);

                    return $this->emailPdfErrorResponse(
                        'PDF conversion did not return a valid file. Ensure the report URL is reachable (set PIR_PDF_FETCH_BASE_URL if needed).',
                        422
                    );
                }
                $this->writePirPdfToDisk($userId, $submissionId, $pdfBody);
                $this->markPirPdfGeneratedOnUser($user, $submissionId, $regenType);
                $filePath = Craft::getAlias('@webroot') . DIRECTORY_SEPARATOR . 'pir-documents' . DIRECTORY_SEPARATOR . $filename;
            }
        }

        if (!is_file($filePath)) {
            return $this->emailPdfErrorResponse('PDF file not found. Generate the report first.', 422);
        }

        $pdfBytes = file_get_contents($filePath);
        if ($pdfBytes === false || $pdfBytes === '') {
            Craft::error('PIR email: could not read PDF at ' . $filename, __METHOD__);

            return $this->emailPdfErrorResponse('Could not read the PDF file for attachment.', 500);
        }

        // Use parsed env (same as Craft core / AdminEmailsModule). systemSettings raw values can still
        // contain $SMTP_FROM-style placeholders and produce invalid From headers / SMTP rejection.
        $mailSettings = App::mailSettings();
        $fromEmail = App::parseEnv($mailSettings->fromEmail);
        $fromName = App::parseEnv($mailSettings->fromName) ?: '';
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

        $plain = "Dear Healthcare Provider,\n\n"
            . "The requested NeuroScience Product Information Request (PIR) is attached.\n\n"
            . "If you have any questions, please contact Customer Service at 888-342-7272. Please do not reply to this email.\n\n"
            . "NeuroScience\n373 280th Street, Osceola, WI 54020\nP: 888-342-7272  F: 715-294-3921";

        $message->setFrom([$fromEmail => $fromName]);
        $replyTo = App::parseEnv($mailSettings->replyToEmail ?? '');
        if ($replyTo !== null && $replyTo !== '') {
            $message->setReplyTo($replyTo);
        }
        $message->setTo($email);
        $message->setSubject($subject);
        $message->setTextBody($plain);
        $message->setHtmlBody($html);
        $message->attachContent($pdfBytes, [
            'fileName' => $filename,
            'contentType' => 'application/pdf',
        ]);

        try {
            $sent = Craft::$app->getMailer()->send($message);
        } catch (\Throwable $e) {
            Craft::error('PIR email send exception: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);

            return $this->emailPdfErrorResponse('Mailer error. Check storage logs.', 500);
        }

        if (!$sent) {
            $bytes = strlen($pdfBytes);
            Craft::warning(
                "PIR email mailer->send() returned false (recipient={$email}, attachment_bytes={$bytes}). "
                . 'Look in storage/logs for the preceding error from the mail transport (often SMTP auth, TLS, or size limits).',
                __METHOD__
            );

            return $this->emailPdfErrorResponse(
                'Mailer could not send. Check Craft Settings → Email and storage/logs for the SMTP error.',
                422
            );
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
