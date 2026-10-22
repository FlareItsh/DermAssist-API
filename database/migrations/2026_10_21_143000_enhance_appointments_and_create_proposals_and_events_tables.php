<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('accepted_by_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable()->after('accepted_by_id');
            $table->foreignId('cancelled_by_id')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable()->after('cancelled_by_id');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->foreignId('no_show_by_id')->nullable()->after('cancellation_reason')->constrained('users')->nullOnDelete();
            $table->dateTime('no_show_at')->nullable()->after('no_show_by_id');
            $table->string('no_show_reason')->nullable()->after('no_show_at');
            $table->dateTime('checked_in_at')->nullable()->after('no_show_reason');
            $table->dateTime('consultation_started_at')->nullable()->after('checked_in_at');
            $table->foreignId('clinic_id')->nullable()->after('consultation_started_at')->constrained('clinics')->nullOnDelete();
            $table->string('appointment_source')->default('direct')->after('clinic_id');

            $table->index(['doctor_id', 'scheduled_at', 'status']);
            $table->index(['patient_id', 'scheduled_at', 'status']);
        });

        Schema::create('appointment_reschedule_proposals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('proposer_id')->constrained('users')->cascadeOnDelete();
            $table->string('proposer_type'); // 'doctor', 'patient', 'secretary'
            $table->dateTime('proposed_start_at');
            $table->dateTime('proposed_end_at')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('pending'); // 'pending', 'accepted', 'declined', 'withdrawn', 'conflict', 'expired'
            $table->foreignId('responder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('responded_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['appointment_id', 'status']);
        });

        Schema::create('appointment_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['appointment_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_events');
        Schema::dropIfExists('appointment_reschedule_proposals');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['doctor_id', 'scheduled_at', 'status']);
            $table->dropIndex(['patient_id', 'scheduled_at', 'status']);

            $table->dropForeign(['accepted_by_id']);
            $table->dropForeign(['cancelled_by_id']);
            $table->dropForeign(['no_show_by_id']);
            $table->dropForeign(['clinic_id']);

            $table->dropColumn([
                'accepted_by_id',
                'confirmed_at',
                'cancelled_by_id',
                'cancelled_at',
                'cancellation_reason',
                'no_show_by_id',
                'no_show_at',
                'no_show_reason',
                'checked_in_at',
                'consultation_started_at',
                'clinic_id',
                'appointment_source',
            ]);
        });
    }
};
