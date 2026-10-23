<?php

return [
    'free_daily_limit' => (int) env('SCANS_FREE_DAILY_LIMIT', 5),
    'window_hours' => (int) env('SCANS_WINDOW_HOURS', 24),
    'care_unlimited_days' => (int) env('SCANS_CARE_UNLIMITED_DAYS', 7),
];
