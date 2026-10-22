<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\Support\LiveChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Live chat" on Help & support (board resident-app-39, M50 §4.3): the account's own conversation with Gemini Security.
 * The account comes from the token and from nothing in the request; see App\Services\Support\LiveChat for who answers,
 * how, and what "Delivered" means.
 */
class LiveChatController extends Controller
{
    public function __construct(private readonly LiveChat $chat) {}

    public function show(DeviceContext $context): JsonResponse
    {
        return response()->json($this->chat->forResident($context->residentOrFail()));
    }

    public function store(Request $request, DeviceContext $context): JsonResponse
    {
        $account = $context->residentOrFail();

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.LiveChat::MAX_BODY],
            'client_key' => ['nullable', 'string', 'max:64'],
        ], [
            'body.required' => 'Write a message first.',
            'body.max' => 'That message is too long. Keep it under 1,000 characters, or send it in two.',
        ]);

        $message = $this->chat->fromResident($account, (string) $data['body'], $data['client_key'] ?? null);

        return response()->json([
            'message' => [
                'id' => (int) $message->id,
                'from' => 'you',
                'body' => (string) $message->body,
                'at' => $message->created_at->toIso8601String(),
                'seen_at' => $message->seen_at?->toIso8601String(),
                'client_key' => $message->client_key,
            ],
            'chat' => ['id' => (int) $message->support_chat_id, 'open' => true],
        ], 201);
    }
}
