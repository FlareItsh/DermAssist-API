<?php

namespace App\Http\Controllers;

use App\Service\MessageService;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    private MessageService $messageService;

    public function __construct(MessageService $messageService)
    {
        $this->messageService = $messageService;
    }

    public function index(Request $request, string $conversation)
    {
        return $this->messageService->listMessages(
            $request->user(),
            $conversation,
            $request->input('per_page', 15)
        );
    }

    public function store(Request $request, string $conversation)
    {
        return $this->messageService->sendMessage($request->user(), $conversation, $request->all());
    }

    public function update(Request $request, string $message)
    {
        if ($request->has('message')) {
            return $this->messageService->editMessage($request->user(), $message, $request->all());
        }

        return $this->messageService->markAsRead($request->user(), $message);
    }

    public function destroy(Request $request, string $message)
    {
        $this->messageService->deleteMessage($request->user(), $message);

        return response()->json(['message' => 'Message deleted successfully'], 200);
    }
}
