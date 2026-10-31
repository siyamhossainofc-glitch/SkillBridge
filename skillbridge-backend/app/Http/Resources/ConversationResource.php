<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $userId = $request->user()->id;

        // The "other" participant
        $otherUser = $this->user_a_id === $userId ? $this->userB : $this->userA;

        // Last message and unread count — pre-loaded via withLastMessage / withUnreadCount
        $lastMsg    = $this->latestMessage ?? null;
        $unreadCount = $this->unread_count ?? 0;

        return [
            'id'              => $this->id,
            'other_user'      => $otherUser ? [
                'id'   => $otherUser->id,
                'name' => $otherUser->name,
            ] : null,
            'last_message'    => $lastMsg?->body,
            'last_message_at' => $lastMsg?->created_at,
            'unread_count'    => (int) $unreadCount,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}
