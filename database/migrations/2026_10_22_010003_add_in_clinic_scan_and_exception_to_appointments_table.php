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
            $table->foreignId('in_clinic_diagnosis_id')->nullable()->after('diagnosis_id')->constrained('diagnoses')->nullOnDelete();
            $table->text('scan_exception_reason')->nullable()->after('in_clinic_diagnosis_id');
            $table->string('scan_exception_category')->nullable()->after('scan_exception_reason');
            $table->timestamp('scan_exception_granted_at')->nullable()->after('scan_exception_category');
            $table->foreignId('scan_exception_doctor_id')->nullable()->after('scan_exception_granted_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['in_clinic_diagnosis_id']);
            $table->dropForeign(['scan_exception_doctor_id']);
            $table->dropColumn([
                'in_clinic_diagnosis_id',
                'scan_exception_reason',
                'scan_exception_category',
                'scan_exception_granted_at',
                'scan_exception_doctor_id',
            ]);
        });
    }
};
