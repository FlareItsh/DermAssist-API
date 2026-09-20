---
name: dermassist-general-architecture
description: Mandatory architecture, Repository-Service pattern rules, CLI generator commands, Nuxt API service patterns, and main.css design system standards for AI assistants.
---

# DermAssist Repository Architecture & Coding Guidelines

All AI assistants working on DermAssist MUST follow these strict architectural standards and workflow commands.

---

## 1. Backend API Scaffolding & Architecture (Laravel)

### Repository-Service Pattern Requirement

- **Controller Layer**: Delegates all business logic to Services. Controllers never perform direct database queries or raw Eloquent logic.
- **Service Layer**: Manages business logic, validations, and delegates database operations to Repositories. Formats response payloads using Eloquent API Resources (`JsonResource`).
- **Repository Layer**: Encapsulates Eloquent database queries (`Model::where()`, pagination, relations).

### Scaffold Generation Commands

When creating a new API feature or resource, **ALWAYS** use the built-in Artisan generator commands instead of manually writing files from scratch:

1. **Standard Unscoped API Layer**:

    ```bash
    php artisan make:api-layer {Name}
    ```

    _Generates Controller, Service, Repository, and Resource via `api/app/Console/Commands/MakeApiLayer.php`._

2. **Scoped / User-Authenticated API Layer**:
    ```bash
    php artisan make:api-scoped {Name}
    ```
    _Generates user-scoped Controller, Service, Repository, and Resource via `api/app/Console/Commands/MakeApiScoped.php`._

---

## 2. Frontend API Service Pattern (Nuxt 3)

### Service Scaffold Command

When creating frontend API interaction wrappers in `views/app/api`, **ALWAYS** use the generator script:

```bash
pnpm make:service {ResourceName}
```

_Executed via `views/scripts/make-service.js`. Generates TypeScript service classes extending `BaseService`._

### Nuxt API Structure

- Place all service files inside `views/app/api/{resource-name}/{ResourceName}Service.ts`.
- Export a singleton instance (e.g. `export const userService = new UserService()`).

---

## 3. Frontend UI Components & Styling Standards

### Reusable UI Components

- **NEVER** write raw `<button>`, `<input>`, or modal markup when reusable components exist in `views/app/components/App/`.
- Mandatory components to use:
    - `AppButton` (`variant="solid"|"outline"|"ghost"|"soft"`, `size="sm"|"md"|"lg"`, `to`, `loading`, `disabled`)
    - `AppBadge` (`color="primary"|"success"|"warning"|"danger"|"info"|"gray"`, `variant="subtle"|"solid"|"outline"`)
    - `AppModal` (`v-model`, `title`, `description`, `size="lg"`, `#footer` slot)
    - `AppAlert` (`type="warning"|"error"|"info"|"success"`, `title`, `description`)
    - `AppPagination` (`v-model:currentPage`, `:total-items`, `:per-page`, `item-label`)
    - `AppTimeRangePicker` (`v-model:startTime`, `v-model:endTime`, `:blocked-slots`, `:existing-appointments`, `label`)
    - `AppWeeklyTimetable` (`:doctor-uuid`, `:initial-view-date`, `:clinic-filter`, `@select-appointment`, `@select-slot`)
- If a new UI pattern is required, create a reusable component in `views/app/components/App/` first.

### Strictly No Hardcoded Colors

- **NEVER** hardcode arbitrary hex/Tailwind colors (e.g., `bg-emerald-50`, `text-teal-600`, `#10b981`).
- **ALWAYS** use theme tokens defined in `views/app/assets/css/main.css`:
    - `bg-card`, `bg-background`
    - `text-foreground`, `text-muted-foreground`
    - `bg-primary`, `text-primary-foreground`, `border-primary`, `ring-primary`
    - `border-border`, `border-sidebar-border`
    - `text-destructive`

### Strictly No Native Browser Prompts (`alert`, `confirm`, `prompt`)

- **NEVER** use browser popups: `alert()`, `confirm()`, `prompt()`, `window.alert()`, `window.confirm()`.
- **Destructive / Confirmation Dialogs**: **ALWAYS** use `<AppModalConfirmation>` (`views/app/components/App/Modal/Confirmation.vue`).
- **User Feedback & Status Updates**: **ALWAYS** use `toast` from `'vue-sonner'` (`toast.success()`, `toast.error()`, `toast.warning()`).

---

## 4. Nuxt Modules Usage & Conventions (`nuxt.config.ts`)

The frontend repository utilizes the following official Nuxt modules configured in `views/nuxt.config.ts`:

### 1. `@nuxt/icon` (`<Icon />`)

- **Usage**: Use the `<Icon name="..." />` component for all UI iconography.
- **Strict Collection Standard**:
    - **PRIMARY STANDARD**: Use `lucide:*` (e.g. `<Icon name="lucide:layout-dashboard" />`, `<Icon name="lucide:check" />`, `<Icon name="lucide:trash-2" />`) for all primary navigation, action buttons, modals, and list items. This ensures 100% stroke weight and aesthetic consistency.
    - Other available packages in `package.json`: `heroicons:*`, `material-symbols:*`, `tabler:*`.
- **Rule**: Never mix multiple icon art styles (e.g. solid filled vs ultra-thin line) within the same view or component hierarchy. Always favor Lucide.

### 2. `@nuxt/image` (`<NuxtImg />`)

- **Usage**: Use `<NuxtImg src="..." loading="lazy" />` instead of standard `<img>` tags for optimized rendering, lazy-loading, and responsive sizing.
- **Storage Images**: Combine with `useStorage().getStorageUrl(path)` or pass absolute public paths.

### 3. `@nuxt/fonts`

- **Usage**: Google and system fonts (`Poppins`, `DM Sans`, `Playfair Display`, `Barlow Condensed`) are automatically optimized and loaded.
- **Rule**: Apply font families via CSS classes (`font-primary`, `font-sans`, `font-serif`, `font-barlow`) without manually inserting `<link rel="stylesheet">` tags in `<head>`.

### 4. `@nuxt/ui`

- **Usage**: Provides headless component primitives, accessibility utilities, and UI foundation.

### 5. `vue-sonner` (`toast`)

- **Usage**: Use `import { toast } from 'vue-sonner'` for all asynchronous action feedback (mutations, deletions, status changes, copies, approvals, rejections).
- **Mandatory Toast Triggers**:
    - `toast.success('...')`: Triggered upon successful creation, update, deletion, approval, status change, or upload.
    - `toast.error('...')`: Triggered upon network failure, validation errors, or API exception.
    - `toast.info('...')`: Triggered upon informational status toggles (e.g. restoring an appeal to pending).
- **Rule**: Avoid relying solely on console logs or silent page updates. Always provide immediate visual confirmation with `toast.*`.

---

## 5. Design Consistency & Reusable Component Standards

To prevent visual drift and maintain a unified design language:

### 1. Card & Container Geometry

- **Standard Border Radius**: Use `rounded-2xl` for content cards and `rounded-3xl` / `rounded-4xl` for modals and main sidebars.
- **Standard Card Style**: Use `bg-card border border-border shadow-sm` with subtle hover elevations `hover:shadow-md hover:border-primary/30 transition-all`.

### 2. Navigation & Buttons

- **Buttons**: Always use `<AppButton variant="solid"|"outline"|"ghost"|"soft"|"destructive"|"unstyled"` size="sm"|"md"|"lg">` instead of raw `<button>` or unstyled `<NuxtLink>` HTML elements.
- **Button Styling Safety (Tailwind v4 Specific)**:
  - **NEVER** pass conflicting color utilities (like `bg-white` or `text-indigo-950`) to an `<AppButton variant="solid">`. Because `variant="solid"` injects `text-primary-foreground` (white), passing `bg-white` results in invisible white-on-white text until hovered.
  - For solid primary buttons, use `<AppButton variant="solid" size="md">` with its default tokens (`bg-primary text-primary-foreground hover:bg-primary-dark`).
  - If custom colors are strictly necessary on dark cards, use `variant="unstyled"` with your custom classes so `variantClasses` do not clash.
  - Ensure high visual contrast in both idle and hover states.
- **Badges**: Always use `<AppBadge color="primary"|"success"|"warning"|"danger"|"info"|"gray"` variant="subtle"|"solid"|"outline">`.
- **Alerts & Banners**: Always use `<AppAlert type="warning"|"error"|"info"|"success" title="..." description="...">`.
- **Pagination**: Always use `<AppPagination v-model:current-page="..." :total-items="..." :per-page="..." />` on paginated lists.
- **Search Inputs**: Always use `<AppSearch v-model="..." placeholder="..." />` on search toolbars.

### 3. Strictly No Unicode Emojis in Navigation or Buttons
- **Prohibition**: **NEVER** use unicode emojis (e.g. 🏢, 👥, 🗓️, 💳, ⚙️) in sidebar links, tab menus, headers, buttons, or badge labels.
- **Icon Standard**: Always pair navigation items with standardized `<Icon name="lucide:..." />` icons.

### 4. Typography & Heading Hierarchy

- **Page Titles**: `text-2xl md:text-3xl font-black text-foreground`
- **Section Headers**: `text-lg md:text-xl font-bold text-foreground`
- **Card Subheaders / Meta**: `text-xs font-semibold text-muted-foreground`

### 5. Standardized Doctor Settings Navigation
In `views/app/pages/Doctor/profile.vue`, adhere strictly to the following approved tab names and descriptions:
1. **`Profile & Credentials`**: `desc: 'Personal details & PRC license'` (Note: Freeform "Affiliation" is removed; clinical affiliations are grounded strictly in clinic memberships).
2. **`Clinics & Doctor Team`**: `desc: isOwner ? 'Clinic locations & associate doctor seats' : 'Clinic locations & affiliated doctors'`.
3. **`Schedule & Availability`**: `desc: 'Duty hours, blocked dates & timetable'`.
4. **`Subscription & Plan`**: `desc: isSubInherited ? 'Clinic tier & sponsored access' : 'Plan status, quotas & billing'`.
5. **`Account & Security`**: `desc: 'Verification & session security'`.

### 6. View-Aware Layouts & Vue Template Handler Conventions

1. **View-Aware Layout Architecture (List View vs Full-Screen Timetable)**:
   - When views support both list and full-height interactive view modes (such as `<AppWeeklyTimetable>`), non-essential top KPI cards and tab navigation bars MUST be view-aware (`v-if="viewMode === 'list'"`).
   - In timetable/calendar modes, hiding summary cards frees up maximum vertical screen height and prevents page scrolling.
   - Position view-mode switchers in the top header alongside primary page action buttons (e.g., `+ New Appointment`).
   - Include contextual week summary count badges (e.g. `1 patient booked`) and action pill reminders in header toolbars.

2. **Vue Template Multi-Statement Event Handlers**:
   - In Vue SFC `<template>` attributes, inline handlers containing multiple statements MUST be separated by explicit semicolons (`;`), e.g. `@click="viewMode = 'list'; activeTab = 'reschedule'"`, or extracted into a script setup helper method (`@click="switchToRescheduleTab"`).
   - **Reason**: Omitted semicolons in multi-statement inline handlers break `@vue/compiler-sfc` AST parsing during Nuxt SSR page meta compilation (`?macro=true`).

---

## 6. Client-Side Page Hierarchy & Routing Standards (Nuxt 4)

All frontend routes located in `views/app/pages/` MUST strictly conform to the following directory hierarchy and naming rules:

### 1. PascalCase Directory Naming Rule
- **Mandatory Capitalization**: Every folder within `views/app/pages/` MUST start with a capital letter using **PascalCase** (e.g. `Doctor/`, `Secretaries/`, `Patients/`, `Appointments/`, `Messages/`, `Subscription/`, `Profile/`, `Records/`, `Notifications/`, `Updates/`, `Auth/`, `Login/`, `Register/`, `AccountDisabled/`, `Admin/`, `PatchNotes/`, `Subscriptions/Plans/`).
- **Forbidden**: Never use lowercase or kebab-case directory names under `app/pages/` (e.g., `Doctor/secretaries/` or `Admin/patch-notes/` are strictly forbidden).

### 2. Default `index.vue` Architecture
- **Folder per Route Pattern**: By default, each route or section MUST be structured inside its own PascalCase directory containing an `index.vue` entry file.
  - Examples:
    - `Doctor/Patients/index.vue` -> resolves to `/doctor/patients`
    - `Doctor/Secretaries/index.vue` -> resolves to `/doctor/secretaries`
    - `Doctor/Subscription/index.vue` -> resolves to `/doctor/subscription`
    - `Doctor/Appointments/index.vue` -> resolves to `/doctor/appointments`
- **No Coexisting Flat / Directory Mix**: Never create flat `.vue` files alongside a directory of the same name (e.g., never create `Doctor/appointments.vue` next to `Doctor/Appointments/`).

### 3. Dynamic Parameter Children
- Dynamic route parameters within a feature directory MUST follow standard bracket notation (e.g. `Doctor/Appointments/[uuid].vue`, `Doctor/Messages/[uuid].vue`, `Admin/Moderation/Users/[uuid].vue`).

### 4. Semantic Route Naming for Patients
- The consultation patient directories for Doctors and Secretaries are named `Patients` (e.g. `Doctor/Patients/index.vue` and `Secretary/Patients/index.vue`), resolving to `/doctor/patients` and `/secretary/patients`.
- **Legacy Route Redirection**: When renaming routes, always provide backward-compatible redirection pages (e.g., `Doctor/Users/index.vue` and `Secretary/Users/index.vue` with `definePageMeta({ middleware: () => navigateTo('/doctor/patients', { redirectCode: 301, replace: true }) })`) to prevent broken bookmarks or external links.

### 5. Multi-Word Route Aliases via `definePageMeta`
- Nuxt file-based routing converts PascalCase directories to lowercase (e.g., `PatchNotes/index.vue` -> `/patchnotes`, `AccountDisabled/index.vue` -> `/accountdisabled`).
- If kebab-case URLs or alternative routes are needed (such as `/admin/patch-notes` or `/auth/account-disabled`), **ALWAYS** use the page's `definePageMeta({ alias: ['/admin/patch-notes'] })` rather than duplicating files or creating lowercase wrapper folders.

---

## 7. AI Dataset Contribution & Dual-Consent Architecture

When handling skin scan images and saving diagnostic cases to the Admin Retraining Dataset (`storage/app/public/dataset/{category}/`):

### 1. The Dual-Consent Rule
- **Mandatory Condition**: `Can Save = (Patient Consented == TRUE) AND (Doctor Approved == TRUE)`.
- If the patient has not consented, saving is strictly forbidden and rejected by `DatasetService::saveFromDiagnosis` with **HTTP 403 Forbidden**.
- If the patient has consented, the doctor still retains clinical discretion to uncheck the contribution toggle for that individual diagnosis.

### 2. Clinic-Registered Patients
- Patients created by a doctor in-clinic default strictly to `consent_dataset = false` and `terms_accepted_at = null`. Doctors are never permitted to consent on behalf of a patient.
- The patient must log in to their account to review disclaimers and choose their research consent preference in Profile Settings.

### 3. Non-Blocking Scanners
- **Rule**: Never block live cameras, shutter buttons, or file uploads with forced consent checkboxes. Use non-intrusive informational disclaimer pills linking to the Medical Disclaimer and Privacy Policy modals.

### 4. Doctor View Privacy Rule
- If a patient has not consented, the dataset contribution checkbox in `DiagnosisFindingsDetailed.vue` is completely hidden. Never render an explicit "Patient declined" status badge.


