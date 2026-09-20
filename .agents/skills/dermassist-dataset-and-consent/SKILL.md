---
name: dermassist-dataset-and-consent
description: Architectural guidelines, Dual-Consent Rule, database schemas, backend guardrails, and UI conventions for patient consent and saving clinical scans to the admin AI retraining dataset in DermAssist.
---

# DermAssist Dataset Contribution & Patient Consent Guidelines

This skill provides mandatory architectural context and workflow standards for managing terms, conditions, patient data privacy, and contributing clinical scan images to the Admin AI Retraining Dataset (`storage/app/public/dataset/{category}/`).

---

## 1. The Dual-Consent Principle (Core Rule)

In DermAssist, clinical scan images obtained during diagnostic consultations can only be contributed to the AI retraining dataset if **both** the patient and the doctor agree.

```
Can Save to Dataset = (Patient Consented == TRUE) AND (Doctor Approved == TRUE)
```

### Ethical & Legal Rationale:
1. **Patient is Data Subject**: The patient owns their personal biometric and dermatological image data. Under HIPAA, GDPR, and the Philippine Data Privacy Act, patient data cannot be used for secondary AI research/training without explicit consent.
2. **Doctor is Clinical Custodian**: Even if a patient consents to donate their anonymized data, the doctor must retain clinical discretion to withhold any specific case that contains diagnostic ambiguities, identifying marks, or sensitive anatomical locations.

### Dual-Consent Decision Matrix:

| Patient Consented? | Doctor Approved? | Saved to Admin Dataset? | Doctor UI State in Diagnosis Findings |
| :--- | :--- | :--- | :--- |
| ❌ **No (Opted Out)** | ❌ No | 🛑 **NO** | Checkbox is **completely hidden** |
| ❌ **No (Opted Out)** | ✅ Yes | 🛑 **NO (Strictly Blocked)** | Checkbox is **completely hidden** (Doctor cannot override) |
| ✅ **Yes (Consented)** | ❌ No (Doctor unchecks) | 🛑 **NO** | Checkbox visible, doctor unchecked it for this case |
| ✅ **Yes (Consented)** | ✅ Yes (Doctor checks) | 🚀 **YES** | Checkbox visible & checked -> saved upon note completion |
| ❓ **Unregistered / Walk-in** | Any | 🛑 **NO (Privacy First)** | Checkbox is **completely hidden** (no consent record) |

---

## 2. Database Models & Schema Specifications

### `users` Table
- `consent_dataset` (`boolean`, default `false`): Tracks whether the patient consented to donating anonymized skin scan photos to AI retraining.
- `terms_accepted_at` (`timestamp`, nullable): Timestamp when the user accepted platform Terms & Conditions and Privacy Policy.

### `diagnoses` Table
- `patient_consented_dataset` (`boolean`, default `false`): Historical snapshot of the patient's consent status at the moment the scan was conducted or assigned.
- `contributed_to_dataset` (`boolean`, default `false`): Marked `true` once the image is copied into the admin dataset pool.
- `contributed_at` (`timestamp`, nullable): Timestamp when the scan was contributed to the dataset.

### Eloquent Models & Resources
- **`User` model** (`app/Models/User.php`):
  - Casts: `'consent_dataset' => 'boolean'`, `'terms_accepted_at' => 'datetime'`.
  - Helper: `hasConsentedToDataset(): bool`.
- **`Diagnosis` model** (`app/Models/Diagnosis.php`):
  - Casts: `'patient_consented_dataset' => 'boolean'`, `'contributed_to_dataset' => 'boolean'`, `'contributed_at' => 'datetime'`.
- **Resources** (`UserResource.php`, `DiagnosisResource.php`):
  - Serializes `consent_dataset`, `terms_accepted_at`, `patient_consented_dataset`, and `contributed_to_dataset`.

---

## 3. Backend Guardrails & Service Implementation

### 1. Dataset Saving Validation (`DatasetService::saveFromDiagnosis`)
In `api/app/Service/DatasetService.php`:
- Before copying any file to `storage/app/public/dataset/{category}/`, the service verifies:
  ```php
  $isPatientConsented = false;
  if ($diagnosis->patient_consented_dataset) {
      $isPatientConsented = true;
  } elseif (! empty($diagnosis->patient_uuid)) {
      $patient = User::where('uuid', $diagnosis->patient_uuid)->first();
      if ($patient && $patient->consent_dataset) {
          $isPatientConsented = true;
      }
  }

  if (! $isPatientConsented) {
      return response()->json([
          'status' => 'error',
          'message' => 'Cannot save to dataset: Patient has not consented to AI retraining dataset contribution.',
      ], 403);
  }
  ```
- When successful, updates the diagnosis:
  ```php
  $diagnosis->update([
      'contributed_to_dataset' => true,
      'contributed_at' => now(),
  ]);
  ```

### 2. Diagnosis Creation & Update Snapshotting (`DiagnosisService.php`)
- In `diagnose()`: Automatically reads `User::where('uuid', $data['patient_uuid'])->consent_dataset` and sets `patient_consented_dataset`.
- In `updateDiagnosis()`: If a diagnosis is later assigned or linked to a `patient_uuid`, updates `patient_consented_dataset` with that patient's current consent choice.

### 3. Doctor-Created Patient Privacy Rule (`UserService::createDoctorPatient`)
- When a doctor creates a patient account in-clinic via `/doctor/patients`, the account defaults strictly to:
  - `consent_dataset = false`
  - `terms_accepted_at = null`
- **Rule**: Doctors are never permitted to toggle consent on behalf of a patient during clinic intake. The patient must log into their own portal account to review disclaimers and choose their consent preference.

---

## 4. Frontend UI & UX Conventions

### 1. Non-Blocking Scanners (`AppScanner.vue`, `Mobile/Scanner.vue`)
- **Strict Rule**: **NEVER** block the shutter button, scan button, or camera execution with a forced checkbox.
- Conflating general medical disclaimers with mandatory research donation creates unnecessary user friction.
- **Standard**: Display an informative, non-intrusive legal pill above the camera controls linking to the modal disclaimers:
  ```html
  <div class="flex items-center gap-2 rounded-2xl border border-white/20 bg-black/40 px-3.5 py-1.5 text-white shadow-2xl backdrop-blur-md">
    <Icon name="lucide:info" class="text-primary h-3.5 w-3.5 shrink-0" />
    <p class="text-[11px] sm:text-xs text-white/90 select-none leading-tight">
      AI results are assistive only.
      <button type="button" class="text-primary font-bold underline ml-0.5" @click="openTermsModal('terms')">Medical Disclaimer</button>
      &
      <button type="button" class="text-primary font-bold underline" @click="openTermsModal('privacy')">Privacy Policy</button>
    </p>
  </div>
  ```

### 2. Doctor Diagnosis Results (`DiagnosisFindingsDetailed.vue`)
- Evaluates `patientConsentedToDataset`:
  - If `true`: Renders the `"Include in AI Retraining Dataset"` checkbox (default checked).
  - If `false`: The checkbox is **completely hidden** and `contributeToDataset` is forced to `false`.
  - **Rule**: Do **NOT** render a "Patient declined" status badge on the doctor's view. Simply omit the toggle.

### 3. Patient Registration (`Auth/Register/index.vue`)
- Checkbox 1 (Mandatory): Terms & Conditions and Privacy Policy agreement (required to enable step progression).
- Checkbox 2 (Voluntary / Optional): AI Retraining Dataset Contribution (`consent_dataset`).

### 4. Patient Profile Settings (`Patient/Profile/index.vue`)
- Dedicated **"Data Privacy & Research"** settings card.
- Contains a clean switch toggle allowing patients to view and update their `consent_dataset` preference at any time. Updates are saved via `userService.update(uuid, form)`.
