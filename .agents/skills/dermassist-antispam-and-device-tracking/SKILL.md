---
name: dermassist-antispam-and-device-tracking
description: Architectural guidelines, anti-spam policies, cookie-based device tracking, verification deadlines, soft vs permanent deletion, and TermsModal integration in DermAssist.
---

# DermAssist Anti-Spam, Device Tracking & Account Lifecycle Guidelines

This skill provides mandatory architectural context and workflow standards for managing anti-spam protection, device identification cookies, temporary accounts, verification deadlines, soft-deletion vs. permanent pruning, and cookie consent UI in DermAssist.

---

## 1. Web Security Realities vs. Hardware Blocking

### Browser Sandboxing
Standard web browsers (Chrome, Edge, Safari, Firefox) run in strict security sandboxes. Web applications **cannot** read physical hardware serial numbers, CPU IDs, or MAC addresses due to web privacy standards.

### The Standard Web Solution: Cryptographic Device Tokens
1. **Persistent Device Cookie**: A client-side UUID is generated and stored in a long-lived cookie (`da_device_id`, maxAge: 1 year, SameSite: `Lax`).
2. **Global Request Header**: The frontend composable (`useApi.ts`) automatically attaches `'X-Device-Id': deviceId.value` to every outgoing API request.
3. **IP Logging & Rate Limiting**: The client IP (`$request->ip()`) is evaluated alongside the device token for anomalous registration bursts.

---

## 2. Device & IP Blacklist Architecture

### Database Schema
- **`blocked_devices` Table**:
  - `device_id` (string 100, indexed): Persistent device UUID.
  - `user_id` (foreignId to `users`, nullable, null on delete): Associated user if known.
  - `reason` (text, nullable): Administrative or automated reason for ban.
  - `blocked_by` (foreignId to `users`, nullable): Admin user who initiated the block.
- **`blocked_ips` Table**:
  - `ip_address` (string 45, indexed): Client IPv4 or IPv6 address.
  - `reason` (text, nullable).
  - `expires_at` (timestamp, nullable): Null for permanent blocks or timestamp for temporary cooling bans.

### Middleware Guardrail (`CheckBlockedDeviceOrIp`)
Mounted on authentication endpoints (`/login`, `/register`):
```php
Route::middleware([CheckBlockedDeviceOrIp::class])->controller(AuthController::class)->group(function () {
    Route::post('/login', 'login')->name('login');
    Route::post('/register', 'register');
    Route::post('/verify-account', 'verifyAccount');
});
```
- Checks `X-Device-Id` header, `da_device_id` cookie, and `request->ip()`.
- Rejects matching blacklist records with HTTP 403:
  ```json
  {
    "message": "Access denied: This device has been restricted due to security violations.",
    "is_device_blocked": true
  }
  ```

---

## 3. Temporary Account Lifecycle & Verification

### User Account States (`account_status`)
- `'active'`: Fully verified account with unrestricted access.
- `'pending_verification'`: Self-registered public accounts awaiting email/token verification.
- `'disabled'`: Manually suspended by attending doctor or administrator.

### Public Self-Registration Rules
1. Doctor-registered patients start as `'active'`.
2. Public patient self-registrations start as `'pending_verification'` with:
   - `verification_token`: Cryptographically secure random hash (`Str::random(40)`).
   - `verification_deadline`: Set to 48 hours from registration (`now()->addHours(48)`).
   - `device_token`: Linked to the current client device ID.

### Verification Banner & Modal (`AppVerificationBanner.vue`)
- Displayed prominently in `sidebar-layout.vue` when `user.account_status === 'pending_verification'`.
- Shows a real-time countdown to the deadline (e.g. `Time remaining: 47h 12m`).
- Provides one-click verification token submission modal and resend action.
- Strictly adheres to the **Zero Native Browser Prompts Rule** (uses `vue-sonner` toasts; no `alert()` or `confirm()`).

---

## 4. Automated Soft-Deletion vs. Permanent Pruning

### Soft Deletion (`$user->delete()`)
- **When**: Accounts with `account_status === 'pending_verification'` whose `verification_deadline <= now()`.
- **Action**:
  - Sets `deleted_at = now()`.
  - Immediately purges active Sanctum tokens (`$user->tokens()->delete()`).
  - **Clinical Safety**: Soft-deleting prevents immediate database cascade errors and preserves historical diagnostic integrity.
- **Execution**: Evaluated in real-time in `CheckAccountStatus` middleware and scheduled every minute in `routes/console.php` via `ProcessScheduledAccountActions::processDueActions()`.

### Permanent Pruning (`$user->forceDelete()`)
- **When**: Soft-deleted accounts older than the 14-day trash retention period.
- **Action**:
  - Purges related conversations, messages, appointments, diagnoses, and executes `$user->forceDelete()`.
  - Physically frees database storage and eliminates abandoned dummy records.
- **Execution**: Scheduled daily in `routes/console.php` via `ProcessScheduledAccountActions::pruneExpiredTrash(14)`.

---

## 5. UI Standards for Cookie Notices & Terms

### Minimalist Floating Pill Pattern (`AppCookieBanner.vue`)
- Cookie banners must **never** bombard users with intimidating technical jargon (e.g. avoid phrases like "bot farms", "device tracking tokens", or "strict deactivation deadlines").
- Maintain a **compact, floating glassmorphism pill** in the bottom corner with a single clean sentence:
  > *"We use essential cookies to keep your account secure. [Terms & Cookies] [Accept] [✕]"*

### Terms & Conditions Modal Integration (`AppModalTermsModal`)
- Located in `views/app/components/App/Modal/TermsModal.vue`.
- Must support 3 distinct tabs:
  1. `terms`: Terms & Conditions and Medical Disclaimer.
  2. `privacy`: Privacy Policy & Data Privacy Act compliance.
  3. `cookies`: Essential Cookies & Device Security Policy (`initial-tab="cookies"`).
- **Vue Template Rule**: When using conditional templates (`<template v-if>`, `<template v-else-if>`), `v-else` must **always** be the final terminal branch. Never place a `v-else-if` after a `v-else`.

---

## 6. API Resource Serialization

In `UserResource.php`, **never** expose internal device tokens or cookie timestamps (`device_token`, `cookies_accepted_at`) to public API consumers. Only expose fields necessary for frontend functionality (such as `verification_deadline` for the countdown banner).
