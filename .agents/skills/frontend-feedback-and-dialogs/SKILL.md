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

---

## 5. Notification Inspection & Detail Modal Standard (`AppModalNotificationDetail`)

When users interact with in-app notifications (via the Utility Bar bell dropdown or the dedicated Notifications page):

1. **Modal Inspection Over Immediate Navigation**:
   - **Never** jump routes immediately or show an abrupt native dialog.
   - **Always** open `<AppModalNotificationDetail>` (`views/app/components/App/Modal/NotificationDetail.vue`) to allow the user to review the full details and context.

2. **Clinic Seat Invitations (`type === 'clinic_invitation'`)**:
   - Must render the inviting head doctor's profile card (avatar, full name, email, PRC license number).
   - Must show the clinic branch name, physical address, and assigned role badge.
   - Must list the granted clinical subscription privileges.
   - Must offer explicit action buttons:
     - **Accept Invitation**: Calls `acceptInvitation(id)` with loading state -> grants active subscription privileges upon success.
     - **Decline**: Calls `declineInvitation(id)` with loading state -> frees seat quota.
     - **Decide Later**: Closes modal without altering invitation status.

3. **General System & Appointment Notifications**:
   - Displays full descriptive text, category badge, and formatted timestamp.
   - Provides a direct primary CTA button to navigate to the referenced record or conversation if `to` is present.

4. **Read State Persistence**:
   - Opening a notification in `<AppModalNotificationDetail>` must automatically mark that notification as read via `markAsRead(notification.id)`.

5. **"Show All Notifications" & Dedicated Pages**:
   - The notification bell dropdown footer must provide a **"Show All Notifications"** link.
   - Routes: `/doctor/notifications`, `/patient/notifications`, `/secretary/notifications`, `/notifications`.
   - Features filter tabs (**All**, **Unread**, **Invitations**), bulk "Mark all as read", and triggers the same `<AppModalNotificationDetail>` on item click.
