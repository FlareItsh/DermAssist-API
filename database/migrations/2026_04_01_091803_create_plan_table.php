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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tier_type')->default('individual'); // individual, doctor_multi_clinic, clinic_multi_doctor
            $table->decimal('price_monthly', 10, 2)->default(0.00);
            $table->decimal('price_annual', 10, 2)->default(0.00);
            $table->integer('max_doctors')->nullable(); // null = unlimited
            $table->integer('max_clinics')->nullable(); // null = unlimited
            $table->json('features')->nullable();
            $table->integer('trial_period_days')->default(0);
            $table->integer('grace_period_days')->default(3);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
