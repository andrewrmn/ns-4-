# NeuroRewards — functional specification

This document describes **exactly** what the NeuroRewards plugin does today. Use it when upgrading Craft CMS / Commerce so “buy 11, get 1 free” behavior and discount interaction stay correct.

---

## Purpose

Implement a **custom Commerce order adjuster** that credits every **12th qualifying bottle** as free (**buy 11, get 1 free**), tied to a Commerce **Discount** record. The plugin also **hooks Commerce’s built-in discount adjuster** so discounts whose name includes **`neurorewards`** do not get applied twice through the standard pipeline.

There is **no** plugin CP settings UI or migrations. Configuration is via **Commerce Discounts** (CP) plus **hardcoded SKU rules** in PHP.

---

## Dependencies

| Dependency | Notes |
|------------|--------|
| **Craft Commerce** | **Required.** Registers order adjusters, uses `Order`, `OrderAdjustment`, `Discount` models, `OrderAdjustments`, `Discount` adjuster events, `AdjusterInterface`. Plugin `composer.json` only lists `craftcms/cms`; the project must ship Commerce. |
| **CP discount named exactly `NeuroRewards`** | The buy-11-get-1 math runs **only** when `$discount->name == 'NeuroRewards'` (case-sensitive). Other discounts whose names merely **contain** the substring `neurorewards` are collected in an earlier filter but **never** receive this logic (see [Naming gotcha](#naming-gotcha-neurorewards-vs-neurorewards)). |

---

## Plugin metadata

| Item | Value |
|------|--------|
| Handle | `neuro-rewards` |
| Class | `neuroscience\neurorewards\NeuroRewards` |
| Schema version | `1.0.0` |
| `hasCpSettings` / `hasCpSection` | `false` |

---

## Components

### 1. `NeuroRewards` (`src/NeuroRewards.php`)

**A. Register custom adjuster** — `OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS`

- Prepends `Discount3for2::class` to the registered adjuster types **unless** a type with the same **short class name** is already listed (compares last segment of FQCN).
- Commented placeholders: `Bundles`, `Trade` (not shipped).

**B. Disable standard discount adjustments for “NeuroRewards” discounts** — `craft\commerce\adjusters\Discount::EVENT_AFTER_DISCOUNT_ADJUSTMENTS_CREATED`

- If `stripos`-style check: `strpos(strtolower($e->discount->name), 'neurorewards') !== false`, sets **`$e->isValid = false`**.
- **Intent:** Stop Commerce’s built-in discount adjuster from applying those discounts in the usual way, so the custom `Discount3for2` adjuster owns the behavior (avoid double application). Confirm against target Commerce version that `isValid` still has this effect.

**C. Other**

- Empty `Plugins::EVENT_AFTER_INSTALL_PLUGIN` handler.
- Info log: “NeuroRewards plugin loaded” (`neuro-rewards` category).

---

### 2. `Discount3for2` (`src/adjusters/Discount3for2.php`)

> **Note:** The filename and class name say “3for2”; the implemented rule is **every 12th unit free** (11 valid + 1 free). File header credits Kurious Agency / “Promotions” — it was used as a starting point.

**Interface:** `craft\commerce\base\AdjusterInterface::adjust(Order $order): array`

#### Step 1 — Choose candidate discounts

- Loads all Commerce discounts via `Commerce::getInstance()->getDiscounts()->getAllDiscounts()`.
- Keeps discounts that are:
  - **enabled**, and
  - name contains **`neurorewards`** (case-insensitive substring):  
    `strpos(strtolower($discount->name), 'neurorewards') !== false`, and
  - **no coupon code** on the discount, **or** the order’s `couponCode` matches the discount code (case-insensitive).

#### Step 2 — Build adjustments per discount

For each candidate discount, calls `_getAdjustments($discount)`.

**Naming gotcha (`NeuroRewards` vs `neurorewards`)**

- Collection uses **substring** `neurorewards` (any casing in the name).
- **`_getAdjustments` only runs the buy-11-get-1 logic when**  
  `$discount->name == 'NeuroRewards'` **(exact string, capital N and R).**
- A CP discount named e.g. `NeuroRewards Holiday` would match the filter but **not** the inner `==` check → **no free-bottle adjustments** from this adjuster (and standard adjustments may be invalidated by the event above if the name still contains `neurorewards`).

#### Step 3 — Eligibility inside `_getAdjustments` (for `NeuroRewards` only)

1. **User groups (must match Commerce’s `Discount` model semantics in your version)**  
   - If **`!$discount->userGroupsCondition`** (falsy):  
     - Require order customer’s user to exist and **intersect** `$discount->getUserGroupIds()` via `Commerce::getInstance()->getCustomers()->getUserGroupIdsForUser($user)`.  
   - **Else** (`userGroupsCondition` truthy):  
     - Set `$inGroup = true` **without** checking user groups.

2. **Date window**  
   - If `dateFrom` / `dateTo` on the discount exclude “now”, return no adjustments (`false`).

3. **Per–line-item, per–unit counting**  
   - Iterates **line items in reverse order** (`array_reverse($order->getLineItems())`).  
   - For each line item, loops **each unit** (`qty` times). Maintains a counter `$ti`.

   **Every 12th unit** (`($ti + 1) % 12 == 0`):

   - If the line item **SKU** is one of the **“free product” SKUs** (cannot receive the automatic free credit as the 12th item):  
     `2056`, `2046`, `20050`, `20067B`  
     → decrement `$ti` and `continue` (that unit does not consume the free slot in the same way as normal SKUs — see code).

   - Otherwise create a **discount-type** `OrderAdjustment`:
     - Linked to that **line item** (`lineItemId`).
     - **Amount:** negative of `salePrice` if `!empty($item->salePrice)`, else negative of `price` (free unit = credit one unit’s price).
     - **Description:** `Buy 11 products, get 1 free (` + line item description + `)`
     - **Name / sourceSnapshot:** from discount; snapshot overwritten to `['data' => $item->description]` after creation (overrides earlier assignment for this adjustment).

   - Then increment `$ti` and `continue`.

   **SKUs that do not advance the “bottle count”** (after the 12th check fails):  
   If SKU is one of  
   `20039`, `20043`, `20048`, `20047`, `20044S`, `20045S`, `20041S`, `20054S`, `20067K`  
   → `$ti--` **before** the closing `$ti++`, so **net +0** for that unit (does not count toward the 12).

4. **Optional `baseDiscount` on the Commerce discount**  
   If `baseDiscount` is non-null and not `0`, code adds an order-level adjustment (`lineItemId` null) with that amount.

   **Bug / migration risk:** ` _createOrderAdjustment($discount)` is called with **one argument**, but `_createOrderAdjustment` requires **`($discount, $data)`** for `array_merge($discount->attributes, $data)`. If `baseDiscount` is ever non-zero in CP, **PHP will throw** (missing argument). Today this may be dead if `baseDiscount` is always zero.

5. **If no adjustments** were built, return `false`.

6. **`DiscountAdjustmentsEvent`** is **instantiated** with `order`, `discount`, and `adjustments`, but **never dispatched** with `Event::trigger`. The return value is simply `$event->adjustments` (listeners never run). Treat as **no-op** for extensions.

---

## Hardcoded SKU reference

| Role | SKUs |
|------|------|
| Cannot be auto-credited as 12th “free” unit | `2056`, `2046`, `20050`, `20067B` |
| Do not count toward the 12 (excluded from progression) | `20039`, `20043`, `20048`, `20047`, `20044S`, `20045S`, `20041S`, `20054S`, `20067K` |

**Cart UI alignment:** `templates/shop/cart.html` uses a similar “counter” for messaging (NeuroRewards copy) but the **exclude list for the counter** is slightly **different** (e.g. it omits some SKUs the adjuster excludes). Promo text and actual credits may not match if SKUs diverge—**preserve or intentionally reconcile** during refactors.

---

## Related project behavior (not in the plugin)

- **`config/element-api.php`** (and variants): `NeuroRewardsCount` on line items is derived from discount/price heuristics for API output — **not** the same code path as this adjuster.
- **Cart template** messaging for “NeuroRewards Program” and Calm PRT exclusions — **Twig**, separate from PHP SKU lists; keep in sync conceptually with commerce rules.

---

## Files

| Path | Role |
|------|------|
| `src/NeuroRewards.php` | Adjuster registration + discount adjuster event |
| `src/adjusters/Discount3for2.php` | Buy-11-get-1 logic |
| `src/translations/en/neuro-rewards.php` | Log translation string |

---

## Migration / regression checklist

- [ ] `OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS` and adjuster order (prepend vs Commerce core adjusters) still produce the same totals.
- [ ] `Discount::EVENT_AFTER_DISCOUNT_ADJUSTMENTS_CREATED` + `isValid` semantics unchanged for your Commerce version.
- [ ] CP discount **name** remains exactly **`NeuroRewards`** if you rely on buy-11-get-1; or align code with intended naming.
- [ ] Verify **`userGroupsCondition`** meaning on `Discount` in Commerce 4 — logic depends on falsy vs truthy.
- [ ] `getUserGroupIds()`, `getCustomers()->getUserGroupIdsForUser()`, line item `sku` / `salePrice` / `price` access unchanged.
- [ ] Fix or confirm **`_createOrderAdjustment($discount)`** missing `$data` if `baseDiscount` is used.
- [ ] Re-test: 12+ qualifying bottles, mixed SKUs, excluded SKUs, coupons, date-limited discount, user in/out of group.
- [ ] Optional: rename `Discount3for2` → something accurate; add `craftcms/commerce` to plugin `composer.json`.

---

## Version history (documentation)

| Date | Notes |
|------|--------|
| 2026-04-07 | Spec written from source + template/API cross-references for Craft/Commerce upgrade planning. |
