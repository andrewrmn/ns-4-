# Admin Emails — functional specification

This document describes **exactly** what the Admin Emails plugin does today. Use it when upgrading to Craft CMS 4 (or later) so no behavior is dropped or accidentally changed.

---

## Purpose

Notify an internal mailbox when a **logged-in user’s account is saved from the public (non–Control Panel) site**, so staff can review the update in the Control Panel.

There is **no** Control Panel UI, settings, permissions, or database schema. Behavior is entirely code-defined.

---

## Dependencies

| Dependency | Notes |
|------------|--------|
| **Craft CMS** | Plugin extends `craft\base\Plugin`. Root `composer.json` targets Craft 4; plugin `composer.json` requires `craftcms/cms`. |
| **Craft Commerce** | **Not used.** `AdminEmails.php` imports `Order`, `LineItemEvent`, and `LineItems` and contains **commented-out** Commerce hooks. Commerce is **not** required for this plugin to run. Those imports can be removed during cleanup; they are not functional dependencies. |

---

## Plugin metadata (for parity checks)

| Item | Value |
|------|--------|
| Handle | `admin-emails` |
| PHP class | `neuroscience\adminemails\AdminEmails` |
| Namespace | `neuroscience\adminemails` |
| Schema version | `1.0.0` (no migrations) |
| `hasCpSettings` | `false` |
| `hasCpSection` | `false` |

---

## Runtime behavior

### 1. Plugin bootstrap (`init()`)

- Sets `AdminEmails::$plugin` to the plugin instance.
- Registers a **no-op** listener on `Plugins::EVENT_AFTER_INSTALL_PLUGIN` (empty body when this plugin is installed).
- Logs an info message using the `admin-emails` translation category: `{name} plugin loaded`.
- Registers the **only active feature**: a listener on `User::EVENT_AFTER_SAVE`.

### 2. User save notification (the only active feature)

**Event:** `craft\elements\User::EVENT_AFTER_SAVE`  
**Handler type:** `craft\events\ModelEvent`

**All of the following must be true for an email to send:**

1. **Not a CP request** — `Craft::$app->request->isCpRequest` is false. Saves performed in the Control Panel **do not** trigger this email.
2. **A user is logged in** — `Craft::$app->getUser()->getIdentity()` is non-null.

**Important implementation detail:** The handler does **not** use `$event->sender` (the `User` element that was just saved). It uses the **current session identity** only. In normal front-end “edit my profile” flows that is usually the same user; any edge case where the saved user differs from the logged-in user (unusual on the front end) would **not** be reflected in the email body or CP link.

**Email composition:**

| Field | Source / value |
|-------|----------------|
| **To** | Hardcoded: `newaccounts@neurorelief.com` |
| **Subject** | Hardcoded: `Please review an account update` |
| **From** | Craft system email settings: `Craft::$app->systemSettings->getSettings('email')` → `fromEmail` and `fromName` |
| **Body (HTML)** | Plain string concatenation: user’s `firstName` and `lastName`, plus a “View Profile” link. The URL is `UrlHelper::cpUrl() . '/users/' . $user->id` (CP user edit screen for that user). |

**Sending:** `Craft::$app->mailer->send($message)` with `craft\mail\Message`. There is no try/catch or user-facing error handling; failures behave like any other Craft mail send.

### 3. Dead / non-functional code in the same handler

Immediately before send, there is a block:

```php
if (!empty($attachments) && \is_array($attachments)) {
    // attach assets by ID...
}
```

**`$attachments` is never set** in this file. The attachment loop never runs. Preserving Craft 4 compatibility does **not** require porting attachment behavior unless you intentionally add it later.

### 4. Commented-out code (not active today)

The following are **comments only** and have **no** effect:

- `Order::EVENT_AFTER_COMPLETE_ORDER` (Commerce order completed).
- `Elements::EVENT_AFTER_SAVE_ELEMENT` filtering for `User` (alternative hook).

If you re-enable or replace these during migration, treat that as **new** behavior, not inherited from the current plugin.

---

## When emails fire (summary)

- **Fires:** Each time a `User` element is saved via a **non-CP** request **while someone is logged in** — including registration or profile updates on the front end, and any custom front-end code that saves the user, if the above conditions hold.
- **Does not fire:** User saves from the Control Panel; guest (not logged-in) requests (e.g. guest checkout or public registration with no session may not notify — depends how registration is implemented).

---

## Files that define behavior

| File | Role |
|------|------|
| `src/AdminEmails.php` | All event registration and email logic. |
| `src/translations/en/admin-emails.php` | Single string: “Admin Emails plugin loaded” (log message). |

No migrations, models, services, controllers, templates, or config files.

---

## Craft 4 migration checklist (reference this doc)

Use this list while converting; tick items when verified in the target Craft version.

- [ ] `User::EVENT_AFTER_SAVE` and `ModelEvent` still used as expected; confirm `isCpRequest` semantics unchanged for your front-end entry points.
- [ ] **Recipient and subject** remain `newaccounts@neurorelief.com` and `Please review an account update` unless the business decides to change them (consider env or plugin settings if hardcoding is no longer desired).
- [ ] **From address** still resolves correctly — confirm `systemSettings->getSettings('email')` (or the Craft 4–equivalent API if renamed) still exposes `fromEmail` / `fromName`.
- [ ] `UrlHelper::cpUrl()` and CP user URL pattern `/users/{id}` still valid for your install.
- [ ] `craft\mail\Message` and `Craft::$app->mailer->send()` usage matches current Craft mail docs.
- [ ] Decide whether to use `$event->sender` (saved user) vs `getIdentity()` for parity with **intended** behavior; document any intentional change.
- [ ] Remove unused Commerce imports if still present; optional: remove empty install handler and dead `$attachments` block for clarity.
- [ ] Re-test: save user from CP (no email), save same user from front end while logged in (email received with correct name and CP link).

---

## Version history (documentation)

| Date | Notes |
|------|--------|
| 2026-04-07 | Spec written from source for Craft upgrade planning. |
