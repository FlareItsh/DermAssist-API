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
        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('diagnosis_id')->nullable()->constrained('diagnoses')->nullOnDelete();
            
            // Subjective
            $table->text('history_of_present_illness')->nullable();
            $table->text('systemic_symptoms')->nullable();
            
            // Objective
            $table->text('physical_exam')->nullable();
            
            // Assessment
            $table->text('differential_diagnosis')->nullable();
            $table->string('final_diagnosis')->nullable();
            
            // Plan
            $table->text('prescription')->nullable();
            $table->text('patient_education')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->text('follow_up_instructions')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinical_notes');
    }
};
