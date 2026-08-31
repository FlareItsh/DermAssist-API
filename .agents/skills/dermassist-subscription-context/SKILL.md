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
  - Backend calls `https://api.paymongo.com/v1/checkout_sessions` with line items, user details, and supported payment methods (`gcash`, `paymaya`, `card`, `dob`).
  - Doctor is redirected directly to the PayMongo hosted checkout page where they input GCash OTP, Maya credentials, or card details.
- **Instant Activation**:
  - **Webhooks**: `POST /api/webhooks/paymongo` receives payment notifications and triggers `PaymentInvoiceService::approvePayment()` to mark invoice as `paid` and `Subscription.status` as `active`.
  - **Return Confirmation**: Returning doctors (`/doctor/subscription?status=success&invoice={uuid}`) trigger `/api/subscription/confirm-return-payment` for instant confirmation after verifying with PayMongo's API.
- **Admin Side**:
  - Manual approval/rejection buttons and manual receipt uploads are completely removed.
  - The Admin panel provides real-time audit logs and transaction history for all PayMongo gateway settlements.

---

## 2. Key Database Models & Schema Relationships

- **`Plan`** (`plans` table):
  - Fields: `uuid`, `name`, `slug`, `tier_type` (`basic`, `professional`, `enterprise`), `price_monthly`, `price_annual`, `max_doctors`, `max_clinics`, `features` (json array), `is_active`.
- **`Subscription`** (`subscriptions` table):
  - Fields: `uuid`, `user_id`, `plan_id`, `billing_cycle` (`monthly`, `annual`), `status` (`pending`, `trialing`, `active`, `past_due`, `canceled`), `starts_at`, `ends_at`.
- **`PaymentInvoice`** (`payment_invoices` table):
  - Fields: `uuid`, `subscription_id`, `user_id`, `amount`, `discount_amount`, `final_amount`, `payment_method` (`paymongo`), `payment_status` (`pending`, `paid`, `approved`, `rejected`), `transaction_reference`, `approved_by_user_id`.
- **`Coupon`** (`coupons` table):
  - Fields: `code`, `discount_type` (`percentage`, `fixed`), `value`, `valid_from`, `valid_until`, `max_redemptions`, `times_redeemed`, `is_active`.

---

## 3. UI & Design System Conventions (Nuxt / Frontend)

- **UI Copy & Branding**: Do not explicitly show provider brand names or tab selections to users. Present payment options neutrally as supported methods (e.g. *GCash, Maya, QR Ph, Credit/Debit Cards*).
- **Reusable Components**: Always use project components in `@/components/App/`:
  - `AppButton` (`variant="solid"|"outline"|"ghost"|"soft"`, `size="sm"|"md"|"lg"`, `loading`, `disabled`, `to`)
  - `AppBadge` (`color="primary"|"success"|"warning"|"danger"|"info"|"gray"`, `variant="subtle"|"solid"|"outline"`)
  - `AppModal` (`v-model`, `title`, `description`, `size="lg"`, `#footer` slot)
  - `AppAlert` (`type="warning"|"error"|"info"|"success"`, `title`, `description`)
- **Theme & Colors**:
  - Never hardcode arbitrary Tailwind/hex colors (e.g., `bg-emerald-50`, `text-teal-600`).
  - Use tokens from `main.css`: `bg-card`, `bg-background`, `text-foreground`, `text-muted-foreground`, `bg-primary`, `text-primary-foreground`, `border-sidebar-border`, `border-border`, `text-destructive`.
