<?php

use App\Http\Controllers\AppealController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DiagnosisController;
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

    Route::apiResource('conversations', ConversationController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::apiResource('conversations.messages', MessageController::class)->shallow()->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('appeals', AppealController::class)->only(['index', 'store']);
});
