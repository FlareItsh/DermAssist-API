---
name: dermassist-subscription-context
description: Guidelines and architectural context for DermAssist subscription and payment flows, including automated activation, database models, and UI conventions.
---

# DermAssist Subscription & Payment System Guidelines

This skill provides mandatory architectural context for AI assistants working on the DermAssist Subscription and Payment features.

## 1. Automated Payment Gateway Architecture

### Automated Subscription Activation Workflow
- **Single Seamless Online Checkout**:
  - Manual bank transfers and explicit provider logos/tabs are **REMOVED** from the UI.
  - The UI presents a clean, unified **"Instant Automated Subscription Activation"** flow.
  - PayMongo powers the backend checkout session (supporting GCash, Maya, QR Ph, and Credit/Debit Cards).
- **Checkout Submission**:
  - Doctor clicks "Proceed to Secure Payment" (`POST /api/subscription/checkout`).
  - Backend creates a pending `Subscription` and `PaymentInvoice`, returning the gateway `checkout_url`.
  - Doctor is automatically redirected to the secure payment portal.
- **Instant Activation**:
  - **Webhooks**: `POST /api/webhooks/paymongo` receives payment notifications and triggers `PaymentInvoiceService::approvePayment()` to mark invoice as `paid` and `Subscription.status` as `active`.
  - **Return Confirmation**: Returning doctors (`/doctor/subscription?status=success&invoice={uuid}`) trigger `/api/subscription/confirm-return-payment` for instant confirmation.

---

## 2. Key Database Models & Schema Relationships

- **`Plan`** (`plans` table):
  - Fields: `uuid`, `name`, `slug`, `tier_type` (`basic`, `professional`, `enterprise`), `price_monthly`, `price_annual`, `max_doctors`, `max_clinics`, `features` (json array), `is_active`.
- **`Subscription`** (`subscriptions` table):
  - Fields: `uuid`, `user_id`, `plan_id`, `billing_cycle` (`monthly`, `annual`), `status` (`trialing`, `active`, `past_due`, `canceled`), `starts_at`, `ends_at`.
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
