<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('users', UserController::class);
    Route::apiResource('verifications', VerificationController::class);

    Route::apiResource('conversations', ConversationController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::apiResource('conversations.messages', MessageController::class)->shallow()->only(['index', 'store', 'update', 'destroy']);
});
