<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;



Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('users', UserController::class);
    Route::apiResource('verifications', VerificationController::class);

    Route::post('/diagnose', [\App\Http\Controllers\DiagnosisController::class, 'diagnose']);
    Route::get('/appeals', [\App\Http\Controllers\AppealController::class, 'index']);
    Route::post('/appeals', [\App\Http\Controllers\AppealController::class, 'store']);
});
