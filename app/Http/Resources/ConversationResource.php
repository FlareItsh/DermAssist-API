<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $latestMessage = $this->relationLoaded('latestMessage') ? $this->latestMessage : $this->messages()->latest()->first();

        return [
            'id' => $this->uuid,
            'doctor' => $this->relationLoaded('doctor') && $this->doctor ? [
                'id' => $this->doctor->uuid,
                'name' => trim($this->doctor->first_name.' '.$this->doctor->last_name),
                'avatar' => $this->doctor->avatar_url,
            ] : null,
            'patient' => $this->relationLoaded('patient') && $this->patient ? [
                'id' => $this->patient->uuid,
                'name' => trim($this->patient->first_name.' '.$this->patient->last_name),
                'avatar' => $this->patient->avatar_url,
            ] : null,
            'latest_message' => $latestMessage ? [
                'message' => $latestMessage->message,
                'sender_id' => $latestMessage->sender?->uuid,
                'created_at' => $latestMessage->created_at,
            ] : null,
            'unread_count' => isset($this->unread_messages_count)
                ? (int) $this->unread_messages_count
                : ($request->user() ? (int) $this->messages()->where('sender_id', '!=', $request->user()->id)->where('is_read', false)->count() : 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
