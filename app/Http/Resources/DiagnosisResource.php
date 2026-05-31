<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class DiagnosisResource extends JsonResource
{
    /**
     * The "data" wrapper that should be applied.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'label' => $this->label,
            'confidence' => $this->confidence,
            'all_probabilities' => $this->probabilities,
            'image_url' => Storage::url($this->image_path),
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
