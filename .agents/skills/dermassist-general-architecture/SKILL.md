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
   *Generates Controller, Service, Repository, and Resource via `api/app/Console/Commands/MakeApiLayer.php`.*

2. **Scoped / User-Authenticated API Layer**:
   ```bash
   php artisan make:api-scoped {Name}
   ```
   *Generates user-scoped Controller, Service, Repository, and Resource via `api/app/Console/Commands/MakeApiScoped.php`.*

---

## 2. Frontend API Service Pattern (Nuxt 3)

### Service Scaffold Command
When creating frontend API interaction wrappers in `views/app/api`, **ALWAYS** use the generator script:

```bash
pnpm make:service {ResourceName}
```
*Executed via `views/scripts/make-service.js`. Generates TypeScript service classes extending `BaseService`.*

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
- If a new UI pattern is required, create a reusable component in `views/app/components/App/` first.

### Strictly No Hardcoded Colors
- **NEVER** hardcode arbitrary hex/Tailwind colors (e.g., `bg-emerald-50`, `text-teal-600`, `#10b981`).
- **ALWAYS** use theme tokens defined in `views/app/assets/css/main.css`:
  - `bg-card`, `bg-background`
  - `text-foreground`, `text-muted-foreground`
  - `bg-primary`, `text-primary-foreground`, `border-primary`, `ring-primary`
  - `border-border`, `border-sidebar-border`
  - `text-destructive`

---

## 4. Nuxt Modules Usage & Conventions (`nuxt.config.ts`)

The frontend repository utilizes the following official Nuxt modules configured in `views/nuxt.config.ts`:

### 1. `@nuxt/icon` (`<Icon />`)
- **Usage**: Use the `<Icon name="..." />` component for all UI iconography.
- **Available Collections**:
  - `lucide:*` (e.g. `<Icon name="lucide:check" />`, `<Icon name="lucide:credit-card" />`)
  - `heroicons:*` (e.g. `<Icon name="heroicons:chart-bar" />`)
  - `material-symbols:*` (e.g. `<Icon name="material-symbols:chevron-left-rounded" />`)
  - `solar:*`, `bx:*`, `tabler:*`, `mingcute:*`, `mynaui:*`
- **Rule**: Avoid inline raw SVGs. Always use `<Icon />` with appropriate collection identifiers.

### 2. `@nuxt/image` (`<NuxtImg />`)
- **Usage**: Use `<NuxtImg src="..." />` instead of standard `<img>` tags for optimized rendering, lazy-loading, and responsive sizing.
- **Storage Images**: Combine with `useStorage().getStorageUrl(path)` or pass absolute public paths.

### 3. `@nuxt/fonts`
- **Usage**: Google and system fonts (`Poppins`, `DM Sans`, `Playfair Display`, `Barlow Condensed`) are automatically optimized and loaded.
- **Rule**: Apply font families via CSS classes (`font-primary`, `font-sans`, `font-serif`, `font-barlow`) without manually inserting `<link rel="stylesheet">` tags in `<head>`.

### 4. `@nuxt/ui`
- **Usage**: Provides headless component primitives, accessibility utilities, and UI foundation.

### 5. `vue-sonner` (`toast`)
- **Usage**: Use `toast.success('...')`, `toast.error('...')`, `toast.info('...')` from `vue-sonner` for user feedback and flash alerts.

