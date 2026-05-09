<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->file_name,
            'url' => $this->url,
            'type' => $this->file_type,
            'size' => $this->file_size,
            'created_at' => $this->created_at,
        ];
    }
}
