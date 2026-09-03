---
name: frontend-feedback-and-dialogs
description: Strict guidelines for frontend user feedback, alerts, confirmations, notifications, and modals in DermAssist. Enforces zero native browser prompts (alert, confirm, prompt) and mandates AppModalConfirmation and vue-sonner toasts.
---

# DermAssist User Feedback & Dialog Standards

This skill defines mandatory rules for user prompts, alerts, toasts, confirmations, and modals across the entire DermAssist frontend (`views/`).

## 1. Zero Native Browser Dialogs Rule (CRITICAL)

**NEVER use native browser prompts under ANY circumstances**:
- ❌ `window.alert(...)` or `alert(...)`
- ❌ `window.confirm(...)` or `confirm(...)`
- ❌ `window.prompt(...)` or `prompt(...)`

Native browser popups look archaic, disrupt UX, cannot be styled or themed, block JavaScript execution threads, and violate DermAssist product design standards.

---

## 2. Notification & Toast Standard (`vue-sonner`)

For all transient status updates, validation warnings, errors, and success feedback, **ALWAYS** use `toast` from `'vue-sonner'`.

### Usage:
```ts
import { toast } from 'vue-sonner'

// Success
toast.success('Doctor seat assigned successfully.')

// Error
toast.error(err.data?.message || err.message || 'Failed to update schedule.')

// Informational
toast.info('File upload in progress...')

// Warning
toast.warning('Your subscription plan expires in 3 days.')
```

### Rules:
- `Toaster` is already globally mounted in `views/app/app.vue` with rich colors and custom styling.
- Catch blocks in async actions must notify the user via `toast.error(...)`, NEVER `alert(...)`.
- Successful async submissions (create, update, delete, save) must show a concise `toast.success(...)`.

---

## 3. Destructive & Confirm Dialog Standard (`AppModalConfirmation`)

For all confirmations (revoking seats, removing clinic branches, deleting accounts, cancelling appointments, logging out), **ALWAYS** use `<AppModalConfirmation>`.

### Component Location:
`views/app/components/App/Modal/Confirmation.vue` (auto-imported by Nuxt as `<AppModalConfirmation>`).

### Component API:
| Prop | Type | Default | Description |
|---|---|---|---|
| `v-model` | `boolean` | `true` | Controls modal visibility. |
| `title` | `string` | required | Modal headline (e.g. "Revoke Doctor Seat?"). |
| `description` / `message` | `string` | `''` | Explanatory context of the action and consequences. |
| `icon` | `string` | `'lucide:alert-triangle'` | Iconify icon name. |
| `iconColor` | `'danger' \| 'warning' \| 'primary' \| 'info'` | `'danger'` | Badge color palette for the icon. |
| `confirmText` | `string` | `'Confirm'` | Label for primary confirm button. |
| `cancelText` | `string` | `'Cancel'` | Label for cancel button. |
| `confirmVariant` | `'destructive' \| 'solid' \| 'outline'` | `'destructive'` | Variant of the confirm button. |
| `loading` | `boolean` | `false` | Shows spinner on confirm button and disables interaction. |

### Events:
- `@confirm`: Emitted when user clicks confirm.
- `@cancel`: Emitted when user dismisses or clicks cancel.

### Pattern Example:
```vue
<script setup lang="ts">
import { ref } from 'vue'
import { toast } from 'vue-sonner'

const itemToDelete = ref<any>(null)
const showConfirmModal = ref(false)
const isDeleting = ref(false)

const openDeleteConfirm = (item: any) => {
  itemToDelete.value = item
  showConfirmModal.value = true
}

const handleExecuteDelete = async () => {
  if (!itemToDelete.value) return
  isDeleting.value = true
  try {
    await apiService.delete(itemToDelete.value.id)
    toast.success('Item deleted successfully.')
    showConfirmModal.value = false
    itemToDelete.value = null
  } catch (err: any) {
    toast.error(err.data?.message || err.message || 'Failed to delete item.')
  } finally {
    isDeleting.value = false
  }
}
</script>

<template>
  <AppModalConfirmation
    v-model="showConfirmModal"
    title="Revoke Doctor Seat?"
    :description="`Are you sure you want to revoke the doctor seat for Dr. ${itemToDelete?.name}? Their multi-doctor access will be unlinked.`"
    icon="lucide:user-x"
    icon-color="danger"
    confirm-text="Revoke Seat"
    cancel-text="Keep Doctor"
    confirm-variant="destructive"
    :loading="isDeleting"
    @confirm="handleExecuteDelete"
  />
</template>
```

---

## 4. Complex Form Dialogs (`AppModal`)

For detailed forms (adding clinics, assigning doctors, scheduling appointments), use `<AppModal>` with custom inputs and `<AppButton>` controls. Never mix native dialogs with modal forms.
