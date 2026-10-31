<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InAppMessagingFeatureTest extends TestCase
{
    use DatabaseTransactions;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeConversation(User $a, User $b): Conversation
    {
        return Conversation::findOrCreateBetween($a->id, $b->id);
    }

    private function makeMessage(Conversation $conv, User $sender, array $attrs = []): ConversationMessage
    {
        return ConversationMessage::create(array_merge([
            'conversation_id' => $conv->id,
            'sender_id'       => $sender->id,
            'body'            => 'Hello there',
        ], $attrs));
    }

    // ── 1. Authentication required ─────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_conversations(): void
    {
        $this->getJson('/api/conversations')->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_create_conversation(): void
    {
        $this->postJson('/api/conversations', ['user_id' => 1])->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_view_messages(): void
    {
        $this->getJson('/api/conversations/1/messages')->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_send_message(): void
    {
        $this->postJson('/api/conversations/1/messages', ['body' => 'hi'])->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_mark_message_read(): void
    {
        $this->patchJson('/api/messages/1/read')->assertUnauthorized();
    }

    public function test_provider_can_discover_and_start_conversation_with_a_seeker(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $seeker = User::factory()->create(['role' => 'seeker']);

        $this->actingAs($provider, 'sanctum')
            ->getJson('/api/messaging-users?search=' . urlencode($seeker->name))
            ->assertOk()
            ->assertJsonFragment(['id' => $seeker->id, 'role' => 'seeker'])
            ->assertJsonMissing(['email' => $seeker->email])
            ->assertJsonMissing(['phone' => $seeker->phone]);

        $this->actingAs($provider, 'sanctum')
            ->postJson('/api/conversations', ['user_id' => $seeker->id])
            ->assertCreated()
            ->assertJsonPath('conversation.other_user.id', $seeker->id);
    }

    public function test_seeker_can_discover_and_start_conversation_with_a_provider(): void
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($seeker, 'sanctum')
            ->getJson('/api/messaging-users?search=' . urlencode($provider->name))
            ->assertOk()
            ->assertJsonFragment(['id' => $provider->id, 'role' => 'provider']);

        $this->actingAs($seeker, 'sanctum')
            ->postJson('/api/conversations', ['user_id' => $provider->id])
            ->assertCreated()
            ->assertJsonPath('conversation.other_user.id', $provider->id);
    }

    public function test_messaging_discovery_excludes_the_authenticated_user(): void
    {
        $alice = User::factory()->create(['role' => 'seeker']);

        $this->actingAs($alice, 'sanctum')
            ->getJson('/api/messaging-users?search=' . urlencode($alice->name))
            ->assertOk()
            ->assertJsonMissing(['id' => $alice->id]);
    }

    public function test_messaging_discovery_does_not_expose_private_contact_fields(): void
    {
        $alice = User::factory()->create(['role' => 'seeker']);
        $bob = User::factory()->create(['role' => 'provider']);

        $this->actingAs($alice, 'sanctum')
            ->getJson('/api/messaging-users?search=' . urlencode($bob->name))
            ->assertOk()
            ->assertJsonMissing(['email' => $bob->email])
            ->assertJsonMissing(['phone' => $bob->phone]);
    }

    // ── 2. User can create/find conversation ───────────────────────────────────

    public function test_user_can_start_conversation_with_another_user(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();

        $response = $this->actingAs($alice, 'sanctum')
            ->postJson('/api/conversations', ['user_id' => $bob->id]);

        $response->assertCreated()
            ->assertJsonStructure(['conversation' => ['id', 'other_user', 'last_message', 'unread_count']]);

        $this->assertDatabaseHas('conversations', [
            'user_a_id' => min($alice->id, $bob->id),
            'user_b_id' => max($alice->id, $bob->id),
        ]);
    }

    // ── 3. Self-conversation rejected ─────────────────────────────────────────

    public function test_self_conversation_is_rejected(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice, 'sanctum')
            ->postJson('/api/conversations', ['user_id' => $alice->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot start a conversation with yourself.');
    }

    // ── 4. Duplicate conversation prevented ───────────────────────────────────

    public function test_starting_existing_conversation_returns_existing(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();

        $first = $this->actingAs($alice, 'sanctum')
            ->postJson('/api/conversations', ['user_id' => $bob->id]);
        $firstId = $first->json('conversation.id');

        $second = $this->actingAs($alice, 'sanctum')
            ->postJson('/api/conversations', ['user_id' => $bob->id]);
        $secondId = $second->json('conversation.id');

        $this->assertSame($firstId, $secondId, 'Should return the existing conversation, not create a duplicate');
        $this->assertDatabaseCount('conversations', $this->databaseConversationCount());
    }

    /** Count conversations including those from other tests via DatabaseTransactions. */
    private function databaseConversationCount(): int
    {
        return Conversation::count();
    }

    public function test_reverse_start_returns_same_conversation(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();

        $fromAlice = $this->actingAs($alice, 'sanctum')
            ->postJson('/api/conversations', ['user_id' => $bob->id])
            ->json('conversation.id');

        $fromBob = $this->actingAs($bob, 'sanctum')
            ->postJson('/api/conversations', ['user_id' => $alice->id])
            ->json('conversation.id');

        $this->assertSame($fromAlice, $fromBob);
    }

    // ── 5. Only participants can view the conversation ─────────────────────────

    public function test_participant_can_view_their_conversations(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $this->makeConversation($alice, $bob);

        $this->actingAs($alice, 'sanctum')
            ->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'conversations');
    }

    public function test_conversation_list_is_paginated_with_default_page_size(): void
    {
        $alice = User::factory()->create();

        for ($i = 0; $i < 16; $i++) {
            $this->makeConversation($alice, User::factory()->create());
        }

        $response = $this->actingAs($alice, 'sanctum')
            ->getJson('/api/conversations');

        $response->assertOk()
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonStructure(['conversations', 'data', 'links', 'meta']);

        $this->assertCount(15, $response->json('conversations'));
    }

    public function test_conversation_list_accepts_valid_page_size(): void
    {
        $alice = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->makeConversation($alice, User::factory()->create());
        }

        $this->actingAs($alice, 'sanctum')
            ->getJson('/api/conversations?per_page=2')
            ->assertOk()
            ->assertJsonPath('per_page', 2);
    }

    public function test_invalid_conversation_page_size_is_rejected(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice, 'sanctum')
            ->getJson('/api/conversations?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_non_participant_does_not_see_conversation(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $carol = User::factory()->create();
        $this->makeConversation($alice, $bob);

        $data = $this->actingAs($carol, 'sanctum')
            ->getJson('/api/conversations')
            ->assertOk()
            ->json('conversations');

        $ids = array_column($data, 'id');
        // Carol should not see Alice-Bob conversation
        $aliceBobConv = Conversation::findOrCreateBetween($alice->id, $bob->id);
        $this->assertNotContains($aliceBobConv->id, $ids);
    }

    public function test_conversation_list_does_not_leak_other_users_conversations_across_pages(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $carol = User::factory()->create();
        $aliceBob = $this->makeConversation($alice, $bob);
        $this->makeConversation($carol, User::factory()->create());

        $response = $this->actingAs($carol, 'sanctum')
            ->getJson('/api/conversations?per_page=1');

        $response->assertOk();
        $this->assertNotSame($aliceBob->id, $response->json('conversations.0.id'));
    }

    // ── 6. Only participants can view messages ─────────────────────────────────

    public function test_participant_can_view_messages(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);
        $this->makeMessage($conv, $alice);

        $this->actingAs($bob, 'sanctum')
            ->getJson("/api/conversations/{$conv->id}/messages")
            ->assertOk()
            ->assertJsonStructure(['data', 'pagination']);
    }

    public function test_non_participant_cannot_view_messages(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $carol = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);

        $this->actingAs($carol, 'sanctum')
            ->getJson("/api/conversations/{$conv->id}/messages")
            ->assertForbidden();
    }

    // ── 7. Participant can send message ───────────────────────────────────────

    public function test_participant_can_send_message(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);

        $this->actingAs($alice, 'sanctum')
            ->postJson("/api/conversations/{$conv->id}/messages", ['body' => 'Hey Bob!'])
            ->assertCreated()
            ->assertJsonStructure(['message' => ['id', 'conversation_id', 'sender_id', 'body', 'created_at']]);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conv->id,
            'sender_id'       => $alice->id,
            'body'            => 'Hey Bob!',
        ]);
    }

    public function test_new_message_notifies_only_the_recipient_once(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $conv = $this->makeConversation($alice, $bob);

        $response = $this->actingAs($alice, 'sanctum')
            ->postJson("/api/conversations/{$conv->id}/messages", ['body' => 'Hello Bob']);

        $response->assertCreated();
        $messageId = $response->json('message.id');
        $notification = Notification::where('user_id', $bob->id)->where('type', 'message.received')->first();

        $this->assertNotNull($notification);
        $this->assertSame($conv->id, $notification->data['conversation_id']);
        $this->assertSame($messageId, $notification->data['message_id']);
        $this->assertSame('/messages?conversation='.$conv->id, $notification->data['link']);
        $this->assertSame(0, Notification::where('user_id', $alice->id)->where('type', 'message.received')->count());

        $this->actingAs($alice, 'sanctum')
            ->postJson("/api/conversations/{$conv->id}/messages", ['body' => 'Another message'])
            ->assertCreated();

        $this->assertSame(2, Notification::where('user_id', $bob->id)->where('type', 'message.received')->count());
    }

    // ── 8. Sender is always the authenticated user ─────────────────────────────

    public function test_sender_is_always_authenticated_user_not_arbitrary(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);

        // Even if someone tries to spoof sender_id in the body, it's ignored
        $response = $this->actingAs($alice, 'sanctum')
            ->postJson("/api/conversations/{$conv->id}/messages", [
                'body'      => 'Hello',
                'sender_id' => $bob->id, // attempted spoof
            ]);

        $response->assertCreated();
        $savedSenderId = $response->json('message.sender_id');
        $this->assertSame($alice->id, $savedSenderId, 'sender_id must always be the authenticated user');
    }

    // ── 9. Empty message rejected ─────────────────────────────────────────────

    public function test_empty_body_is_rejected(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);

        $this->actingAs($alice, 'sanctum')
            ->postJson("/api/conversations/{$conv->id}/messages", ['body' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    }

    public function test_whitespace_only_body_is_rejected(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);

        $this->actingAs($alice, 'sanctum')
            ->postJson("/api/conversations/{$conv->id}/messages", ['body' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    }

    public function test_missing_body_is_rejected(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);

        $this->actingAs($alice, 'sanctum')
            ->postJson("/api/conversations/{$conv->id}/messages", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    }

    // ── 10. Non-participant cannot send ───────────────────────────────────────

    public function test_non_participant_cannot_send_message(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $carol = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);

        $this->actingAs($carol, 'sanctum')
            ->postJson("/api/conversations/{$conv->id}/messages", ['body' => 'intruder!'])
            ->assertForbidden();
    }

    // ── 11. Pagination ────────────────────────────────────────────────────────

    public function test_messages_are_paginated(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);

        for ($i = 0; $i < 5; $i++) {
            $this->makeMessage($conv, $alice, ['body' => "Message $i"]);
        }

        $response = $this->actingAs($alice, 'sanctum')
            ->getJson("/api/conversations/{$conv->id}/messages?per_page=3");

        $response->assertOk()
            ->assertJsonPath('pagination.per_page', 3)
            ->assertJsonPath('pagination.current_page', 1);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_pagination_second_page(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);

        for ($i = 0; $i < 5; $i++) {
            $this->makeMessage($conv, $alice, ['body' => "Message $i"]);
        }

        $response = $this->actingAs($alice, 'sanctum')
            ->getJson("/api/conversations/{$conv->id}/messages?per_page=3&page=2");

        $response->assertOk()
            ->assertJsonPath('pagination.current_page', 2);

        $this->assertCount(2, $response->json('data'));
    }

    // ── 12. Read-state authorization ──────────────────────────────────────────

    public function test_recipient_can_mark_message_read(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);
        $msg   = $this->makeMessage($conv, $alice); // alice sends, bob receives

        $this->assertNull($msg->read_at);

        $response = $this->actingAs($bob, 'sanctum')
            ->patchJson("/api/messages/{$msg->id}/read");

        $response->assertOk()
            ->assertJsonPath('message.is_read', true);

        $this->assertNotNull(ConversationMessage::find($msg->id)->read_at);
    }

    public function test_sender_cannot_mark_own_message_as_read(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);
        $msg   = $this->makeMessage($conv, $alice);

        $this->actingAs($alice, 'sanctum')
            ->patchJson("/api/messages/{$msg->id}/read")
            ->assertUnprocessable();
    }

    public function test_non_participant_cannot_mark_message_read(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $carol = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);
        $msg   = $this->makeMessage($conv, $alice);

        $this->actingAs($carol, 'sanctum')
            ->patchJson("/api/messages/{$msg->id}/read")
            ->assertForbidden();
    }

    public function test_marking_read_is_idempotent(): void
    {
        $alice  = User::factory()->create();
        $bob    = User::factory()->create();
        $conv   = $this->makeConversation($alice, $bob);
        $readAt = now()->subHour();
        $msg    = $this->makeMessage($conv, $alice, ['read_at' => $readAt]);

        $this->actingAs($bob, 'sanctum')
            ->patchJson("/api/messages/{$msg->id}/read")
            ->assertOk();

        // read_at should not change on a second mark
        $this->assertEquals(
            $readAt->toDateTimeString(),
            ConversationMessage::find($msg->id)->read_at->toDateTimeString()
        );
    }

    // ── Unread count ──────────────────────────────────────────────────────────

    public function test_conversation_list_shows_unread_count(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $conv  = $this->makeConversation($alice, $bob);

        // Bob sends 2 unread messages to Alice
        $this->makeMessage($conv, $bob, ['body' => 'Msg 1']);
        $this->makeMessage($conv, $bob, ['body' => 'Msg 2']);
        // Alice sends 1 (doesn't count as unread for Alice)
        $this->makeMessage($conv, $alice, ['body' => 'Msg 3']);

        $response = $this->actingAs($alice, 'sanctum')
            ->getJson('/api/conversations');

        $response->assertOk();
        $conversations = $response->json('conversations');
        $target = collect($conversations)->firstWhere('id', $conv->id);

        $this->assertSame(2, $target['unread_count']);
    }
}
