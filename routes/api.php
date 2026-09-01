<?php

use App\Http\Controllers\Admin\AdminCouponController;
use App\Http\Controllers\Admin\AdminFeatureController;
use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\AdminPlanController;
use App\Http\Controllers\Admin\AdminSubscriptionController;
use App\Http\Controllers\AppealController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClinicalNoteController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\DiagnosisController;
use App\Http\Controllers\DoctorAvailabilityController;
use App\Http\Controllers\DoctorClinicController;
use App\Http\Controllers\DoctorPatientController;
use App\Http\Controllers\DoctorSecretaryController;
use App\Http\Controllers\DoctorSubscriptionController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationController;
use App\Http\Middleware\CheckAccountStatus;
use App\Service\PaymentGatewayService;
use Illuminate\Http\Request;
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

    // Doctor Subscription Routes
    Route::get('/subscription/plans', [DoctorSubscriptionController::class, 'plans']);
    Route::get('/subscription/my-subscription', [DoctorSubscriptionController::class, 'mySubscription']);
    Route::post('/subscription/validate-coupon', [DoctorSubscriptionController::class, 'validateCoupon']);
    Route::post('/subscription/checkout', [DoctorSubscriptionController::class, 'checkout']);
    Route::post('/subscription/confirm-return-payment', function (Request $request, PaymentGatewayService $gatewayService) {
        $request->validate([
            'invoice_uuid' => 'required|string',
            'provider' => 'required|string',
        ]);

        return $gatewayService->confirmReturnPayment($request->input('invoice_uuid'), $request->input('provider'));
    });

    // Admin Subscription Management Routes
    Route::prefix('admin')->group(function () {
        Route::get('/subscriptions/dashboard', [AdminSubscriptionController::class, 'dashboard']);
        Route::get('/subscriptions', [AdminSubscriptionController::class, 'index']);

        Route::apiResource('plans', AdminPlanController::class);
        Route::patch('plans/{plan}/toggle-active', [AdminPlanController::class, 'toggleActive']);

        Route::apiResource('features', AdminFeatureController::class);
        Route::patch('features/{feature}/toggle-active', [AdminFeatureController::class, 'toggleActive']);

        Route::get('/payments', [AdminPaymentController::class, 'index']);
        Route::post('/payments/{invoice}/approve', [AdminPaymentController::class, 'approve']);
        Route::post('/payments/{invoice}/reject', [AdminPaymentController::class, 'reject']);

        Route::apiResource('coupons', AdminCouponController::class)->except(['update', 'show']);
        Route::patch('coupons/{coupon}/toggle-active', [AdminCouponController::class, 'toggleActive']);
    });

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

    // Doctor Clinics Routes
    Route::get('/doctor/clinics', [DoctorClinicController::class, 'index']);
    Route::post('/doctor/clinics', [DoctorClinicController::class, 'store']);
    Route::put('/doctor/clinics/{uuid}', [DoctorClinicController::class, 'update']);
    Route::delete('/doctor/clinics/{uuid}', [DoctorClinicController::class, 'destroy']);
});

// Unauthenticated Payment Webhooks
Route::post('/webhooks/paymongo', [PaymentWebhookController::class, 'handlePayMongo']);
Route::post('/webhooks/stripe', [PaymentWebhookController::class, 'handleStripe']);
