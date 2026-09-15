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
  - Fields: `uuid`, `user_id`, `plan_id`, `plan_version`, `plan_snapshot` (json), `billing_cycle` (`monthly`, `annual`), `auto_renew` (boolean, default `true`), `status` (`pending`, `trialing`, `active`, `past_due`, `cancelled`, `expired`), `starts_at`, `ends_at`, `cancelled_at`, `cancellation_reason`.
  - Helpers: `isActive(): bool`, `isAutoRenew(): bool`, `isPendingCancellation(): bool`, `hasPlanUpdate(): bool`.
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

---

## 5. Mid-Cycle Plan Updates, Grandfathering, & Plan Snapshots

### The Core Architectural Rule
When an administrator modifies a subscription plan (such as raising/lowering prices, increasing/reducing quota limits like `max_secretaries` or `max_clinics`, or toggling feature flags) in the middle of a billing period:
> **MANDATORY RULE**: Currently active subscribers **MUST NOT** automatically receive unpurchased updates mid-cycle, nor have their active features and quotas abruptly reduced. Existing subscribers remain **grandfathered** on their contracted terms until their current cycle ends. To unlock newly added features or revised quotas immediately, they must explicitly upgrade or wait for their next monthly renewal.

### Schema Foundations & Storage
1. **`plans` table**:
   - `version` (`integer`, default `1`): Automatically incremented whenever an admin modifies plan features, quotas, or pricing in `/admin/subscriptions/plans`.
2. **`doctor_subscriptions` table**:
   - `plan_snapshot` (`json`, nullable): A frozen JSON document captured at the instant of subscription creation or payment settlement.
     ```json
     {
       "name": "Clinic Group Plan",
       "price": 2500,
       "billing_cycle": "monthly",
       "max_clinics": 3,
       "max_secretaries": 5,
       "max_doctors": 5,
       "features": {
         "can_execute_scan": true,
         "show_in_recommendation": true,
         "export_pdf_reports": true,
         "can_have_secretary": true
       }
     }
     ```
   - `plan_version` (`integer`, default `1`): The version number of the plan at the time the subscription was purchased or last renewed.

### Effective Capability Resolution (Backend)
When checking doctor permissions and limits, always resolve against the **effective snapshot** first, with fallback to the live plan for legacy rows without a snapshot:
```php
// In DoctorSubscriptionService or User Model:
$snapshot = $subscription->plan_snapshot;

$effectiveFeatures = $snapshot['features'] ?? $subscription->plan->features ?? [];
$effectiveMaxClinics = $snapshot['max_clinics'] ?? $subscription->plan->max_clinics ?? 1;
$effectiveMaxSecretaries = $snapshot['max_secretaries'] ?? $subscription->plan->max_secretaries ?? 0;

// Expose whether an update is available:
$latestPlanVersion = $subscription->plan->version ?? 1;
$hasPlanUpdate = ($latestPlanVersion > ($subscription->plan_version ?? 1));
```

### Frontend Notification & Upgrade Flow (Nuxt)
1. **Composable Integration (`useDoctorSubscription`)**:
   - Computes `hasPlanUpdate`:
     ```ts
     const hasPlanUpdate = computed(() => Boolean(currentSubscription.value?.has_plan_update))
     ```
   - Resolves `maxSecretaries` and `maxClinics` via `currentSubscription.value.plan_snapshot` or `effective_max_*`.
2. **Notification Bell Alert**:
   - `useAppNotifications()` detects `hasPlanUpdate` and surfaces a high-priority alert:
     - Title: **"New Plan Features Available"**
     - Description: *"Your subscription plan has received new features and quota updates! Upgrade or renew now to unlock the latest benefits."*
     - CTA Route: `/doctor/subscription`
3. **Transition to New Version**:
   - When the doctor renews on the next month or executes a plan upgrade via checkout, backend captures the latest `plans.version` into `subscription.plan_version` and freezes a fresh `plan_snapshot`.

---

## 6. Auto-Renewal, Cancellation, & Renewal Guard Rules

### 1. Auto-Renewal Lifecycle & Background Processing
- **Default State**: All paid subscriptions default to `auto_renew = true`.
- **Toggle Endpoint**: `POST /api/subscription/toggle-auto-renew` (`auto_renew: boolean`).
  - If re-enabling auto-renew on a subscription marked for cancellation, `cancelled_at` and `cancellation_reason` are automatically cleared.
- **Daily Scheduler Command (`php artisan subscriptions:process-renewals`)**:
  - Scheduled daily via `routes/console.php`.
  - **Auto-Renew ON (`auto_renew === true` & `ends_at <= now()`)**:
    - Rolls over subscription by 1 billing cycle (`starts_at` advances to previous end, `ends_at` adds 1 month or 1 year).
    - Refreshes `plan_version` and captures updated `plan_snapshot`.
    - Automatically records an approved `PaymentInvoice` (`payment_method: 'Auto-Renew'`).
  - **Auto-Renew OFF (`auto_renew === false` & `ends_at <= now()`)**:
    - Marks subscription as `status = 'expired'`.

### 2. Subscription Cancellation Rules
- **Cancellation Strategy**: Strictly **at period end** (doctor paid for the period, so benefits are retained until `ends_at`).
  - Immediate termination is disabled so doctors never lose pre-paid access abruptly.
- **Cancel Endpoint**: `POST /api/subscription/cancel` (`reason: string`, `feedback?: string`).
  - Sets `auto_renew = false`, records `cancelled_at = now()`, and stores `cancellation_reason`.
  - Subscription preserves `status = 'active'` and full clinical scanning/quota privileges until `ends_at`.
- **Resume Endpoint**: `POST /api/subscription/resume`.
  - Restores `auto_renew = true`, clears `cancelled_at` and `cancellation_reason`.

### 3. Strict Renewal Guard & Tier Switching Rules
> **MANDATORY LIFECYCLE RULE**: A doctor who already holds an active, valid subscription **MUST NOT** be able to prematurely renew or re-purchase the exact same plan before it expires.

1. **Same Plan Renewal (Blocked while Active)**:
   - **Backend Guard**: `DoctorSubscriptionService::checkout()` rejects checkout of the same plan with HTTP `422 Unprocessable Entity`:
     `"You already have an active subscription to {plan_name} valid until {ends_at}. Renewal of this plan is only available once your current subscription expires."`
   - **Frontend UI Guard**:
     - The "Renew Plan" button on the Active Subscription card is **strictly hidden** while `status === 'active' && is_active`.
     - In the pricing grid, the doctor's active plan button is disabled with label `"Current Active Plan"`.
2. **Tier Switching / Upgrading (Permitted while Active)**:
   - If a doctor holds an active subscription and chooses a **different tier** (e.g., upgrading from Individual Tier to Multi-Clinic or Clinic Group Plan):
     - Checkout is **permitted**.
     - Upon payment settlement (`PaymentInvoiceService::approvePayment`), the new plan activates immediately and supersedes/cancels the previous lower-tier subscription.
3. **When Renewal of the Same Plan is Permitted**:
   - Renewal becomes available **only when the subscription has actually expired** (`status === 'expired'` or past valid end date without auto-renewal).
   - Once expired:
     - The subscription card displays the **"Renew Plan"** action.
     - The plan's button in the pricing table unlocks with the label `"Renew Plan"`.


