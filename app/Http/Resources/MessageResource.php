<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'conversation_id' => $this->relationLoaded('conversation') && $this->conversation ? $this->conversation->uuid : null,
            'sender' => $this->relationLoaded('sender') && $this->sender ? [
                'id' => $this->sender->uuid,
                'name' => trim($this->sender->first_name.' '.$this->sender->last_name),
                'avatar' => $this->sender->avatar_url,
            ] : null,
            'message' => $this->message,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
