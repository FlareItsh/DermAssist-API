<?php

namespace App\Service;

use App\Http\Resources\MessageResource;
use App\Models\User;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use Illuminate\Validation\ValidationException;

class MessageService
{
    private MessageRepository $messageRepository;

    private ConversationRepository $conversationRepository;

    public function __construct(
        MessageRepository $messageRepository,
        ConversationRepository $conversationRepository
    ) {
        $this->messageRepository = $messageRepository;
        $this->conversationRepository = $conversationRepository;
    }

    private function getValidConversation(User $user, string $conversationUuid)
    {
        $conversation = $this->conversationRepository->findByUuid($conversationUuid);
        if ($conversation->doctor_id !== $user->id && $conversation->patient_id !== $user->id) {
            abort(403, 'Unauthorized access to this conversation.');
        }

        return $conversation;
    }

    public function listMessages(User $user, string $conversationUuid, int $perPage = 15)
    {
        $conversation = $this->getValidConversation($user, $conversationUuid);
        $collection = $this->messageRepository->paginateForConversation($conversation->id, $perPage);

        return MessageResource::collection($collection);
    }

    public function sendMessage(User $user, string $conversationUuid, array $payload)
    {
        $conversation = $this->getValidConversation($user, $conversationUuid);

        if (empty($payload['message'])) {
            throw ValidationException::withMessages(['message' => 'Message content is required.']);
        }

        $model = $this->messageRepository->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'message' => $payload['message'],
        ]);

        $conversation->touch();

        $model->load('sender');

        return new MessageResource($model);
    }

    public function markAsRead(User $user, string $messageUuid)
    {
        $message = $this->messageRepository->findByUuid($messageUuid);

        $conversation = $message->conversation;
        if ($conversation->doctor_id !== $user->id && $conversation->patient_id !== $user->id) {
            abort(403, 'Unauthorized access to this message.');
        }

        if ($message->sender_id === $user->id) {
            abort(403, 'You cannot mark your own message as read.');
        }

        if (! $message->is_read) {
            $message = $this->messageRepository->update($messageUuid, [
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return new MessageResource($message);
    }
}
