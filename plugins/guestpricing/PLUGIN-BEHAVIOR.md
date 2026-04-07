# Guest Pricing — functional specification

This document describes **exactly** what the Guest Pricing plugin does today. Use it when upgrading to Craft CMS / Commerce 4 (or later) so pricing behavior for guests and patients is preserved.

---

## Purpose

For eligible Commerce **line items**, override the variant’s normal **price** and **salePrice** with the purchasable’s **suggested retail price (SRP)** before the line item is fully built. This aligns cart line pricing with what guests and “patient” users see on product UIs (SRP instead of professional / sale pricing).

There is **no** Control Panel UI, settings, or database schema in the plugin. Behavior is entirely code-defined.

---

## Dependencies

| Dependency | Notes |
|------------|--------|
| **Craft Commerce** | **Required at runtime.** The plugin listens to `craft\commerce\services\LineItems::EVENT_POPULATE_LINE_ITEM` and uses `LineItemEvent`, purchasables, and line item option APIs. The plugin’s own `composer.json` only lists `craftcms/cms`; the **project** must install Commerce (this repo does). Consider adding `craftcms/commerce` to the plugin’s `require` for clarity. |
| **Field: `suggestedRetailPrice`** | Expected on purchasables (variants). In this project it is a **Plain Text** field (`config/project/fields/suggestedRetailPrice--*.yaml`). The plugin assigns that value directly to `$lineItem->price` and `$lineItem->salePrice`; Commerce will treat it as the monetary amount (ensure stored values are numeric strings acceptable to Commerce). |

### Dead imports in `GuestPricing.php`

These are **never used** and can be removed during cleanup; they do not affect behavior:

- `craft\commerce\adjusters\Discount`
- `craft\commerce\services\OrderAdjustments`
- `craft\commerce\events\DiscountAdjustmentsEvent`

---

## Plugin metadata (for parity checks)

| Item | Value |
|------|--------|
| Handle | `guest-pricing` |
| PHP class | `neuroscience\guestpricing\GuestPricing` |
| Namespace | `neuroscience\guestpricing` |
| Schema version | `1.0.0` (no migrations) |
| `hasCpSettings` | `false` |
| `hasCpSection` | `false` |

---

## Runtime behavior

### 1. Plugin bootstrap (`init()`)

- Sets `GuestPricing::$plugin` to the plugin instance.
- Registers an **empty** `Plugins::EVENT_AFTER_INSTALL_PLUGIN` handler.
- Logs an info message: `{name} plugin loaded` (category `guest-pricing`).
- Registers the **only active feature** on `LineItems::EVENT_POPULATE_LINE_ITEM`.

### 2. Line item populate hook (the only active feature)

**Event:** `craft\commerce\services\LineItems::EVENT_POPULATE_LINE_ITEM`  
**Payload:** `craft\commerce\events\LineItemEvent` → `$event->lineItem`

**Algorithm (matches current code):**

1. `$options = $event->lineItem->getOptions()` (array of cart line options from the add-to-cart / update-cart request).
2. **Guest flag**
   - If `isset($options['guest'])`, then `$isGuest = $options['guest']` (string from form, typically `'yes'` or `'no'`).
   - Else `$isGuest = 'yes'` **by default** (only matters for steps 3–4 below).
3. **Apply SRP only if** both are true:
   - `$options` is **truthy** in PHP (non-empty array; an **empty** `[]` is falsy, so the block is skipped).
   - `$isGuest == 'yes'` (**loose** comparison — string `'yes'`).

4. When conditions pass:
   - `$purchasable = $lineItem->getPurchasable()`
   - `$lineItem->price = $purchasable->suggestedRetailPrice`
   - `$lineItem->salePrice = $purchasable->suggestedRetailPrice`

No other adjustments, discounts, or logging are performed.

### 3. Behavioral consequences of the `if ($options && …)` guard

| Scenario | Typical result |
|----------|------------------|
| Line item has **no** options (empty array) | Condition fails → **no** SRP override → normal Commerce variant pricing. |
| Line item has options **without** `guest`, and other keys exist (e.g. only `isPatient`) | `$isGuest` defaults to `'yes'`, `$options` truthy → **SRP applied**. |
| `options[guest]` = `'yes'` | **SRP applied** (if options array truthy). |
| `options[guest]` = `'no'` | **SRP not applied**; standard variant pricing. |

So the **default** `$isGuest = 'yes'` does **not** apply when options are completely empty: empty options skip the override entirely.

---

## How the front end supplies `options[guest]` (this project)

The plugin does not set options; **templates and carts do.** These are the main integrations found in-repo:

| Location | Behavior |
|----------|----------|
| `templates/products/_product.html` | Hidden `.pricingOption` block with `<select name="options[guest]">`. Select **`yes`** when there is no logged-in user or the user is in the **`patients`** group; otherwise **`no`**. Also sends `options[isPatient]` / line item fields. |
| `templates/products/productPreview.html` | Hidden select with only **`no`** selected (preview add-to-cart uses standard pricing path). |
| `templates/patients/recommendations.html` | Hidden select: **`yes`** selected (patient recommendations use SRP in cart). |
| `templates/products/_product--new.html` | **Does not** submit `options[guest]` (no select/hidden). Add-to-cart relies on whatever Commerce puts in options; if options stay empty, this plugin **does not** force SRP (UI shows `salePrice` for variant line). |

**localStorage `guest`** appears in some product JS (`localStorage.getItem('guest')`) for display logic; that is **separate** from the **`options[guest]`** line item option unless a form maps one to the other.

---

## Relationship to on-page pricing display

Product templates often show:

- **Logged-in users** who are **not** `neuroselectOnly` or `patients`: **sale** / professional price via `purchasable.salePrice` and `commerceCurrency`.
- **Guests** or **`patients`**: display **SRP** (`suggestedRetailPrice`) in the UI.

Guest Pricing makes the **cart line item** match that SRP path when `options[guest]` is yes and options are non-empty — so checkout totals stay consistent with what those users saw.

---

## Files that define plugin behavior

| File | Role |
|------|------|
| `src/GuestPricing.php` | Event registration and price override logic. |
| `src/translations/en/guest-pricing.php` | Log string: “Guest Pricing plugin loaded”. |

No migrations, services, or adjusters.

---

## Craft 4 / Commerce 4 migration checklist

Use this when converting; verify against Commerce’s current docs.

- [ ] `LineItems::EVENT_POPULATE_LINE_ITEM` still exists and fires at the same lifecycle point; `LineItemEvent` API unchanged for `lineItem` and options.
- [ ] **`getPurchasable()`** and **`suggestedRetailPrice`** on the variant still behave as expected (field handle unchanged).
- [ ] Assigning **`price`** and **`salePrice`** on the line item during populate still overrides totals correctly (Commerce may recalculate later; confirm discounts/taxes still apply as today).
- [ ] **Loose** `'yes'` comparison: confirm no accidental `true`/boolean from JSON APIs.
- [ ] Reconcile **empty `$options`** vs default `$isGuest = 'yes'` — if empty carts should use SRP for guests, the current guard may need an intentional change; document any delta.
- [ ] Add explicit **`craftcms/commerce`** constraint to plugin `composer.json` if you publish or reuse the plugin standalone.
- [ ] Remove unused **Discount / OrderAdjustments** imports unless you add real adjusters.
- [ ] Regression-test: add to cart from `_product.html` as guest vs pro user; from `recommendations.html`; from `productPreview.html`; confirm line totals match expected SRP vs professional price.

---

## Version history (documentation)

| Date | Notes |
|------|--------|
| 2026-04-07 | Spec written from source and template usage for Craft/Commerce upgrade planning. |
