<?php

use App\Http\Controllers\AppealController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClinicalNoteController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DiagnosisController;
use App\Http\Controllers\DoctorAvailabilityController;
use App\Http\Controllers\DoctorPatientController;
use App\Http\Controllers\DoctorSecretaryController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationController;
use App\Http\Middleware\CheckAccountStatus;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)->group(function () {
    Route::post('/login', 'login')->name('login');
    Route::post('/register', 'register');
});

Route::post('/diagnose', [DiagnosisController::class, 'store']);

Route::middleware(['auth:sanctum', CheckAccountStatus::class])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResources([
        'users' => UserController::class,
        'verifications' => VerificationController::class,
        'appointments' => AppointmentController::class,
        'diagnoses' => DiagnosisController::class,
    ]);

    Route::apiResource('conversations', ConversationController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::apiResource('conversations.messages', MessageController::class)->shallow()->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('appeals', AppealController::class)->only(['index', 'store']);
    Route::apiResource('doctors.availabilities', DoctorAvailabilityController::class)
        ->shallow()
        ->only(['index', 'store', 'update', 'destroy']);
    Route::get('/doctors/{doctor}/availability-check', [DoctorAvailabilityController::class, 'check']);

    // Doctor Secretaries Management
    Route::get('/doctor/secretaries', [DoctorSecretaryController::class, 'index']);
    Route::post('/doctor/secretaries', [DoctorSecretaryController::class, 'store']);
    Route::delete('/doctor/secretaries/{uuid}', [DoctorSecretaryController::class, 'destroy']);

    // Dataset Routes
    Route::get('/dataset', [DatasetController::class, 'index']);
    Route::post('/dataset', [DatasetController::class, 'store']);
    Route::delete('/dataset', [DatasetController::class, 'destroy']);
    Route::get('/dataset/download', [DatasetController::class, 'download']);
    Route::post('/dataset/save-diagnosis', [DatasetController::class, 'saveFromDiagnosis']);

    // Appointments Extra Routes
    Route::post('/appointments/schedule-for-patient', [AppointmentController::class, 'scheduleForPatient']);
    Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::post('/appointments/{appointment}/accept', [AppointmentController::class, 'accept']);
    Route::post('/appointments/{appointment}/decline', [AppointmentController::class, 'decline']);
    Route::post('/appointments/{appointment}/propose-reschedule', [AppointmentController::class, 'proposeReschedule']);
    Route::post('/appointments/{appointment}/accept-reschedule', [AppointmentController::class, 'acceptReschedule']);

    // Clinical Notes
    Route::get('/appointments/{uuid}/clinical-note', [ClinicalNoteController::class, 'show']);
    Route::post('/appointments/{uuid}/clinical-note', [ClinicalNoteController::class, 'store']);
    Route::post('/diagnoses/{uuid}/clinical-note', [ClinicalNoteController::class, 'storeForDiagnosis']);

    // Unified Records Endpoint
    Route::get('/records', [RecordController::class, 'index']);

    // Doctor-Created Patients
    Route::get('/doctor/patients', [DoctorPatientController::class, 'index']);
    Route::post('/doctor/patients', [DoctorPatientController::class, 'store']);
    Route::post('/doctor/patients/{uuid}/enable', [DoctorPatientController::class, 'enable']);
    Route::post('/doctor/patients/{uuid}/disable', [DoctorPatientController::class, 'disable']);
    Route::delete('/doctor/patients/{uuid}', [DoctorPatientController::class, 'destroy']);
    Route::post('/doctor/patients/{uuid}/schedule-action', [DoctorPatientController::class, 'scheduleAction']);
    Route::delete('/doctor/patients/{uuid}/cancel-schedule', [DoctorPatientController::class, 'cancelSchedule']);
    Route::post('/doctor/patients/{uuid}/send-scan', [DoctorPatientController::class, 'sendScanResult']);
    Route::post('/doctor/patients/{uuid}/schedule-appointment', [DoctorPatientController::class, 'scheduleAppointment']);
});
