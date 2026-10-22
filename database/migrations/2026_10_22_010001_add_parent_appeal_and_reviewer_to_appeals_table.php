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
        Schema::table('appeals', function (Blueprint $table) {
            $table->foreignId('parent_appeal_id')->nullable()->after('diagnosis_id')->constrained('appeals')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('resubmission_count')->default(0)->after('parent_appeal_id');

            $table->index(['diagnosis_id', 'status']);
            $table->index('parent_appeal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->dropIndex(['diagnosis_id', 'status']);
            $table->dropIndex(['parent_appeal_id']);
            $table->dropForeign(['parent_appeal_id']);
            $table->dropForeign(['reviewer_id']);
            $table->dropColumn(['parent_appeal_id', 'resubmission_count', 'reviewer_id']);
        });
    }
};
