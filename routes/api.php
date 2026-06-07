<?php

use App\Http\Controllers\AppealController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DiagnosisController;
use App\Http\Controllers\DoctorAvailabilityController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)->group(function () {
    Route::post('/login', 'login')->name('login');
    Route::post('/register', 'register');
});

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResources([
        'users' => UserController::class,
        'verifications' => VerificationController::class,
        'appointments' => AppointmentController::class,
        'diagnoses' => DiagnosisController::class,
    ]);
    Route::post('/diagnose', [DiagnosisController::class, 'store']);

    Route::apiResource('conversations', ConversationController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::apiResource('conversations.messages', MessageController::class)->shallow()->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('appeals', AppealController::class)->only(['index', 'store']);
    Route::apiResource('doctors.availabilities', DoctorAvailabilityController::class)
        ->shallow()
        ->only(['index', 'store', 'update', 'destroy']);
    Route::get('/doctors/{doctor}/availability-check', [DoctorAvailabilityController::class, 'check']);
    
    // Dataset Routes
    Route::get('/dataset', [\App\Http\Controllers\DatasetController::class, 'index']);
    Route::post('/dataset', [\App\Http\Controllers\DatasetController::class, 'store']);
    Route::delete('/dataset', [\App\Http\Controllers\DatasetController::class, 'destroy']);
    Route::get('/dataset/download', [\App\Http\Controllers\DatasetController::class, 'download']);
    Route::post('/dataset/save-diagnosis', [\App\Http\Controllers\DatasetController::class, 'saveFromDiagnosis']);

    // Appointments Extra Routes
    Route::get('/appointments/{uuid}', [\App\Http\Controllers\AppointmentController::class, 'show']);
    Route::post('/appointments/{uuid}/accept', [\App\Http\Controllers\AppointmentController::class, 'accept']);
    Route::post('/appointments/{uuid}/decline', [\App\Http\Controllers\AppointmentController::class, 'decline']);

    // Clinical Notes
    Route::get('/appointments/{uuid}/clinical-note', [\App\Http\Controllers\ClinicalNoteController::class, 'show']);
    Route::post('/appointments/{uuid}/clinical-note', [\App\Http\Controllers\ClinicalNoteController::class, 'store']);
});
