---
name: dermassist-multi-doctor-subscription
description: Architectural patterns, database models, resolution strategies, and API rules for Multi-Doctor Shared Subscriptions, Clinic Seat Delegation, and Associate Doctor Access in DermAssist.
---

# Multi-Doctor Shared Subscription & Clinic Seat Delegation Guidelines

This guideline defines how multi-doctor subscription plans (such as *Clinic Group Plan* or *Hospital Enterprise Plan*) are shared among multiple doctor accounts without requiring each doctor to purchase an individual plan.

---

## 1. Architectural Model: Clinic Seat Pool (Recommended)

In a multi-doctor subscription, the **Clinic Owner / Lead Doctor** purchases the master plan (`tier_type: 'clinic_multi_doctor'`). The subscription provides a dynamic pool of doctor seats (`max_doctors`).

```mermaid
graph TD
    A[Clinic Owner / Head Doctor] -->|Purchases Master Plan| B[Clinic Group Subscription]
    B -->|Defines Seat Quota: max_doctors = 5| C[Clinic Doctor Seat Pool]
    C -->|Seat 1: Owner| D[Doctor Account A (Owner)]
    C -->|Seat 2: Invited Associate| E[Doctor Account B (Associate)]
    C -->|Seat 3: Invited Associate| F[Doctor Account C (Associate)]
    C -->|Seat 4: Available| G[Empty Seat]
    C -->|Seat 5: Available| H[Empty Seat]
```

### How Doctors Are Associated with the Clinic Owner:

1. **Clinic Creation**:
   - When a head doctor subscribes, their clinic profile is created in the `clinics` table with `owner_doctor_id = owner.id`.
2. **Assigning an Associate Doctor**:
   - The owner opens their **Clinic Team** portal (`/doctor/clinic/doctors`).
   - The owner searches for a verified doctor by **Email, Name, or PRC Number** (or invites a new doctor via email).
   - Clicking **"Add to Clinic Seat"** inserts a record into `clinic_doctors`:
     ```sql
     INSERT INTO clinic_doctors (clinic_id, doctor_user_id, role, status) 
     VALUES (1, 42, 'associate', 'active');
     ```
3. **Data Privacy & Independent Doctor Identity**:
   - **No Shared Passwords / No Merged Accounts**: Each associate doctor logs in with their own individual credentials.
   - **Individual Medical License**: Each doctor's PRC license number and credentials appear on their clinical reports.
   - **Individual Schedule & Patients**: Each doctor manages their own calendar availability, appointment slots, and private patient chat threads.
4. **Subscription Inheritance**:
   - Associate doctors do **NOT** enter credit card or GCash details.
   - When an associate doctor uses DermAssist, the system detects their active membership in the clinic and grants them full subscription features paid for by the Clinic Owner.
5. **Seat Revocation & Doctor Departure**:
   - If a doctor leaves the clinic, the owner clicks **"Remove Seat"**.
   - The `clinic_doctors` link is removed or marked `revoked`.
   - The seat is immediately freed up for the owner to assign to another doctor.
   - The departing doctor simply loses inherited access; **none of their past medical records or patient notes are deleted**.

---

## 2. Database Schema & Relationships

### Existing Schema Foundations:
- **`clinics` table**:
  - `owner_doctor_id` (foreign key -> `users.id`)
  - `name`, `address`, `phone`, `geo_latitude`, `geo_longitude`, `is_active`
- **`clinic_doctors` pivot table**:
  - `clinic_id` (foreign key -> `clinics.id`)
  - `doctor_user_id` (foreign key -> `users.id`)
  - `role`: `'owner' | 'associate' | 'resident' | 'consultant'`
  - `status`: `'active' | 'pending_invitation' | 'revoked'`

---

## 3. Subscription Resolution Logic (Backend)

To allow seamless capability inheritance, the `User` model resolves its active subscription through **Personal OR Inherited Clinic Subscription**:

```php
/**
 * In App\Models\User.php
 */
public function getEffectiveSubscription(): ?Subscription
{
    // 1. Direct Personal Subscription
    if ($this->hasActiveSubscription()) {
        return $this->subscription;
    }

    // 2. Inherited Clinic Subscription (for Associate Doctors)
    $clinicMembership = $this->clinicMemberships()
        ->where('status', 'active')
        ->whereHas('clinic.owner.subscription', function ($query) {
            $query->whereIn('status', ['active', 'trialing'])
                ->where(function ($q) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                });
        })
        ->first();

    if ($clinicMembership) {
        return $clinicMembership->clinic->owner->subscription;
    }

    return null;
}

public function canAccessFeature(string $featureKey): bool
{
    $subscription = $this->getEffectiveSubscription();
    if (! $subscription || ! $subscription->plan) {
        return false;
    }

    return $subscription->plan->hasFeature($featureKey);
}
```

---

## 4. Clinic Seat Management & Invitation Workflow

### A. Inviting an Associate Doctor:
1. **Clinic Owner** visits `/doctor/clinic/doctors` (or Clinic Settings).
2. Clicks **"Invite Associate Doctor"** and inputs the doctor's email or searches verified doctors.
3. System checks:
   ```php
   $activeSeatsCount = ClinicDoctor::where('clinic_id', $clinic->id)->where('status', 'active')->count();
   $maxSeats = $owner->subscription->plan->max_doctors;
   if ($maxSeats !== null && $activeSeatsCount >= $maxSeats) {
       abort(403, "You have reached your plan limit of {$maxSeats} doctor seats.");
   }
   ```
4. Creates `clinic_doctors` record with `status: 'pending_invitation'` or directly `'active'`.

### B. Associate Doctor Experience:
1. When invited, the doctor receives an in-app notification: *"Dr. Santos invited you to join Makati Skin Clinic under their Clinic Group Plan."*
2. Upon accepting, their status becomes `active`.
3. In their `/doctor/subscription` view, they see:
   - **Plan Type**: `Clinic Member (Covered by Makati Skin Clinic)`
   - **Billing**: Handled by Clinic Owner.
   - **Features**: Full scan access, recommendation listing, and reports.

---

## 5. Multi-Clinic Management & Duty Hour Presets

### Architecture & Models:
1. **Clinic Branch Registration**:
   - Each doctor can register multiple clinic branches or hospital suites (`clinics` table with `owner_doctor_id`).
   - Managed via `useDoctorClinics()` composable (`/doctor/clinics` or Doctor Settings Workspace).
2. **Duty Hour Presets with Clinic Assignment**:
   - `doctor_availabilities` table links a day of the week (`day_of_week`), start time, end time, and an optional `clinic_id` (foreign key -> `clinics.id`).
   - Doctors configure when they are stationed at specific clinics (e.g. *Monday 9 AM - 5 PM at St. Luke's*, *Tuesday 1 PM - 7 PM at Makati Skin Clinic*).
3. **Seeder Setup (`DoctorAvailabilitySeeder`)**:
   - Ensures all active doctor accounts have sample clinic branches and full weekday duty shifts pre-configured for scheduling.

---

## 6. Interactive Weekly Timetable Matrix (`AppWeeklyTimetable.vue`)

- **Component**: `views/app/components/App/AppWeeklyTimetable.vue`
- **Features**:
  - 7-Day Matrix (Monday to Sunday) spanning working hours (7:00 AM to 8:00 PM).
  - Multi-Layer Visualization:
    - 🟢 **Clinic Duty Shifts**: Soft green background band with clinic name and duty hours.
    - 🔴 **Blocked / Away Periods**: Red/striped hazard overlay with away reason.
    - 🔵 **Booked Patient Consultations**: Clickable patient appointment cards with status indicators and quick-view actions.
  - Controls: Week Navigation (`< Previous`, `Next >`, `Today`) and Clinic Filter dropdown.
  - Used in: Doctor Appointments (`Doctor/appointments.vue`), Secretary Appointments (`Secretary/appointments.vue`), and Schedule Settings (`Doctor/profile.vue`).

---

## 7. Intelligent Clinic Autofill in Follow-Up Appointments

- **Rule**: When scheduling patient consultations (in `ScheduleModal.vue` or scan follow-up assessment in `ClinicalNoteForm.vue`), clinic locations must **NEVER** require manual typing.
- **Implementation**:
  1. Retrieve doctor clinics via `useDoctorClinics()`.
  2. Watch `[targetDate, startTime, endTime]` and query `getDutyClinicForDateAndTime(date, start, end)` from `useBlockedDates()`.
  3. If a matching duty shift is found, automatically select the corresponding clinic dropdown option and render an `Autofilled from Duty Preset` badge.
  4. Display quick interval buttons (`+1 Week`, `+2 Weeks`, `+1 Month`) to jump calendar dates rapidly.

---

## 8. Doctor Settings Workspace with Inner Sidebar Layout

- **Page**: `views/app/pages/Doctor/profile.vue`
- **Pattern**:
  - **Left Inner Sidebar (`w-64`)**: Compact Doctor Identity Card + Tab navigation menu (`Profile & Bio`, `Clinic Branches`, `Duty & Away Presets`, `Subscription & Limits`, `Account & Security`).
  - **Right Main Content (`flex-1 min-w-0`)**: Dedicated, focused workspaces for each tab with URL query deep-linking (`?tab=clinics`, `#blocked-dates`).
  - **Subscription & Limits Tab**: Live status card with plan limits/quotas + High-contrast upgrade hero promoting Multi-Doctor Pooling, Secretary Delegation, and Multi-Branch Expansion.

---

## 9. Summary Checklist for Adding Multi-Doctor Features

1. **Plan Setup**: Set `tier_type: 'clinic_multi_doctor'`, `max_doctors: N` in `/admin/subscriptions/plans`.
2. **User Capability Resolution**: Use `getEffectiveSubscription()` so all existing feature checks (`canExecuteScan`, `canBeRecommended`, etc.) automatically cover associate doctors.
3. **Clinic Management UI**: Provide a seat-usage widget (`X / Y Doctor Seats Assigned`) with invite and revoke buttons in the Doctor Clinic Portal.
4. **Duty & Schedule Sync**: Ensure new clinics integrate with `doctor_availabilities` and are reflected in `AppWeeklyTimetable`.

