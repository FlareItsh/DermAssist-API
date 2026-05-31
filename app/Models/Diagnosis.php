<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['uuid', 'user_uuid', 'image_path', 'label', 'confidence', 'probabilities', 'status'])]
class Diagnosis extends Model
{
    use HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'probabilities' => 'array',
            'confidence' => 'float',
        ];
    }

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
