<?php

return [
    'window_hours' => (int) env('SCANS_WINDOW_HOURS', 24),
    'patient_default_daily_scans' => (int) env('SCANS_PATIENT_DEFAULT', 5),
    'patient_care_daily_scans' => (int) env('SCANS_PATIENT_CARE', 15),
    'patient_care_duration_days' => (int) env('SCANS_PATIENT_CARE_DURATION', 30),
    'doctor_trial_days' => (int) env('SCANS_DOCTOR_TRIAL_DAYS', 14),
    'doctor_trial_daily_scans' => (int) env('SCANS_DOCTOR_TRIAL_SCANS', 10),
    'doctor_unsubscribed_daily_scans' => (int) env('SCANS_DOCTOR_UNSUB_SCANS', 3),
];
