# Autoship flow (as implemented today)

This document describes **how autoship / recurring orders are wired today** across the **NeuroSelect plugin** (`plugins/neuroselect`), the **Stripe webhook module** (`modules/StripeWebhookModule.php`), **Enupal Stripe**, **Craft Commerce**, and supporting DB tables. Use it when changing Craft 4, Commerce, Enupal, or Stripe integration so renewals, statuses, and emails still behave as expected.

---

## High-level picture

1. **Subscription billing** is driven by **Stripe** (via **Enupal Stripe**). Recurring charge success surfaces as Stripe webhooks (notably **`invoice.paid`**).
2. **Canonical Commerce “snapshot”** for an autoship program is the **patient’s last completed Commerce order** (the template order). When Stripe renews, the site **clones that Commerce order** into a **new** Commerce order and aligns totals/line items via **raw SQL**.
3. **`craft_autoship_schedule`** (custom table) links **Enupal Stripe order id** to **Commerce order number** and stores **subscription period** dates from Enupal’s **`EVENT_AFTER_ORDER_COMPLETE`** handler.
4. **Patient edits** (change interval or line items) use **`cancelSubscriptionOrderId`** on the cart/order flow to **cancel the previous Stripe subscription** when the new checkout completes (**NeuroSelect** `Order::EVENT_AFTER_COMPLETE_ORDER`).

Supporting pieces: **console** jobs (`AutoShipController`) and **delay** (`HcpController::actionDelayAutoship`) tweak subscriptions or statuses; some of this overlaps or looks **legacy** relative to Stripe-driven renewal.

---

## Key moving parts

| Piece | Role |
|--------|------|
| **Enupal Stripe** `Order` element | Stores subscription/checkout; `variants` JSON includes `orderNumber` matching **Commerce** `order.number`. |
| **Commerce** `Order` | Real order record, line items, adjustments, **`reference`** field (used as a gate for renewal emails). |
| **`craft_autoship_schedule`** | Columns include `orderId` (Enupal order `number`), `comOrderId` (Commerce order number), `currentPeriodStart`, `currentPeriodEnd`. |
| **`StripeWebhookModule`** (`config/app.php`) | Listens to **`enupal\stripe\services\Orders::EVENT_AFTER_PROCESS_WEBHOOK`**, reacts to **`invoice.paid`**. |
| **`Neuroselect` plugin** `src/Neuroselect.php` | **Commerce** `Order::EVENT_AFTER_COMPLETE_ORDER` (recurring flag + cancel old sub); **Enupal** `Orders::EVENT_AFTER_ORDER_COMPLETE` (autoship schedule rows). |
| **Console** `neuroselect/auto-ship/*` | `renew-auto-ship`, `upcoming-autoship-email`; **`reactivate`** mostly commented. |
| **Templates** | `shop/_includes/auto-ship-subscription.html`, `patients/autoship/*`, checkout payment with `makeThisARecurringOrder`. |

---

## Flow A — First signup or “edit” autoship (Commerce checkout completes)

### A1. Commerce `Order::EVENT_AFTER_COMPLETE_ORDER` (NeuroSelect)

**Trigger:** Any **completed** Commerce order on the **front end** (`!isCpRequest`).

**If `makeThisARecurringOrder` is truthy:**

1. Set **`orderStatusId = 5`** on that Commerce order and save (quick save, `false` for validation—**environment-specific**: status **5** must remain the intended handle/meaning).
2. If **`cancelSubscriptionOrderId`** is set (typical when patient **edits** autoship and posts the old **Stripe subscription id**):

   - Call **Enupal Stripe** `cancelStripeSubscription($subscriptionId, $settings->cancelAtPeriodEnd)` so the **previous** subscription winds down per plugin settings.

**Then (separate concern on same event):** if the order email matches a **User**, optionally email **HCP** (`shop/emails/_hcpPatientPlacedOrder`). **Guest** checkouts **return early** and skip that email.

### A2. Enupal Stripe `Orders::EVENT_AFTER_ORDER_COMPLETE` (NeuroSelect)

**Trigger:** Enupal marks its own order complete after subscription/payment flow.

1. Read **Stripe subscription** from `$e->order->getSubscription()`; derive **`current_period_start`** / **`current_period_end`** as `Y-m-d`.
2. **`orderId` = Enupal order `number`**, **`craftOrderNumber` = `$order->getFormFields()['orderNumber']`** (must match how checkout passes the Commerce order number into Enupal metadata/forms).
3. **`INSERT` or `UPDATE**` row in **`craft_autoship_schedule`** for that Enupal order id with `comOrderId`, `currentPeriodStart`, `currentPeriodEnd`.

**Risk:** Raw SQL and **`craft_`** table prefix are environment-specific; values are interpolated into SQL strings.

---

## Flow B — Stripe renewal (`invoice.paid` → cloned Commerce order + emails)

**Where:** `modules/StripeWebhookModule.php` → `Event::on(Orders::class, Orders::EVENT_AFTER_PROCESS_WEBHOOK, ...)`.

### B1. Metadata

For each **invoice line** in the webhook payload, read **`metadata.orderNumber`** (if present). That value is the **Commerce order number** (the subscription’s logical “template” order).

### B2. Enupal Stripe order status

Find **Enupal** `Order` with:

`variants` = `{"orderNumber":"<that number>"}`

If found, set its **`orderStatusId`** to Enupal’s **default** status (`OrderStatus::find()->where(['isDefault' => 1])`) and save.

### B3. Clone Commerce order (renewal order)

1. Load **Commerce** order: `CommerceOrder::find()->where(['number' => $orderNumber])->orderBy(['id' => 'DESC'])->one()`.
2. **`duplicateElement($commerceOrder)`** → new draft Commerce order instance.
3. Resolve Commerce order status by handle **`autoShip`** (`Commerce::getOrderStatuses()->getOrderStatusByHandle('autoShip')`) and assign to the clone; set **`dateCreated`** / **`dateOrdered`** to **now**.
4. Save the clone.
5. **Bulk-update** the new row in **`craft_commerce_orders`** via **raw SQL** so monetary columns and shipping fields match the **source** order (`totalPaid`, `totalPrice`, `itemTotal`, tax, shipping, etc.). This bypasses normal Commerce APIs for those fields.
6. **Copy** **`craft_commerce_orderadjustments`** and **`craft_commerce_lineitems`** from source order id to **`$clonedCommerceOrder->id`** with `INSERT`…`SELECT`-style loops (again **raw SQL**).

**Risks:** Duplicating `uid` on line items, null `lineItemStatusId` forced to `null`, and escaping in SQL—any change in Commerce schema can break this.

### B4. Emails (only if three conditions hold)

The module loads **Enupal** order again by `orderNumber` in `variants`, reloads **Commerce** clone by number, and requires **`$commerceOrder->reference`** to be **truthy**.

If so:

| Recipient | Template | Subject (approx.) |
|-----------|----------|---------------------|
| Patient (`$order->email` from Enupal) | `shop/emails/_orderReceivedPatient` | Your Autoship Order Has Been Placed! |
| HCP (if patient user + `relatedHcp` + `hcpEmailNotifications`) | `shop/emails/_hcpPatientPlacedOrder` | New order on your NeuroScience storefront |
| Admin | `shop/emails/_orderReceivedAdmin` | An autoship order has renewed → **customerservice@neurorelief.com** |

If **`reference`** is missing on the Commerce order, **all three emails are skipped** (warning logged).

**Note:** This path is **in addition to** the NeuroSelect **Commerce** `EVENT_AFTER_COMPLETE_ORDER` HCP email on initial checkout—renewals are driven by the **webhook**, not by a normal Commerce “complete” flow for the cloned order in the same way.

---

## Flow C — Delay next shipment

**Where:** `neuroselect/hcp/delay-autoship` → **`HcpController::actionDelayAutoship`**.

- POST **`subscriptionId`** (Stripe subscription id) and **`delayAutoshipDate`**.
- **`StripePlugin::$app->settings->initializeStripe()`**, then **`\Stripe\Subscription::update($subscriptionId, ['trial_end' => $timestamp, 'proration_behavior' => 'none'])`** where `$timestamp = strtotime($delayAutoshipDate)`.

Older commented code referenced **`craft_autoship_delay`** and Enupal cancel/reactivate; **not active** in the main block.

---

## Flow D — Console: `AutoShipController`

**Registered** when `Neuroselect` sets console controller namespace (`src/Neuroselect.php`).

| Action | Purpose |
|--------|---------|
| **`actionRenewAutoShip`** | Finds **completed** Enupal Stripe orders with **`makeThisARecurringOrder(1)`**. Computes days since **`dateOrdered`**. If **`recurringOrderFrequency`** value is **1 / 2 / 3**, applies **`daysDiff % 17 == 0`**, **`% 60 == 0`**, or **`% 90 == 0`** respectively; sets **`orderStatusId = 5`** and updates **`dateUpdated`**. **Does not** charge cards; looks like **legacy / auxiliary** status maintenance vs Stripe billing. |
| **`actionUpcomingAutoshipEmail`** | **`Subscription::search`** for active Stripe subs; when **`current_period_end`** is **3 days** away and not cancel-at-period-end, resolves Enupal + Commerce order by **`metadata orderNumber`**, sends **`shop/emails/_patientAutoshipComingUp`**. |
| **`actionReactivateAutoShip`** | Body **commented out** (historically **`craft_autoship_delay`** + DB). |

**Cron:** These are only effective if **`./craft neuroselect/auto-ship/...`** is scheduled.

---

## Flow E — Patient UI (context only)

- **`patients/autoship/order`** and related templates: cancel / edit / delay modals, **`cancelSubscriptionOrderId`** on edit form, **`neuroselect/hcp/delay-autoship`**, Enupal actions for cancel/reactivate/customer portal.
- **`config/general.php`**: CSRF exception for **`/patients/autoship/create-cart`** (one integration path posts without CSRF as configured).

---

## End-to-end timeline (conceptual)

```mermaid
sequenceDiagram
  participant Patient
  participant Commerce as Craft Commerce
  participant Enupal as Enupal Stripe
  participant Stripe
  participant NeuroSelect as Neuroselect plugin
  participant DB as craft_autoship_schedule + commerce DB
  participant Webhook as StripeWebhookModule

  Patient->>Commerce: Complete checkout (makeThisARecurringOrder, optional cancelSubscriptionOrderId)
  Commerce->>NeuroSelect: Order EVENT_AFTER_COMPLETE_ORDER
  NeuroSelect->>Commerce: Set status 5; maybe cancel old Stripe sub
  NeuroSelect->>Enupal: (parallel) Enupal completes
  Enupal->>NeuroSelect: EVENT_AFTER_ORDER_COMPLETE
  NeuroSelect->>DB: INSERT/UPDATE autoship_schedule

  Stripe->>Enupal: Subscription invoice paid (webhook)
  Enupal->>Webhook: EVENT_AFTER_PROCESS_WEBHOOK invoice.paid
  Webhook->>Commerce: duplicateElement + SQL sync → new renewal order
  Webhook->>Patient: Emails (if reference set)
```

---

## Dependencies and risks to preserve on upgrade

1. **`orderNumber`** must stay consistent in: Stripe invoice line **metadata**, Enupal **`variants`**, and **Commerce** `order.number` / form fields passed to Enupal.
2. **Commerce `reference`** must be set for renewal notification emails to send from `StripeWebhookModule`.
3. **Order status** ids / handles: **`5`** on first complete, **`autoShip`** on cloned renewal (must exist in Commerce).
4. **Enupal** webhook must still dispatch **`EVENT_AFTER_PROCESS_WEBHOOK`** with the same payload shape for **`invoice.paid`**.
5. **Raw SQL** duplication is fragile; any Commerce 4 schema or element API change needs a deliberate rewrite.
6. **`new Commerce('commerce')`** in the webhook module is nonstandard; verify equivalent service access in target versions.

---

## Related docs

- [`plugins/neuroselect/PLUGIN-BEHAVIOR.md`](plugins/neuroselect/PLUGIN-BEHAVIOR.md) — full NeuroSelect behavior including non-autoship features.

---

## Version history

| Date | Notes |
|------|--------|
| 2026-04-07 | Initial doc from `src/Neuroselect.php`, `modules/StripeWebhookModule.php`, `AutoShipController`, `HcpController`, `config/app.php`, and template grep. |
