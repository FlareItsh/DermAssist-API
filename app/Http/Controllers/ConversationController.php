<?php

namespace App\Http\Controllers;

use App\Service\ConversationService;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    private ConversationService $conversationService;

    public function __construct(ConversationService $conversationService)
    {
        $this->conversationService = $conversationService;
    }

    public function index(Request $request)
    {
        return $this->conversationService->listConversations(
            $request->user(),
            $request->input('per_page', 15)
        );
    }

    public function store(Request $request)
    {
        return $this->conversationService->startConversation($request->user(), $request->all());
    }

    public function show(Request $request, string $uuid)
    {
        return $this->conversationService->getConversation($request->user(), $uuid);
    }

    public function destroy(Request $request, string $uuid)
    {
        $this->conversationService->deleteConversation($request->user(), $uuid);

        return response()->json(['message' => 'Deleted successfully'], 200);
    }
}
