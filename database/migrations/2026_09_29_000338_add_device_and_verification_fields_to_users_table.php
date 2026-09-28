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
            $table->string('account_status', 30)->default('active')->change();
            $table->string('device_token', 100)->nullable()->after('account_status');
            $table->timestamp('cookies_accepted_at')->nullable()->after('device_token');
            $table->string('verification_token', 64)->nullable()->after('cookies_accepted_at');
            $table->timestamp('verification_deadline')->nullable()->after('verification_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'device_token',
                'cookies_accepted_at',
                'verification_token',
                'verification_deadline',
            ]);
            $table->enum('account_status', ['active', 'disabled'])->default('active')->change();
        });
    }
};
