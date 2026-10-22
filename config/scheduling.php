<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Appointment Attendance Grace Period
    |--------------------------------------------------------------------------
    |
    | Number of minutes after the scheduled appointment start time before
    | the appointment can be reviewed and manually marked as a no-show
    | by an authorized doctor or secretary.
    |
    */
    'no_show_grace_minutes' => env('APPOINTMENT_NO_SHOW_GRACE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Default Appointment Duration
    |--------------------------------------------------------------------------
    |
    | Default consultation duration in minutes if not specified explicitly.
    |
    */
    'default_duration_minutes' => env('APPOINTMENT_DEFAULT_DURATION_MINUTES', 60),
];
