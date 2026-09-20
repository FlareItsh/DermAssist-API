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

## 5. In-App Notification & Modal Inspection Standard (`useAppNotifications` & `AppModalNotificationDetail`)

When users interact with in-app notifications (via the Utility Bar bell dropdown or dedicated Notifications page):

1. **Modal Inspection Over Immediate Navigation**:
   - **Never** jump routes immediately or show an abrupt native dialog.
   - **Always** open `<AppModalNotificationDetail>` (`views/app/components/App/Modal/NotificationDetail.vue`) to allow the user to review the full details and context before acting.

2. **Specialized Notification Types & Modal Handling**:
   - **Clinic Seat Invitations (`type === 'clinic_invitation'`)**:
     - Renders inviting doctor's profile card (avatar, full name, email, PRC license number).
     - Renders clinic branch name, physical address, and assigned role badge.
     - Lists granted clinical subscription privileges.
     - Actions: **Accept Invitation** (`acceptInvitation(id)`), **Decline** (`declineInvitation(id)`), **Decide Later**.
   - **Clinic Seat Revocations (`type === 'clinic_revocation'`)**:
     - Renders revoked practice head credentials and clinic location.
     - Explanatory notice reassuring doctor that their personal medical records, consultations, and patient history remain intact.
     - Action: **Acknowledge & Dismiss** (`acknowledgeRevocation(id)`).
   - **Patch Notes & System Updates (`type === 'patch_note'`)**:
     - Renders version badge, release title, description, and "What's New & Improvements" bullet list.
     - Actions: **View All Updates** (navigates to `/updates`, `/doctor/updates`, or `/patient/updates`), **Close**.
   - **General System, Appointment, Record & Subscription Notifications**:
     - Displays formatted category badge, relative timestamp, and descriptive context.
     - Provides a direct primary CTA button to navigate to the referenced record, conversation, or billing page via `notification.to`.

3. **Multi-Role Notification Coverage (Composables Standard)**:
   The central reactive composable `useAppNotifications()` (`views/app/composables/useAppNotifications.ts`) dynamically generates alerts for all roles:
   - **Doctor**:
     - Plan updates & quota upgrade alerts (`hasPlanUpdate`).
     - Subscription expiration warnings (5-day countdown, today, expired notice).
     - Secretary quota warnings (when `doctorSecretaries >= maxSecretaries`).
     - Clinic doctor seat quota warnings (when `used_seats >= max_seats`).
     - PRC verification approval & decline notices.
     - Overdue appointments requiring resolution, new appointment requests, upcoming appointments (24h).
     - Unread chat messages with snippet preview.
   - **Secretary**:
     - Incomplete profile warnings (`missingSecretaryFields`).
     - Overdue appointments, new appointment requests, reschedule proposals.
     - Unread chat messages from doctors/patients.
   - **Patient**:
     - New clinical consultation notes & AI skin scan results saved to health history (`/patient/records`).
     - Appointment confirmations, 24-hour upcoming reminders, reschedule proposals, cancellations/declines, and completion summaries.
     - Incomplete profile warnings (`missingPatientFields`).
     - Unread chat messages.
   - **Admin**:
     - Pending doctor PRC verification requests count (`/admin/moderation/verification`).
     - Pending subscription payments awaiting settlement count (`/admin/subscriptions/payments`).
     - Medical diagnosis scan appeals.
     - Published patch notes.

4. **Lifecycle & Polling Architecture**:
   - Uses a **module-level singleton polling timer** (8-second interval) active only when `document.visibilityState === 'visible'`.
   - Listens to the `visibilitychange` event to immediately trigger `pollAllNotifications()` when the user switches back to the tab.
   - Persists read and dismissed IDs per user via cookies: `read_notifs_${userUuid}` and `dismissed_notifs_${userUuid}`.

5. **"Show All Notifications" & Dedicated Pages**:
   - The notification bell dropdown footer must provide a **"Show All Notifications"** link.
   - Dedicated routes: `/doctor/notifications`, `/patient/notifications`, `/secretary/notifications`, `/notifications`.
   - Features filter tabs (**All**, **Unread**, **Invitations**), bulk "Mark all as read", and triggers `<AppModalNotificationDetail>` on item click.

---

## 6. Interactive Component & Timetable Modal Guidelines

1. **Pure Event Emission on Item Click**:
   - Component selection handlers (e.g. `handleApptClick` in `<AppWeeklyTimetable>`) must ONLY emit the selection event (`emit('select-appointment', item)`).
   - **NEVER** pair item click handlers with automatic route navigation (e.g. `navigateTo('/Doctor/Messages/...')`). Clicking an item on a timetable or list grid should open the quick detail modal cleanly without redirecting the user away.

2. **Explicit Modal Action Buttons**:
   - All secondary workflow actions (e.g., *Clinical Consultation*, *Message Patient*) must be presented as explicit, labeled buttons inside the opened detail modal so healthcare providers retain full control over their navigation.

