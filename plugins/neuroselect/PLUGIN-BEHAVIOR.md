# NeuroSelect — functional specification

This document describes **what the NeuroSelect plugin implements** in this codebase: mobile/API integrations, HCP–patient workflows, Neuro‑Q survey flows, PIR PDF generation, Commerce discounts, autoship-related hooks, and emails. Use it for **Craft CMS / Commerce 4** upgrades so nothing is dropped accidentally.

**Convention:** Items marked **Risk** or **Cleanup** are for migration parity *and* optional hardening (dead code, duplicates, secrets, SQL injection).

---

## 1. Which code is actually the plugin?

| Item | Detail |
|------|--------|
| **Composer entry** | `"class": "neuroscience\\neuroselect\\Neuroselect"` → PSR‑4 maps to **`src/Neuroselect.php`**. |
| **`plugins/neuroselect/Neuroselect.php` (repo root)** | **Alternate copy** of the plugin class (version **1.1.1**, different `init()`): registers **`NeurocashPatientDiscount`** adjuster only, **no** order/login/Stripe hooks. **Not** the autoloaded class if `composer.json` points at `src/`. Treat as **stale duplicate** unless your `composer.json` `class` is changed to load it. |
| **`src/variables/NeuroselectVariable.php`** | File content is a **duplicate `Plugin` class** (`class Neuroselect extends Plugin` in the `variables` namespace), **not** a Twig variable class. `src/Neuroselect.php` still registers `NeuroselectVariable::class` → **class does not match file** → **critical**: fix or confirm runtime (OPcache/old deploy) before assuming `craft.neuroselect` works. |
| **`adjusters/NeurocashPatientDiscount.php` (under `plugins/neuroselect/adjusters/`, outside `src/`)** | **Not** in Composer PSR‑4 `src/` tree; logic references **`$item` undefined**, positive **`amount`** for a “discount”, likely **broken**. Only the **root** `Neuroselect.php` references it; **`src/Neuroselect.php`** uses **`NeuroselectDiscountSharing`** via **`EVENT_REGISTER_DISCOUNT_ADJUSTERS`**. |
| **`src/controllers/GlobalOldSurveyController.php`** | Filename implies `GlobalOldSurveyController`, but the file declares **`class SurveyController`** again (duplicate of `SurveyController.php`). **Invalid PSR‑4** and **duplicate class risk** if both files load. Treat as **accidental copy** — do not rely on it; remove or rename after verifying nothing references it. |

**Authoritative behavior for a normal install** is assumed to be **`src/Neuroselect.php` + controllers under `src/controllers/`** matching Composer autoload.

---

## 2. Dependencies (runtime)

| Dependency | Used for |
|------------|----------|
| **Craft CMS** | Plugin base, users, elements, mail, DB, routing, Twig registration. |
| **Craft Commerce** | Orders, discounts (`craft\commerce\models\Discount`), `Order::EVENT_AFTER_COMPLETE_ORDER`, Commerce order queries in console. **Not** listed in plugin `composer.json`; project supplies it. |
| **Verbb Super Table** | All “submission” storage on users: block types resolved by field handle, `setFieldValues` with stacked row data. |
| **Enupal Stripe** | `Orders::EVENT_AFTER_ORDER_COMPLETE`, `StripePlugin`, `enupal\stripe\elements\Order`, subscription APIs, `ORDER_AFTER_COMPLETE` recurring logic in `Neuroselect.php`; **delay autoship** in `HcpController`; **upcoming autoship email** in console. |
| **Stripe PHP SDK** | `Stripe\Subscription::update`, `Subscription::search` in `HcpController` / console. |
| **PDFShift** | HTTP Basic auth to `https://api.pdfshift.io/v2/convert/` in `PdfController` and `SurveyController` (API key in source — **Risk**). |
| **External lab API** | `https://core.neurorelief.com/api/v1/labkits/getlabkitresultbyactivitationcode` with `x-api-key` header (**hardcoded** — **Risk**). |

---

## 3. `src/Neuroselect.php` — global hooks

### 3.1 Console

- If `Craft::$app instanceof ConsoleApplication`, `controllerNamespace` = `neuroscience\neuroselect\console\controllers` (e.g. **`./craft neuroselect/auto-ship`**).

### 3.2 Commerce discount adjuster registration

```php
OrderAdjustments::EVENT_REGISTER_DISCOUNT_ADJUSTERS
```

Appends **`NeuroselectDiscountSharing`** (`src/adjusters/NeuroselectDiscountSharing.php`).

**Behavior (`NeuroselectDiscountSharing::adjust`):**

- If logged-in user is in **`patients`** group:
  - Resolve **`relatedHcp`** (relation) on the user.
  - If HCP exists and **`disableProviderEarnings`** is falsy (i.e. provider earnings allowed) **and** **`hcpStorefrontDiscount`** has a **truthy numeric `value`**:
    - Compute **order-level discount adjustment**:  
      `amount = -(order->itemTotal * (hcpStorefrontDiscount->value / 100))`
    - Name **`Provider Discount`**, type **`discount`**, `setOrder($order)`.

**Migration:** Confirm Commerce 4 still exposes **`EVENT_REGISTER_DISCOUNT_ADJUSTERS`** and same adjuster interface.

### 3.3 URL rules (site + CP) — **placeholder keys / bugs**

Same pattern as other legacy plugins:

- Site: `siteActionTrigger1` → `neuroselect/api`, `2` → `pdf`, `3` → `sleep`, `4` → `update` **and** `4` **again** → `survey` (second assignment **overwrites**; **only `survey` remains** for key `siteActionTrigger4`).
- CP: duplicate `cpActionTrigger4` overwrite to `neuroselect/survey/do-something`.

**Real routes** used by the site are largely in **`config/routes.php`** (e.g. `appConnector/*`) and shorthand paths like **`/neuroselect/...`** in templates (see §8). **Do not assume** `siteActionTrigger*` URLs are meaningful.

### 3.4 `Order::EVENT_AFTER_COMPLETE_ORDER`

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

### 3.5 `WebUser::EVENT_AFTER_LOGIN`

If user is in **`patients`** group:

- If **`!patientEnrolled`**, HCP exists, and **`hcpEmailNotifications`**:
  - Render **`shop/emails/_hcpPatientEnrolled`**, send to HCP, subject **`{firstName} {lastName} set up their patient portal`**.
  - On mail success: set user field **`patientEnrolled`** to **1**, save user.
- **Always** (for patients): **`redirect`** to **`patients/dashboard`** ( **`send()`** on response — **note**: runs after login; ensure Craft 4 still allows this pattern).

### 3.6 `enupal\stripe\services\Orders::EVENT_AFTER_ORDER_COMPLETE`

- Reads **Stripe subscription** from completed Enupal order; **`current_period_start` / `current_period_end`** dates.
- **`orderNumber`** from form fields vs **`number`** — used with raw SQL on **`craft_autoship_schedule`**:
  - **SELECT** by `orderId` (Enupal order number),
  - **UPDATE** or **INSERT** `comOrderId`, `currentPeriodStart`, `currentPeriodEnd`.

**Risk:** String interpolation in SQL (**injection** if values ever user-controlled). Table name **`craft_`** prefix is environment-specific.

### 3.7 Twig variable registration

- **`craft.neuroselect`** → **`NeuroselectVariable::class`** (see §1 — **file currently broken**).

### 3.8 Misc

- Empty post-install handler; info log **`{name} plugin loaded`** (`neuroselect` category).

**Unused imports in `src/Neuroselect.php`:** `SaveController`, `SaveEvent`, `guestentries` — **not** used (**Cleanup**).

---

## 4. Commerce / patient pricing (summary)

| Mechanism | Location | Behavior |
|-----------|----------|----------|
| **Provider storefront discount** | `NeuroselectDiscountSharing` | % of **item total** off order for **patients** tied to HCP with **`hcpStorefrontDiscount`**, unless **`disableProviderEarnings`**. |
| **Per-patient Commerce discount** | `HcpController::_saveHcpDiscount` | Creates/updates Commerce **`Discount`**: `orderConditionFormula` like `order.email in ['a@b.com', ...]`, **user group 6** (patients), **`percentDiscount`** set from form as `(float)$percent / -100`, **`appliedTo`** matching line items, **`ignoreSales`**, etc. |
| **“Remove” old patient discounts** | `actionSavePatientDiscount` | **`LIKE`** query on **`craft_commerce_discounts.orderConditionFormula`** for patient email; replaces email with **`customerservice@neurorelief.com`** in formula. |

---

## 5. `HcpController` — HCP-facing actions

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

## 11. Console — `console/controllers/AutoShipController.php`

| Action | Command | Behavior |
|--------|---------|----------|
| **`actionIndex`** | `neuroselect/auto-ship` | Stub welcome message. |
| **`actionRenewAutoShip`** | `neuroselect/auto-ship/renew-auto-ship` | Finds **completed** Enupal **Orders** with **`makeThisARecurringOrder(1)`**; if **`recurringOrderFrequency`** value **1 / 2 / 3**, uses **`daysDiff`** from **`dateOrdered`** with **`% 17` / `% 60` / `% 90`** to set **`orderStatusId = 5`** and update **`dateUpdated`**. |
| **`actionReactivateAutoShip`** | | Body **commented out**. |
| **`actionUpcomingAutoshipEmail`** | | **`Subscription::search`** active subs; **3 days** before period end → load Enupal + Commerce order by **`metadata orderNumber`**, email **`shop/emails/_patientAutoshipComingUp`**. |

**Note:** `use neuroscience\neuroselect\AutoShip` is **imported** but **no such class** in repo — **harmless if unused**, remove if lint fails.

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
2. **Raw SQL** in `Neuroselect` (autoship schedule) and **Craft DB table names** with **`craft_`** prefix.
3. **Hardcoded API keys**: PDFShift Basic auth, neurorelief **x-api-key**.
4. **Duplicate / broken PHP files**: variables file, `GlobalOldSurveyController.php`, root `Neuroselect.php`, `adjusters/NeurocashPatientDiscount.php`.
5. **`actionGeneratePdf` `actionEmailPdf`**: writes under **`./pir-documents/`**, **`./surveys/`** relative to CWD — ensure path is correct in worker/queue context.
6. **Order status ID `5`**, user group **`6`**, global set id **`436182`**, section/type **`21`/`22`** — **environment-specific**.

---

## 15. Migration / regression checklist

- [ ] Confirm **single** authoritative **`Neuroselect` class** and **`NeuroselectVariable`** implementation; delete or fix duplicates.
- [ ] **`EVENT_REGISTER_DISCOUNT_ADJUSTERS`** vs Commerce 4 adjuster API; retest **patient + HCP storefront %** orders.
- [ ] **`Order::EVENT_AFTER_COMPLETE_ORDER`**: recurring flag, status **5**, Stripe cancel, HCP email, guest skip.
- [ ] **Enupal Stripe** `Orders::EVENT_AFTER_ORDER_COMPLETE` + **`craft_autoship_schedule`** schema.
- [ ] **Patient login redirect** + **`patientEnrolled`** email once.
- [ ] **All `config/routes.php` `appConnector/*`** map to existing actions; implement or remove **`update-users`**.
- [ ] **HCP** save patient, recommendation, discount CRUD, product availability, delay autoship.
- [ ] **PDFShift + lab API** still valid; rotate credentials to env.
- [ ] **Neuro‑Q** survey + PDF + guest email path.
- [ ] **Console** autoship renew + upcoming email cron.
- [ ] **Super Table** + field handles unchanged or migrated with content migrations.

---

## 16. Version history (documentation)

| Date | Notes |
|------|--------|
| 2026-04-07 | Spec from `src/Neuroselect.php`, all `src/controllers/*`, adjusters, console, `config/routes.php`, and template action references. |
