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
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('scan_window_started_at')->nullable()->after('remember_token');
            $table->unsignedSmallInteger('scan_window_count')->default(0)->after('scan_window_started_at');
            $table->timestamp('unlimited_scans_until')->nullable()->after('scan_window_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'scan_window_started_at',
                'scan_window_count',
                'unlimited_scans_until',
            ]);
        });
    }
};
