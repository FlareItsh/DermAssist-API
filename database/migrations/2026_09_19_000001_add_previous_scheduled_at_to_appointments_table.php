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
            $table->dateTime('previous_scheduled_at')->nullable()->after('scheduled_end_at');
            $table->dateTime('previous_scheduled_end_at')->nullable()->after('previous_scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['previous_scheduled_at', 'previous_scheduled_end_at']);
        });
    }
};
