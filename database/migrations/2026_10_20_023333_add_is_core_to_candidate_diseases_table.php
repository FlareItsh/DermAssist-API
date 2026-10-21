<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('candidate_diseases', function (Blueprint $table) {
            $table->boolean('is_core')->default(false)->after('is_default');
        });

        $coreDiseases = [
            [
                'name' => 'Acne',
                'slug' => 'acne',
                'category' => 'Sebaceous / Bacterial',
                'description' => 'Inflammatory and comedonal papules, pustules, and cysts.',
                'badge' => 'Core Model',
                'icon' => 'lucide:flame',
                'is_core' => true,
                'is_default' => true,
            ],
            [
                'name' => 'Eczema',
                'slug' => 'eczema',
                'category' => 'Inflammatory Dermatitis',
                'description' => 'Pruritic, erythematous, dry, and scaly skin lesions.',
                'badge' => 'Core Model',
                'icon' => 'lucide:activity',
                'is_core' => true,
                'is_default' => true,
            ],
            [
                'name' => 'Herpes',
                'slug' => 'herpes',
                'category' => 'Viral Infection',
                'description' => 'Clustered fluid-filled vesicles on an erythematous base.',
                'badge' => 'Core Model',
                'icon' => 'lucide:shield-alert',
                'is_core' => true,
                'is_default' => true,
            ],
            [
                'name' => 'Clear',
                'slug' => 'clear',
                'category' => 'Normal / Healthy',
                'description' => 'No active dermatological lesion or pathological irregularity detected.',
                'badge' => 'Healthy Skin',
                'icon' => 'lucide:check-circle-2',
                'is_core' => true,
                'is_default' => true,
            ],
        ];

        foreach ($coreDiseases as $core) {
            DB::table('candidate_diseases')->insertOrIgnore([
                'uuid' => (string) Str::uuid(),
                'name' => $core['name'],
                'slug' => $core['slug'],
                'category' => $core['category'],
                'description' => $core['description'],
                'badge' => $core['badge'],
                'icon' => $core['icon'],
                'is_core' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('candidate_diseases')->whereIn('slug', ['acne', 'eczema', 'herpes', 'clear'])->delete();

        Schema::table('candidate_diseases', function (Blueprint $table) {
            $table->dropColumn('is_core');
        });
    }
};
