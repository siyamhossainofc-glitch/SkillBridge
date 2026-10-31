<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Only expose safe, consumer-facing fields.
     * Omits: user_id, updated_at, and any internal model state.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'type'       => $this->type,
            'title'      => $this->title,
            'body'       => $this->body,
            'data'       => $this->data,
            'read_at'    => $this->read_at?->toIso8601String(),
            'is_read'    => $this->isRead(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
