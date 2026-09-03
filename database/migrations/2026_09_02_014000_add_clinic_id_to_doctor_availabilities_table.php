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
        Schema::table('doctor_availabilities', function (Blueprint $table) {
            if (! Schema::hasColumn('doctor_availabilities', 'clinic_id')) {
                $table->foreignId('clinic_id')->nullable()->after('doctor_id')->constrained('clinics')->nullOnDelete();
            }
            if (! Schema::hasColumn('doctor_availabilities', 'location_name')) {
                $table->string('location_name')->nullable()->after('clinic_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctor_availabilities', function (Blueprint $table) {
            if (Schema::hasColumn('doctor_availabilities', 'clinic_id')) {
                $table->dropConstrainedForeignId('clinic_id');
            }
            if (Schema::hasColumn('doctor_availabilities', 'location_name')) {
                $table->dropColumn('location_name');
            }
        });
    }
};
