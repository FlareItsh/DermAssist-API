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
        return $this->messageService->markAsRead($request->user(), $message);
    }
}
