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
        Schema::table('diagnoses', function (Blueprint $table) {
            // CLIP-detected out-of-scope disease label (e.g. 'psoriasis', 'ringworm', 'vitiligo')
            $table->string('out_of_scope_category')->nullable()->after('contributed_at');
            // Whether this diagnosis was flagged as an out-of-scope skin condition
            $table->boolean('is_out_of_scope')->default(false)->after('out_of_scope_category');
            // Tracks when the out-of-scope scan image was contributed to the research dataset
            $table->boolean('out_of_scope_contributed')->default(false)->after('is_out_of_scope');
            $table->timestamp('out_of_scope_contributed_at')->nullable()->after('out_of_scope_contributed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('diagnoses', function (Blueprint $table) {
            $table->dropColumn([
                'out_of_scope_category',
                'is_out_of_scope',
                'out_of_scope_contributed',
                'out_of_scope_contributed_at',
            ]);
        });
    }
};
