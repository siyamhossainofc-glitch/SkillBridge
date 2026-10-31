<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = [
        'user_a_id',
        'user_b_id',
    ];

    /**
     * Find or create a conversation between two users.
     * Always stores with lower id as user_a to enforce the unique constraint.
     */
    public static function findOrCreateBetween(int $userA, int $userB): self
    {
        [$low, $high] = $userA < $userB ? [$userA, $userB] : [$userB, $userA];

        return self::firstOrCreate(
            ['user_a_id' => $low, 'user_b_id' => $high]
        );
    }

    /**
     * Check whether a given user participates in this conversation.
     */
    public function hasParticipant(int $userId): bool
    {
        return $this->user_a_id === $userId || $this->user_b_id === $userId;
    }

    /**
     * Return the other participant's id given one participant.
     */
    public function otherUserId(int $userId): int
    {
        return $this->user_a_id === $userId ? $this->user_b_id : $this->user_a_id;
    }

    public function userA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    public function userB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }

    public function conversationMessages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class, 'conversation_id')->latestOfMany('created_at');
    }
}
