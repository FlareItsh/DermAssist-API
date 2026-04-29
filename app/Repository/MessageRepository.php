<?php

namespace App\Repository;

use App\Models\Message;

class MessageRepository
{
    public function paginateForConversation(int $conversationId, int $perPage = 15)
    {
        return Message::with('sender')
            ->where('conversation_id', $conversationId)
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Message::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Message::where('uuid', $uuid)->firstOrFail();
    }

    public function update(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);

        return $model;
    }

    public function updateQuietly(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->updateQuietly($payload);

        return $model;
    }

    public function delete(string $uuid): bool
    {
        $model = $this->findByUuid($uuid);

        return $model->delete();
    }
}
