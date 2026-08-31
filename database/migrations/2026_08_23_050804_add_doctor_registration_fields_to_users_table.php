<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_doctor_registered')->default(false)->after('avatar_path');
            $table->foreignId('registered_by_doctor_id')->nullable()->constrained('users')->nullOnDelete()->after('is_doctor_registered');
            $table->enum('account_status', ['active', 'disabled'])->default('active')->after('registered_by_doctor_id');
            $table->enum('account_action', ['delete', 'disable'])->nullable()->after('account_status');
            $table->timestamp('account_action_scheduled_at')->nullable()->after('account_action');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['registered_by_doctor_id']);
            $table->dropColumn([
                'is_doctor_registered',
                'registered_by_doctor_id',
                'account_status',
                'account_action',
                'account_action_scheduled_at',
            ]);
        });
    }
};
