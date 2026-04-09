# NeuroSelect — functional specification

This document describes **what the NeuroSelect plugin implements** in this codebase: mobile/API integrations, HCP–patient workflows, Neuro‑Q survey flows, PIR PDF generation, Commerce discounts, autoship-related hooks, and emails. Use it for **Craft CMS / Commerce 4** upgrades so nothing is dropped accidentally.

**Convention:** Items marked **Risk** or **Cleanup** are for migration parity *and* optional hardening (dead code, duplicates, secrets, SQL injection).

---

## 1. Which code is actually the plugin?

| Item | Detail |
|------|--------|
| **Composer entry** | `"class": "neuroscience\\neuroselect\\Neuroselect"` → PSR‑4 maps to **`src/Neuroselect.php`** only. |
| **Cleanup (2026-04)** | Removed stale **`plugins/neuroselect/Neuroselect.php`** (root duplicate plugin class), **`src/variables/NeuroselectVariable.php`** (broken; **`craft.neuroselect`** not registered), and **`adjusters/NeurocashPatientDiscount.php`** (orphan; storefront % discount is **`HcpWorkspaceDiscountAdjuster`** in **`hcp-workspace`**). |
| **`src/controllers/GlobalOldSurveyController.php`** | **Removed (2026-04):** was a misnamed duplicate of **`SurveyController`**. |

**Authoritative behavior for a normal install** is **`src/Neuroselect.php`** (routes + install hook) **+** `src/controllers/*` for PIR/survey/API, **plus** **`hcp-workspace` / `patient-shop` / `autoship-schedule`** modules for HCP storefront, patient order/login, and autoship console/schedule.

---

## 2. Dependencies (runtime)

| Dependency | Used for |
|------------|----------|
| **Craft CMS** | Plugin base, users, elements, mail, DB, routing, Twig registration. |
| **Craft Commerce** | Orders, discounts (`craft\commerce\models\Discount`), `Order::EVENT_AFTER_COMPLETE_ORDER`, Commerce order queries in console. **Not** listed in plugin `composer.json`; project supplies it. |
| **Verbb Super Table** | All “submission” storage on users: block types resolved by field handle, `setFieldValues` with stacked row data. |
| **Enupal Stripe** | `Orders::EVENT_AFTER_ORDER_COMPLETE` in **`autoship-schedule`**; `StripePlugin`, `enupal\stripe\elements\Order`, subscription APIs; Commerce recurring + cancel in **`patient-shop`**; **delay autoship** in **`hcp-workspace` `HcpController`**; **upcoming autoship email** in **`autoship-schedule`** console. |
| **Stripe PHP SDK** | `Stripe\Subscription::update`, `Subscription::search` in **`HcpController`** / **`autoship-schedule`** console. |
| **Dompdf** | `composer require` via plugin: `HtmlToPdfRenderer` loads report HTML (Guzzle) and renders PDF in `PdfController` / `SurveyController`. Optional **`PIR_PDF_ENGINE=wkhtmltopdf`** for layout closer to browser print. |
| **External lab API** | `https://core.neurorelief.com/api/v1/labkits/getlabkitresultbyactivitationcode` with `x-api-key` header (**hardcoded** — **Risk**). |

---

## 3. `src/Neuroselect.php` — global hooks

**Split (2026-04):** Most former hooks now live in project modules (see `config/app.php`): **`patient-shop`** (§3.4–3.5), **`autoship-schedule`** (§3.1, §3.6, §11), **`hcp-workspace`** (§3.2, §5, storefront URL alias `neuroselect/hcp/*`). **`src/Neuroselect.php`** keeps only URL rules (§3.3), post-install event, and the plugin loaded log. **`craft.neuroselect`** Twig registration was **removed** (variable class was broken — see §1).

### 3.1 Console (autoship)

- **`autoship-schedule`** module: `controllerNamespace` = `modules\autoshipschedule\console\controllers` on console.
- **Cron / CLI:** **`./craft autoship-schedule/auto-ship/renew-auto-ship`**, **`./craft autoship-schedule/auto-ship/upcoming-autoship-email`**, etc. (**`./craft neuroselect/auto-ship`** is no longer registered.)

### 3.2 Commerce discount adjuster registration (hcp-workspace)

```php
OrderAdjustments::EVENT_REGISTER_DISCOUNT_ADJUSTERS
```

**`hcp-workspace`** appends **`HcpWorkspaceDiscountAdjuster`** (`modules/hcpworkspace/adjusters/HcpWorkspaceDiscountAdjuster.php`) — same behavior as the former **`NeuroselectDiscountSharing`**.

**Behavior (`HcpWorkspaceDiscountAdjuster::adjust`):**

- If logged-in user is in **`patients`** group:
  - Resolve **`relatedHcp`** (relation) on the user.
  - If HCP exists and **`disableProviderEarnings`** is falsy (i.e. provider earnings allowed) **and** **`hcpStorefrontDiscount`** has a **truthy numeric `value`**:
    - Compute **order-level discount adjustment**:  
      `amount = -(order->itemTotal * (hcpStorefrontDiscount->value / 100))`
    - Name **`Provider Discount`**, type **`discount`**, `setOrder($order)`.

**Migration:** Confirm Commerce 4 still exposes **`EVENT_REGISTER_DISCOUNT_ADJUSTERS`** and same adjuster interface.

### 3.3 URL rules (site + CP) — **placeholder keys / bugs**

Same pattern as other legacy plugins:

- Site: `siteActionTrigger1` → `neuroselect/api`, `2` → `pdf`, `3` → `sleep`, `4` → `update`, `5` → `survey` ( **`4`/`5` split fixed 2026-04** — previously `4` was overwritten).
- CP: `cpActionTrigger4` → `update/do-something`, `cpActionTrigger5` → `survey/do-something`.

**Real routes** used by the site are largely in **`config/routes.php`** (e.g. `appConnector/*`) and shorthand paths like **`/neuroselect/...`** in templates (see §8). **Do not assume** `siteActionTrigger*` URLs are meaningful.

### 3.4 `Order::EVENT_AFTER_COMPLETE_ORDER` (`patient-shop` module)

When a Commerce order completes:

1. **Recurring (front-end only)**  
   If **`makeThisARecurringOrder`** and **not** CP request:
   - Set **`orderStatusId = 5`**, `saveElement($order, false)`.
   - If **`cancelSubscriptionOrderId`** set: treat as subscription id, load Enupal Stripe settings, **`cancelStripeSubscription`** with **`cancelAtPeriodEnd`** from plugin settings.

2. **Email HCP when patient places order**  
   Resolve user by **`$order->email`**. If **no user** (guest checkout), **return** (no email).
   - Load **`relatedHcp`**; if HCP exists and **`hcpEmailNotifications`**:
     - Render **`shop/emails/_hcpPatientPlacedOrder`** with `order`, `hcp`.
     - Send from **`info@neuroscienceinc.com`** / **NeuroScience** to **`$hcp->email`**, subject **“New order on your NeuroScience storefront”**.

### 3.5 `WebUser::EVENT_AFTER_LOGIN` (`patient-shop` module)

If user is in **`patients`** group:

- If **`!patientEnrolled`**, HCP exists, and **`hcpEmailNotifications`**:
  - Render **`shop/emails/_hcpPatientEnrolled`**, send to HCP, subject **`{firstName} {lastName} set up their patient portal`**.
  - On mail success: set user field **`patientEnrolled`** to **1**, save user.
- **Always** (for patients): **`redirect`** to **`patients/dashboard`** ( **`send()`** on response — **note**: runs after login; ensure Craft 4 still allows this pattern).

### 3.6 `enupal\stripe\services\Orders::EVENT_AFTER_ORDER_COMPLETE` (`autoship-schedule` module)

- Reads **Stripe subscription** from completed Enupal order; **`current_period_start` / `current_period_end`** dates.
- **`orderNumber`** from form fields vs **`number`** — updates **`{db.tablePrefix}autoship_schedule`** via parameterized **INSERT** / **UPDATE** (logical table **`craft_autoship_schedule`** when prefix is `craft_`).

### 3.7 Twig variable registration

- **None.** Former **`NeuroselectVariable`** file was **deleted** (was not a real variable class). Add a new class under **`src/variables/`** and register in **`src/Neuroselect.php`** only if **`craft.neuroselect`** is needed again.

### 3.8 Misc

- Empty post-install handler; info log **`{name} plugin loaded`** (`neuroselect` category).

---

## 4. Commerce / patient pricing (summary)

| Mechanism | Location | Behavior |
|-----------|----------|----------|
| **Provider storefront discount** | **`HcpWorkspaceDiscountAdjuster`** (`hcp-workspace`) | % of **item total** off order for **patients** tied to HCP with **`hcpStorefrontDiscount`**, unless **`disableProviderEarnings`**. |
| **Per-patient Commerce discount** | **`HcpController::_saveHcpDiscount`** (`modules/hcpworkspace/controllers/HcpController.php`) | Creates/updates Commerce **`Discount`**: `orderConditionFormula` like `order.email in ['a@b.com', ...]`, **user group 6** (patients), **`percentDiscount`** set from form as `(float)$percent / -100`, **`appliedTo`** matching line items, **`ignoreSales`**, etc. |
| **“Remove” old patient discounts** | **`actionSavePatientDiscount`** (same HCP controller) | **`LIKE`** on **`{tablePrefix}commerce_discounts.orderConditionFormula`** for patient email; replaces email with **`customerservice@neurorelief.com`** in formula. |

---

## 5. `HcpController` — HCP-facing actions

**Location:** `modules/hcpworkspace/controllers/HcpController.php` (**`hcp-workspace`** module). Site URL rule maps **`neuroselect/hcp/<action>`** → this controller so existing forms/JS keep working.

**`$allowAnonymous = true`** on the whole controller (**very broad** — **Risk**: every action is theoretically reachable without login; some actions assume logged-in HCP).

| Action | Route segment (typical) | Summary |
|--------|-------------------------|--------|
| **`actionSavePatient`** | `neuroselect/hcp/save-patient` | Create user if email new: password hash from email, assign group **6** (patients), **`relatedHcp`** to current user or **customerservice@neurorelief.com** fallback, invite email **`hcp/_emails/invite-patient`**, optional redirect to recommendation. |
| **`actionSaveRecommendation`** | `save-recommendation` | New **Entry** section **21** type **22**: **`patientAccount`**, **`recommendedProducts`**, **`relatedHcp`**, **`recommendationNote`**; email patient **`hcp/_emails/recommendations`**. |
| **`actionReSendRecommendation`** | GET `recId` | Resend email if entry **`relatedTo`** HCP. |
| **`actionRemoveRecommendation`** | GET `recId`, `patientId` | **`deleteElementById`** if entry links HCP + patient. |
| **`actionSavePatientNotes`** | (POST) | Update **`patientNotes`** on patient user; returns bool (not always JSON). |
| **`actionAcceptTermsConditions`** | `accept-terms-conditions` | Save **`accpetedTermsAndConditions`** (typo field); email **`hcp/_emails/accept-tc`** to internal addresses (**Doug / Amanda** at neurorelief). |
| **`actionSavePatientDiscount`** | `save-patient-discount` | Rewrites old discount formulas (see §4), then **`_saveHcpDiscount()`**. |
| **`actionSaveDiscountSharing`** | `save-discount-sharing` | For **`physicians`** or admin: cap discount by **`hcpNeurocashPercentage`**; set **`hcpSharingDiscountName`**, **`hcpSharingDiscount`**, **`hcpSharingDiscountDetail`**. |
| **`actionSaveDiscount`** | `save-discount` | **`_saveHcpDiscount()`** only. |
| **`actionEnableAutopay`** | GET `enabled` | Set **`enableAutopayForPatients`** on HCP user. |
| **`actionSetProductsAvailability`** | POST | **`restrictedProducts`** on patient if **`_isHcpPatient`**. |
| **`actionDelayAutoship`** | POST `subscriptionId`, `delayAutoshipDate` | **Stripe** `Subscription::update` **`trial_end`** = strtotime(date), **`proration_behavior` => none**; Enupal Stripe init. |
| **`actionTest`** | (empty) | No-op stub. |

**`_saveHcpDiscount` details:** Builds **`craft\commerce\models\Discount`**, saves via **`CommercePlugin::getInstance()->getDiscounts()->saveDiscount`**. Patient list from **`patientId`** single email or **all** patients in group **6** **`relatedTo`** HCP on **`relatedHcp`**.

---

## 6. `ApiController` — mobile app connector (JSON)

**`$allowAnonymous = true`** (entire controller).

| Action | `config/routes.php` alias | Summary |
|--------|---------------------------|--------|
| **`actionLogin`** | `appConnector/authentication` → **`neuroselect/api/login`** | POST **Username** / **Password**; if valid, generates **32-byte hex `appToken`** on user, saves, returns `{ success, token }`. Supports JSON body into `$_POST`. |
| **`actionQrScan`** | `appConnector/qrscan` | POST **Data**, **Category**, **Token**; user lookup **`appToken`**, append Super Table row **`qrScanSubmissions`**; return URL under **`neuroscienceinc.com/account/neuroselect/qrscan/{id}?q={email}`**. |
| **`actionPathway`** | `appConnector/pathway` | **Pathways** (array imploded), **Category**, **Age**, **Gender**, **Token** → **`pathwaySubmissions`**. |
| **`actionSleep`** | `appConnector/sleep` | Sleep questionnaire fields → **`sleepSubmission`**. |
| **`actionClinicalIndication`** | `appConnector/clinicalindication` | **ClinicalIndicators** → **`clinicalIndicationSubmission`**. |
| **`actionProducts`** | `appConnector/products` | **Products** → **`productSubmission`**. |

**Failure paths:** Some branches reference **`EmailModel`**, **`craft()->email`** (Craft 2 API) — **will fatal if executed** (**Risk**).

**`appConnector/updateUsers`** maps to **`neuroselect/api/update-users`** but **no** `actionUpdateUsers` in current `ApiController` (only commented code) — **404 unless implemented elsewhere** (**Risk**).

**Super Table pattern (all API submissions):** New row **`new1`**, copy **`new2…`** from existing blocks to avoid wiping history; **`submissionId`** random 8-digit string; Today’s date; **`pdfGenerated` = 0**.

---

## 7. `PdfController`

**`$allowAnonymous = true`**.

| Action | Summary |
|--------|--------|
| **`actionGeneratePdf`** | POST **`source`** (HTML), **`submissionId`**, **`userId`**, **`submissionType`** (`qrscan|pathway|clinicalindication|products`). Calls **PDFShift**, saves **`./pir-documents/NS-PIR-{userId}-{submissionId}.pdf`**, marks matching Super Table row **`pdfGenerated = 1`**. |
| **`actionEmailPdf`** | POST: attach generated PDF, email user (**system From**), HCP-oriented copy, subject **NeuroScience PIR #**. |
| **`actionGenerateLab`** | POST **`userId`**, **`activationCode`**. Calls **neurorelief** lab API; maps product **names** to Commerce product IDs; rewrites user **`neuroCoreSubmissions`** Super Table (loop replaces data structure — verify it preserves multiple rows as intended). |

---

## 8. `SleepController`

- **`actionSleepPir`**: Web UI updating **`sleepSubmission`** by **`userId`** / **`submissionId`** (create new random id if empty). **`allowAnonymous = true`**.

---

## 9. `UpdateController`

- **`actionUpdatePir`**: Updates **`pathwaySubmissions`**, **`clinicalIndicationSubmission`**, or **`productSubmission`** from POST (`submissionType`, `submissionId`, `userId`, etc.). New submission if `submissionId` empty. Resets **`pdfGenerated`** when editing matched row.

---

## 10. `SurveyController` (Neuro‑Q)

**`$allowAnonymous = true`**.

| Action | Summary |
|--------|--------|
| **`actionSurveySubmission`** | Large POST → new row on user **`surveySubmissions`** Super Table; **`submissionId`** from POST or **current timestamp**; duplicates **`$globalSet = Craft::$app->globals->getSetById('436182')`** read (**`surveySubmissions`** on global) but **user** data is what gets saved. Guest branch: PDFShift report URL, save **`./surveys/NS-SURVEY-{id}.pdf`**, email **`guestUser`** with attachment. |
| **`actionSurveyPdf`** | PDFShift → **`./surveys/`** |
| **`actionEmailSurvey`** | Email survey PDF to address; **unreachable code** after `return Craft::$app->mailer->send` before second **`return $this->asJson`** (**Cleanup**). |

**Hard dependency:** Global set ID **`436182`** and field **`surveySubmissions`** — **environment-specific** (**Risk** when syncing project config).

---

## 11. Console — `modules/autoshipschedule/console/controllers/AutoShipController.php`

| Action | Command | Behavior |
|--------|---------|----------|
| **`actionIndex`** | `autoship-schedule/auto-ship` | Stub welcome message. |
| **`actionRenewAutoShip`** | `autoship-schedule/auto-ship/renew-auto-ship` | Finds **completed** Enupal **Orders** with **`makeThisARecurringOrder(1)`**; if **`recurringOrderFrequency`** value **1 / 2 / 3**, uses **`daysDiff`** from **`dateOrdered`** with **`% 17` / `% 60` / `% 90`** to set **`orderStatusId = 5`** and update **`dateUpdated`**. |
| **`actionReactivateAutoShip`** | | Body **commented out**. |
| **`actionUpcomingAutoshipEmail`** | `autoship-schedule/auto-ship/upcoming-autoship-email` | **`Subscription::search`** active subs; **3 days** before period end → load Enupal + Commerce order by **`metadata orderNumber`**, email **`shop/emails/_patientAutoshipComingUp`**. |

**Cron:** use **`./craft autoship-schedule/auto-ship/...`** (not **`neuroselect/auto-ship`**).

---

## 12. User / Super Table field handles (contract with CP schema)

Controllers assume these **handles** on **User** (and one Global):

- **`appToken`**, **`relatedHcp`**, **`patientEnrolled`**, **`patientNotes`**, **`qrScanSubmissions`**, **`pathwaySubmissions`**, **`sleepSubmission`**, **`clinicalIndicationSubmission`**, **`productSubmission`**, **`neuroCoreSubmissions`**, **`surveySubmissions`**, **`restrictedProducts`**, **`patientNeurocashDiscount`** (used in `_saveHcpDiscount` / discount logic), HCP fields: **`hcpEmailNotifications`**, **`hcpStorefrontName`**, **`hcpStorefrontDiscount`**, **`disableProviderEarnings`**, **`hcpNeurocashPercentage`**, **`hcpSharingDiscount*`**, **`enableAutopayForPatients`**, **`accpetedTermsAndConditions`**, etc.

Entry types: recommendations **section 21** / **type 22** with **product** relations.

---

## 13. Emails sent (template paths)

| Template | Trigger |
|----------|---------|
| `shop/emails/_hcpPatientPlacedOrder` | Order complete (registered user with HCP + notifications). |
| `shop/emails/_hcpPatientEnrolled` | First patient login + enroll flow. |
| `hcp/_emails/invite-patient` | New patient account by HCP. |
| `hcp/_emails/recommendations` | New / resend recommendation. |
| `hcp/_emails/accept-tc` | HCP accepts terms. |
| `shop/emails/_patientAutoshipComingUp` | Console: 3 days before Stripe renewal. |

Default **From** is commonly **`info@neuroscienceinc.com`** (some use system email settings).

---

## 14. Security & operational risks (prioritize during upgrade)

1. **Controllers with `$allowAnonymous = true`** for HCP, API, PDF, survey, sleep, update — **must** be intentional; consider **per-action** allow lists + auth.
2. **Raw SQL** elsewhere (e.g. webhook module, legacy paths) and **Craft DB table names** with **`craft_`** prefix; autoship schedule rows use parameterized queries in **`autoship-schedule`**.
3. **Hardcoded API keys**: PDFShift Basic auth, neurorelief **x-api-key**.
4. **Stale duplicates** (root plugin copy, broken variable file, orphan adjuster) **removed 2026-04**; watch for new duplicate files on merge.
5. **`actionGeneratePdf` `actionEmailPdf`**: writes under **`./pir-documents/`**, **`./surveys/`** relative to CWD — ensure path is correct in worker/queue context.
6. **Order status ID `5`**, user group **`6`**, global set id **`436182`**, section/type **`21`/`22`** — **environment-specific**.

---

## 15. Migration / regression checklist

- [x] **Single `Neuroselect` class** in **`src/Neuroselect.php`**; stale root copy and **`NeuroselectVariable`** removed (2026-04).
- [ ] **`EVENT_REGISTER_DISCOUNT_ADJUSTERS`** vs Commerce 4 adjuster API; retest **patient + HCP storefront %** orders.
- [ ] **`Order::EVENT_AFTER_COMPLETE_ORDER`**: recurring flag, status **5**, Stripe cancel, HCP email, guest skip.
- [ ] **Enupal Stripe** `Orders::EVENT_AFTER_ORDER_COMPLETE` + **`craft_autoship_schedule`** schema.
- [ ] **Patient login redirect** + **`patientEnrolled`** email once.
- [ ] **All `config/routes.php` `appConnector/*`** map to existing actions; implement or remove **`update-users`**.
- [ ] **HCP** save patient, recommendation, discount CRUD, product availability, delay autoship.
- [ ] **PDFShift + lab API** still valid; rotate credentials to env.
- [ ] **Neuro‑Q** survey + PDF + guest email path.
- [ ] **Console** autoship renew + upcoming email cron (**`./craft autoship-schedule/auto-ship/...`** on servers).
- [ ] **Super Table** + field handles unchanged or migrated with content migrations.

---

## 16. Version history (documentation)

| Date | Notes |
|------|--------|
| 2026-04-07 | Spec from `src/Neuroselect.php`, all `src/controllers/*`, adjusters, console, `config/routes.php`, and template action references. |
| 2026-04-07 | Doc update: removed root `Neuroselect.php`, `NeuroselectVariable.php`, `NeurocashPatientDiscount.php`; modules split documented in §1 / §3. |
