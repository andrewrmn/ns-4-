# NeuroRewards — functional specification (module)

This document describes **exactly** what the NeuroRewards **module** does. Use it when upgrading Craft CMS / Commerce so “buy 11, get 1 free” behavior and discount interaction stay correct.

**Bootstrap:** `config/app.php` → module id `neuro-rewards-module`, class `modules\NeuroRewardsModule`.

---

## Purpose

Implement a **custom Commerce order adjuster** that credits every **12th qualifying bottle** as free (**buy 11, get 1 free**), tied to a Commerce **Discount** record. The module also **hooks Commerce’s built-in discount adjuster** so discounts whose name includes **`neurorewards`** do not get applied twice through the standard pipeline.

There is **no** CP settings UI or migrations. Configuration is via **Commerce Discounts** (CP) plus **hardcoded SKU rules** in PHP.

---

## Dependencies

| Dependency | Notes |
|------------|--------|
| **Craft Commerce** | **Required.** Registers order adjusters, `OrderAdjustments`, `Discount` adjuster events, `AdjusterInterface`. Declared in root `composer.json`. |
| **CP discount named exactly `NeuroRewards`** | The buy-11-get-1 math runs **only** when `$discount->name === 'NeuroRewards'` (case-sensitive). |

---

## Module metadata

| Item | Value |
|------|--------|
| Module id | `neuro-rewards-module` |
| Class | `modules\NeuroRewardsModule` |
| Adjuster | `modules\neurorewards\adjusters\Discount3for2` |

---

## Components

### 1. `NeuroRewardsModule` (`modules/NeuroRewardsModule.php`)

**A. Register custom adjuster** — `OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS`

- Prepends `Discount3for2::class` unless a type with the same **short class name** already exists.

**B. Disable standard discount adjustments for “NeuroRewards” discounts** — `CommerceDiscount::EVENT_AFTER_DISCOUNT_ADJUSTMENTS_CREATED`

- If `strpos(strtolower($e->discount->name), 'neurorewards') !== false`, sets **`$e->isValid = false`**.

**C. Other**

- Info log: `NeuroRewards module loaded`.

---

### 2. `Discount3for2` (`modules/neurorewards/adjusters/Discount3for2.php`)

> Class/filename say “3for2”; logic is **every 12th unit free**.

(Same algorithm as documented previously: neurorewards substring filter → exact `NeuroRewards` in `_getAdjustments`, user groups, dates, reverse line items, SKU lists, optional `baseDiscount` via `_createOrderAdjustment($discount, [])`.)

**Change from legacy plugin:** `baseDiscount` branch now passes **`[]`** as second argument so PHP does not error when `baseDiscount` is non-zero.

---

## Files

| Path | Role |
|------|------|
| `modules/NeuroRewardsModule.php` | Adjuster registration + discount adjuster event |
| `modules/neurorewards/adjusters/Discount3for2.php` | Buy-11-get-1 logic |

---

## Version history (documentation)

| Date | Notes |
|------|--------|
| 2026-04-07 | Initial plugin spec (see history in git). |
| 2026-04-07 | Converted to module; paths and `baseDiscount` fix noted. |
