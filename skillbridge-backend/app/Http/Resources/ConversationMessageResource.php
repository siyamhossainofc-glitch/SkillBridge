<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id'       => $this->sender_id,
            'sender_name'     => $this->sender?->name,
            'body'            => $this->body,
            'read_at'         => $this->read_at,
            'is_read'         => $this->read_at !== null,
            'created_at'      => $this->created_at,
        ];
    }
}
