---
name: dermassist-subscription-context
description: Guidelines and architectural context for DermAssist subscription and payment flows, including PayMongo API configuration, automated activation, database models, and UI conventions.
---

# DermAssist Subscription & Payment System Guidelines

This skill provides mandatory architectural context for AI assistants working on the DermAssist Subscription and Payment features.

## 1. PayMongo Integration & Environment Configuration

### Required Environment Variable
For real checkout session generation, the application requires the PayMongo Secret Key in the API `.env` file:
```env
PAYMONGO_SECRET_KEY=sk_test_... # Secret key starting with sk_test_ or sk_live_
FRONTEND_URL=http://localhost:3000
```
If `PAYMONGO_SECRET_KEY` is missing when checkout is called, the backend will return a clear configuration error requiring the developer to add their key to `.env`.

### Automated Subscription Activation Workflow
- **Live PayMongo Checkout**:
  - The Doctor clicks "Proceed to Secure Payment" (`POST /api/subscription/checkout`).
  - Backend cleans up any abandoned/unpaid `pending` checkout sessions and invoices for the doctor so only completed transactions persist.
  - Backend calls `https://api.paymongo.com/v1/checkout_sessions` with line items, doctor details, and supported payment methods (`gcash`, `paymaya`, `card`, `dob`, `dob_ubp`).
  - Doctor is redirected directly to the PayMongo hosted checkout page where they input GCash OTP, Maya credentials, or card details.
- **Instant Activation & Dynamic Channel Resolution**:
  - **Return Confirmation**: Returning doctors (`/doctor/subscription?status=success&invoice={uuid}`) trigger `/api/subscription/confirm-return-payment`.
  - Backend queries PayMongo (`GET /v1/checkout_sessions/{id}`) to verify paid status, extracts the specific channel chosen by the doctor (e.g. `GCash`, `Maya`, `Credit / Debit Card`, `Online Bank Transfer`, `QR Ph`), updates `payment_invoices.payment_method`, and records the exact PayMongo payment ID (`pay_...`) in `transaction_reference`.
  - **Webhooks**: `POST /api/webhooks/paymongo` receives async payment notifications and triggers `PaymentInvoiceService::approvePayment()` to mark invoice as `paid` and `Subscription.status` as `active`.
- **Admin Side**:
  - Manual approval/rejection buttons and manual receipt uploads are completely removed.
  - The Admin panel provides a read-only real-time audit ledger and transaction history for all PayMongo gateway settlements with filter tabs (`All Transactions`, `Paid & Settled`, `Pending Checkout`, `Failed / Rejected`).

---

## 2. Key Database Models & Schema Relationships

- **`Plan`** (`plans` table):
  - Fields: `uuid`, `name`, `slug`, `tier_type` (`individual`, `doctor_multi_clinic`, `clinic_multi_doctor`), `price_monthly`, `price_annual`, `max_doctors`, `max_clinics`, `max_secretaries`, `features` (json legacy / custom bullet items), `is_active`.
  - Relations: `planFeatures(): BelongsToMany<Feature>` (via `plan_has_features` pivot table).
- **`Feature`** (`features` table):
  - Fields: `uuid`, `name`, `code` (slug / identifier), `description`, `is_active`, `sort_order`.
  - Pivot table `plan_has_features`: `plan_id`, `feature_id`, `is_included` (boolean).
- **`Subscription`** (`subscriptions` table):
  - Fields: `uuid`, `user_id`, `plan_id`, `billing_cycle` (`monthly`, `annual`), `status` (`pending`, `trialing`, `active`, `past_due`, `canceled`), `starts_at`, `ends_at`.
- **`PaymentInvoice`** (`payment_invoices` table):
  - Fields: `uuid`, `subscription_id`, `user_id`, `amount`, `discount_amount`, `final_amount`, `payment_method` (`GCash`, `Maya`, `Credit / Debit Card`, `Online Bank Transfer`, `QR Ph`), `payment_status` (`pending`, `paid`, `approved`, `rejected`), `transaction_reference` (PayMongo `pay_...` ID), `approved_by_user_id`.
- **`Coupon`** (`coupons` table):
  - Fields: `code`, `discount_type` (`percentage`, `fixed`), `value`, `valid_from`, `valid_until`, `max_redemptions`, `times_redeemed`, `is_active`.

---

## 3. Plan Features Architecture & Usage Guide

Plan features are normalized into dedicated database tables to allow dynamic creating, renaming, toggling, and assigning of features from the Admin Panel (`/admin/subscriptions/features`).

### Core System Features
| Feature Code | Display Name | Purpose & Enforcement Area |
| :--- | :--- | :--- |
| `can_execute_scan` | Allow Doctor AI Scan Execution | Unlocks live skin disease scanning and AI inference for doctors. Gated in `DiagnosisController::store` and `/Doctor/Scan/index.vue`. |
| `show_in_recommendation` | Show in Patient Scan Recommendations | Controls whether the doctor appears in patient nearby doctor recommendations and specialist discovery. Gated in `UserRepository::paginate` (`recommended_only=1`) and `AppointmentService::createAppointment`. |
| `export_pdf_reports` | Allow PDF Clinical Report Exports | Unlocks downloading clinical diagnosis reports in PDF format. |
| `unlimited_appointments` | Enable Teleconsultation Appointments | Allows online/teleconsultation appointment slot booking. |
| `can_have_secretary` | Dedicated Secretary Account Access | Unlocks registering and delegating work to clinic secretary accounts. Gated in `UserService::createDoctorSecretary` alongside plan `max_secretaries` capacity check. |

### How Feature Checking Works in Backend (Laravel)
1. **Model Helpers on `User`**:
   ```php
   // Check any arbitrary feature code
   $user->canAccessFeature('can_execute_scan'); // returns bool

   // Dedicated shortcuts on User model:
   $user->canExecuteScan();       // checks 'can_execute_scan'
   $user->canBeRecommended();     // checks 'show_in_recommendation'
   ```
2. **Model Helper on `Plan`**:
   ```php
   $plan->hasFeature('can_execute_scan'); // returns bool by checking planFeatures relation
   ```
3. **Controller / Service Authorization Gating**:
   ```php
   // In API Controllers / Services:
   if (! $user->canAccessFeature('can_execute_scan')) {
       return response()->json([
           'message' => 'Your subscription plan does not include Doctor AI Scan execution.',
           'error_code' => 'PLAN_FEATURE_RESTRICTED',
       ], 403);
   }
   ```

### How Feature Checking Works in Frontend (Nuxt 3)
1. **Using `useDoctorSubscription` Composable**:
   ```ts
   const {
     isSubscribed,
     canExecuteScan,
     hasFeature,
     fetchSubscription
   } = useDoctorSubscription()

   // In template or script:
   if (!hasFeature('export_pdf_reports')) {
     toast.error('Your current plan does not include PDF clinical report exports.')
   }
   ```
2. **Route Middleware Protection**:
   In `views/app/middleware/auth-role.global.ts`, route guards check active subscription capabilities (e.g. `/doctor/scan` checks `canExecuteScan`).

### How to Add a New Feature in the Future
1. **Admin Panel**: Navigate to `/admin/subscriptions/features` and click **"Create Feature"**. Provide a Feature Name (e.g., *"Custom Clinic Branding"*) and a System Code (e.g., `custom_branding`).
2. **Plan Builder**: Go to `/admin/subscriptions/plans`, edit the desired plans, and check the checkbox for your newly created feature.
3. **Add Backend Gate**:
   - Add a shortcut in `User.php` if needed:
     ```php
     public function canUseCustomBranding(): bool {
         return $this->canAccessFeature('custom_branding');
     }
     ```
   - Enforce it in the respective Controller/Service by calling `$user->canAccessFeature('custom_branding')`.
4. **Add Frontend Gate**:
   - Use `hasFeature('custom_branding')` from `useDoctorSubscription()` to show/hide UI buttons or display an upgrade prompt.

---

## 4. UI & Design System Conventions (Nuxt / Frontend)

- **UI Copy & Branding**: Do not explicitly show provider brand names or tab selections to users. Present payment options neutrally as supported methods (e.g. *GCash, Maya, QR Ph, Credit/Debit Cards*).
- **Reusable Components**: Always use project components in `@/components/App/`:
  - `AppButton` (`variant="solid"|"outline"|"ghost"|"soft"`, `size="sm"|"md"|"lg"`, `loading`, `disabled`, `to`)
  - `AppBadge` (`color="primary"|"success"|"warning"|"danger"|"info"|"gray"`, `variant="subtle"|"solid"|"outline"`)
  - `AppModal` (`v-model`, `title`, `description`, `size="lg"`, `#footer` slot)
  - `AppAlert` (`type="warning"|"error"|"info"|"success"`, `title`, `description`)
- **Theme & Colors**:
  - Always match existing sibling admin pages (`plans.vue`, `payments.vue`, `coupons.vue`) using clean borders `border-gray-200`, `bg-white`, and `bg-gray-50`.

