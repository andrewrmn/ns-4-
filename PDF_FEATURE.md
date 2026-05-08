# PDF Download / Email Feature — NeuroSelect & Neuro Q

## Overview

Report pages for NeuroSelect (PIR) and Neuro Q (Survey) have "Download Report" and "Email Report" buttons that generate a PDF via PDFShift and either save it for download or email it to the user.

The buttons are currently **hidden** via `{% set neuroselectHideDownloadReport = true %}` at the top of each report template (added in commits `ea8e1d4` and `0cc009e`, May 5 2026).

---

## Architecture

### PDF Engine Selection

`modules/neuroselect/PdfGenerationEngine.php` selects the engine in this order:
1. `PIR_PDF_ENGINE` env var (explicit override: `chromium | pdfshift | wkhtmltopdf | dompdf`)
2. **PDFShift** — if `PDFSHIFT_API_KEY` or `PIR_PDFSHIFT_API_KEY` is set (current default)
3. Chromium — if binary found and `exec`/`proc_open` available
4. Dompdf — final fallback

### Controllers

| Feature | Action | Route |
|---|---|---|
| PIR generate | `PdfController::actionGeneratePdf()` | `POST /actions/neuroselect-module/pdf/generate-pdf` |
| PIR email | `PdfController::actionEmailPdf()` | `POST /actions/neuroselect-module/pdf/email-pdf` |
| Survey generate | `SurveyController::actionSurveyPdf()` | `POST /neuroselect-module/survey/survey-pdf` |
| Survey email | `SurveyController::actionEmailSurvey()` | `POST /neuroselect/email-survey` |

### File Storage

- PIR PDFs: `web/pir-documents/NS-PIR-{userId}-{submissionId}.pdf`
- Survey PDFs: `web/surveys/NS-SURVEY-{submissionId}.pdf`

### PDF Stylesheet

PIR stylesheet resolved in this order:
1. `PIR_PDF_STYLESHEET_URL` env var (public URL)
2. `web/css/pdf9.css` (webroot file, inlined for Dompdf)
3. Fallback: `https://www.neuroscienceinc.com/css/pdf9.css`

Dompdf also appends `web/css/pdf9-dompdf.css` if it exists.
Survey stylesheet: `NEUROQ_PDF_STYLESHEET_URL` or equivalent local path.

---

## Relevant Templates

| Template | Role |
|---|---|
| `templates/neuroselect/submissions/report.html` | PIR report — contains PDF buttons |
| `templates/neuroselect/survey/report.html` | Neuro Q survey report (standard) |
| `templates/neuroselect/survey/5-10/report.html` | Neuro Q survey report (5-10 variant) |
| `templates/shop/customer/submissions/report.html` | Shop customer report |
| `templates/neuroselect/submissions/neurocore.html` | NeuroCore report |

The `source` URL posted to the PDF action is built from `{{ url(craft.app.request.pathInfo) }}?q={{ user.email|url_encode }}` — the report page URL with the user's email as a query param for auth-less rendering.

---

## Known Issues & Fixes

### 1. CRITICAL — Site URL Not Publicly Accessible (PDFShift Fails Locally)

**Problem:** `DEFAULT_SITE_URL=http://local.ns4` in `.env`. PDFShift is a cloud service — it fetches the report URL over the internet. It cannot reach `http://local.ns4`.

**Symptom:** PDFShift returns an error or unreachable response; `PdfShiftRenderer::renderUrlToPdf()` returns `false`; controller responds with a 422 error.

**Fix options:**
- **For production:** Set `PIR_PDF_FETCH_BASE_URL=https://www.neuroscienceinc.com` (or whatever the live domain is). `PdfController::normalizePirPdfSource()` will rewrite the URL before sending to PDFShift.
- **For local dev:** Set `PIR_PDF_ENGINE=dompdf` to use the local Dompdf renderer instead of PDFShift.
- **Tunnel option:** Use ngrok or Cloudflare Tunnel to expose the local site and set `PIR_PDF_FETCH_BASE_URL` to that tunnel URL.

### 2. BUG — `Craft::$app->systemSettings` Removed in Craft 4 (Survey Email Broken)

**Problem:** `SurveyController::actionEmailSurvey()` used `Craft::$app->systemSettings->getSettings('email')` which does not exist in Craft 4. This threw a PHP exception on every "Email Report" click for Neuro Q surveys.

**Fix:** **Already fixed** (this session). Replaced with `App::mailSettings()` and `App::parseEnv()` to match the pattern in `PdfController::actionEmailPdf()`.

File: `modules/neuroselect/controllers/SurveyController.php` around line 427.

### 3. MINOR — PDFShift Sandbox Mode ON (Watermarked PDFs)

**Problem:** `PIR_PDFSHIFT_SANDBOX` is commented out in `.env`, so it defaults to `true`. Sandbox mode adds a PDFShift watermark and doesn't consume credits — fine for testing, not for production.

**Fix:** In `.env`, uncomment and set:
```
PIR_PDFSHIFT_SANDBOX="false"
```

---

## Environment Variables

| Variable | Required | Default | Notes |
|---|---|---|---|
| `PDFSHIFT_API_KEY` | Yes (for PDFShift) | — | PDFShift API key |
| `PIR_PDFSHIFT_API_KEY` | Alternate | — | Alias for above |
| `PIR_PDFSHIFT_SANDBOX` | No | `true` | Set `false` for production PDFs |
| `PIR_PDF_ENGINE` | No | auto | `chromium \| pdfshift \| wkhtmltopdf \| dompdf` |
| `PIR_PDF_FETCH_BASE_URL` | No | — | Rewrites report URL base for PDFShift (e.g. `https://www.neuroscienceinc.com`) |
| `PIR_PDF_STYLESHEET_URL` | No | auto | Public URL for PDF print CSS |
| `NEUROQ_PDF_STYLESHEET_URL` | No | auto | Survey-specific stylesheet URL |
| `PIR_PDFSHIFT_PROCESSOR_VERSION` | No | — | `116` or `142` |
| `CHROMIUM_BIN` / `CHROME_BIN` | No | auto | Path to Chrome/Chromium binary |
| `WKHTMLTOPDF_BIN` | No | auto | Path to wkhtmltopdf binary |

Current `.env` state:
```
PDFSHIFT_API_KEY="e53c829dce5f4013a28cf3eee62b731c"
# PIR_PDFSHIFT_SANDBOX="false"   ← sandbox is ON (watermarks)
# PIR_PDF_FETCH_BASE_URL not set ← PDFShift can't reach local.ns4
```

---

## To Re-Enable the PDF Buttons

Remove the `{% set neuroselectHideDownloadReport = true %}` line (or set it to `false`) from the top of each report template:
- `templates/neuroselect/submissions/report.html:1`
- `templates/neuroselect/survey/report.html:7`
- `templates/neuroselect/survey/5-10/report.html:7`
- `templates/shop/customer/submissions/report.html:1`
- `templates/neuroselect/submissions/neurocore.html:6`

**Before doing so**, ensure:
1. `PIR_PDF_FETCH_BASE_URL` is set to the live public domain (so PDFShift can reach report pages)
2. `PIR_PDFSHIFT_SANDBOX="false"` is set in production `.env`
3. The PDFShift API key has remaining credits

---

## PDFShift API Key Notes

- Key: `e53c829dce5f4013a28cf3eee62b731c` (in `.env`)
- Endpoint: `https://api.pdfshift.io/v3/convert/pdf`
- Authentication: `X-API-Key` header
- Sandbox mode adds watermark; no credits consumed
- Standard plan: timeout capped at 100s
