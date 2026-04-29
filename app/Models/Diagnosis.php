<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Diagnosis extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'user_uuid',
        'image_path',
        'label',
        'confidence',
        'probabilities',
        'status',
    ];

    protected $casts = [
        'probabilities' => 'array',
        'confidence' => 'float',
    ];

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Specify the UUID column name.
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }
}
