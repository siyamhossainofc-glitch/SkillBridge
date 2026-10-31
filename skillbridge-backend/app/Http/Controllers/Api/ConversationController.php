<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationMessageResource;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    // ── GET /api/messaging-users ──────────────────────────────────────────────

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $query = \App\Models\User::query()
            ->where('id', '!=', $request->user()->id)
            ->whereIn('role', ['provider', 'seeker'])
            ->select(['id', 'name', 'role', 'professional_title', 'bio', 'address']);

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('professional_title', 'like', "%{$search}%")
                    ->orWhere('bio', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('name')->paginate($validated['per_page'] ?? 12)->withQueryString());
    }

    // ── GET /api/conversations ─────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $userId = $request->user()->id;
        $perPage = $validated['per_page'] ?? 15;

        // All conversation data needed by the resource is loaded in bounded queries:
        // participant users, latest message, and unread count. No per-row queries.
        $paginator = Conversation::query()
            ->where(function ($query) use ($userId) {
                $query->where('user_a_id', $userId)
                    ->orWhere('user_b_id', $userId);
            })
            ->with(['userA:id,name', 'userB:id,name', 'latestMessage'])
            ->withCount([
                'conversationMessages as unread_count' => function ($query) use ($userId) {
                    $query->where('sender_id', '!=', $userId)
                        ->whereNull('read_at');
                },
            ])
            ->orderByDesc(
                ConversationMessage::query()
                    ->select('created_at')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->latest()
                    ->limit(1)
            )
            ->orderByDesc('conversations.updated_at')
            ->paginate($perPage)
            ->withQueryString();

        $conversations = ConversationResource::collection($paginator->getCollection())->resolve($request);
        $payload = $paginator->toArray();
        $payload['meta'] = array_intersect_key($payload, array_flip([
            'current_page',
            'from',
            'last_page',
            'per_page',
            'to',
            'total',
        ]));

        // Keep the Task 6B response key used by the existing frontend while also
        // exposing Laravel's normal paginator data/meta/links fields.
        $payload['conversations'] = $conversations;
        $payload['data'] = $conversations;

        return response()->json($payload);
    }

    // ── POST /api/conversations ────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $authUser = $request->user();
        $otherId = (int) $validated['user_id'];

        if ($otherId === $authUser->id) {
            return response()->json(['message' => 'You cannot start a conversation with yourself.'], 422);
        }

        $conversation = Conversation::findOrCreateBetween($authUser->id, $otherId);

        $conversation->load(['userA:id,name', 'userB:id,name', 'latestMessage']);
        $conversation->unread_count = $conversation->conversationMessages()
            ->where('sender_id', '!=', $authUser->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'conversation' => (new ConversationResource($conversation))->resolve($request),
        ], 201);
    }

    // ── GET /api/conversations/{id}/messages ───────────────────────────────────

    public function messages(Request $request, int $id): JsonResponse
    {
        $conversation = Conversation::find($id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $userId = $request->user()->id;

        if (! $conversation->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $perPage = $validated['per_page'] ?? 30;

        $paginator = $conversation->conversationMessages()
            ->with('sender:id,name')
            ->orderBy('created_at', 'asc')
            ->paginate($perPage);

        return response()->json([
            'data' => ConversationMessageResource::collection($paginator->items())->resolve($request),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    // ── POST /api/conversations/{id}/messages ─────────────────────────────────

    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $conversation = Conversation::find($id);

        if (! $conversation) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        $userId = $request->user()->id;

        if (! $conversation->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'body' => 'required|string|min:1|max:5000',
        ]);

        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userId,
            'body' => $validated['body'],
        ]);

        $message->load('sender:id,name');

        $recipientId = $conversation->otherUserId($userId);
        Notification::createOnce(
            $recipientId,
            'message.received',
            'New message received',
            "You have a new message from {$request->user()->name}.",
            ['conversation_id' => $conversation->id, 'message_id' => $message->id, 'link' => "/messages?conversation={$conversation->id}"]
        );

        return response()->json([
            'message' => (new ConversationMessageResource($message))->resolve($request),
        ], 201);
    }

    // ── PATCH /api/messages/{id}/read ─────────────────────────────────────────

    public function markRead(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;
        $message = ConversationMessage::find($id);

        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $conversation = Conversation::find($message->conversation_id);

        if (! $conversation || ! $conversation->hasParticipant($userId)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($message->sender_id === $userId) {
            return response()->json(['message' => 'You cannot mark your own sent message as read.'], 422);
        }

        if (is_null($message->read_at)) {
            $message->update(['read_at' => now()]);
        }

        $message->load('sender:id,name');

        return response()->json([
            'message' => (new ConversationMessageResource($message))->resolve($request),
        ]);
    }
}
