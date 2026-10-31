<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NotificationFeatureTest extends TestCase
{
    use DatabaseTransactions;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeNotification(User $user, array $attrs = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'type'    => 'test.notification',
            'title'   => 'Test Notification',
            'body'    => 'This is a test notification body.',
            'data'    => ['key' => 'value'],
        ], $attrs));
    }

    // ── Auth guard ─────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_notifications(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_get_unread_count(): void
    {
        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_mark_read(): void
    {
        $this->patchJson('/api/notifications/1/read')->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_mark_all_read(): void
    {
        $this->patchJson('/api/notifications/read-all')->assertUnauthorized();
    }

    // ── Listing ────────────────────────────────────────────────────────────────

    public function test_user_can_list_own_notifications(): void
    {
        $user = User::factory()->create();
        $this->makeNotification($user, ['title' => 'Hello User']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonStructure(['data', 'pagination'])
            ->assertJsonPath('data.0.title', 'Hello User');
    }

    public function test_notifications_are_returned_newest_first(): void
    {
        $user = User::factory()->create();
        $this->makeNotification($user, ['title' => 'First']);
        sleep(1); // ensure distinct created_at
        $this->makeNotification($user, ['title' => 'Second']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('Second', $data[0]['title']);
        $this->assertSame('First', $data[1]['title']);
    }

    public function test_user_cannot_see_another_users_notifications(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();
        $this->makeNotification($alice, ['title' => 'Alice Private']);

        $response = $this->actingAs($bob, 'sanctum')->getJson('/api/notifications');

        $response->assertOk();
        $titles = array_column($response->json('data'), 'title');
        $this->assertNotContains('Alice Private', $titles);
    }

    public function test_pagination_structure_is_present(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->makeNotification($user, ['title' => "Notification $i"]);
        }

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?per_page=2');

        $response->assertOk()
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.current_page', 1);

        $this->assertCount(2, $response->json('data'));
    }

    // ── per_page validation ────────────────────────────────────────────────────

    public function test_non_integer_per_page_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications?per_page=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_zero_per_page_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_negative_per_page_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications?per_page=-5')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_per_page_above_max_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_per_page_at_max_boundary_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications?per_page=50')
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 50);
    }

    public function test_default_per_page_is_15(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

        $response->assertOk()->assertJsonPath('pagination.per_page', 15);
    }

    // ── Response field shape ───────────────────────────────────────────────────

    public function test_response_contains_required_fields(): void
    {
        $user = User::factory()->create();
        $this->makeNotification($user);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

        $response->assertOk()->assertJsonStructure([
            'data' => [
                '*' => ['id', 'type', 'title', 'body', 'data', 'read_at', 'is_read', 'created_at'],
            ],
        ]);
    }

    public function test_response_does_not_expose_internal_fields(): void
    {
        $user = User::factory()->create();
        $this->makeNotification($user);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

        $response->assertOk();
        $item = $response->json('data.0');

        $this->assertArrayNotHasKey('user_id',    $item, 'user_id must not be exposed');
        $this->assertArrayNotHasKey('updated_at', $item, 'updated_at must not be exposed');
    }

    public function test_is_read_field_reflects_read_state(): void
    {
        $user       = User::factory()->create();
        $unread     = $this->makeNotification($user);
        $read       = $this->makeNotification($user, ['read_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');
        $response->assertOk();

        $items = collect($response->json('data'))->keyBy('id');

        $this->assertFalse($items[$unread->id]['is_read']);
        $this->assertTrue($items[$read->id]['is_read']);
    }

    public function test_mark_read_response_uses_resource_shape(): void
    {
        $user  = User::factory()->create();
        $notif = $this->makeNotification($user);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/notifications/{$notif->id}/read");

        $response->assertOk()
            ->assertJsonStructure([
                'notification' => ['id', 'type', 'title', 'body', 'data', 'read_at', 'is_read', 'created_at'],
            ]);

        $item = $response->json('notification');
        $this->assertArrayNotHasKey('user_id',    $item, 'user_id must not be exposed');
        $this->assertArrayNotHasKey('updated_at', $item, 'updated_at must not be exposed');
        $this->assertTrue($item['is_read']);
    }

    // ── Unread count ───────────────────────────────────────────────────────────

    public function test_unread_count_returns_only_current_users_unread(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();

        $this->makeNotification($alice);
        $this->makeNotification($alice);
        $this->makeNotification($alice, ['read_at' => now()]);
        $this->makeNotification($bob); // should not count for Alice

        $response = $this->actingAs($alice, 'sanctum')->getJson('/api/notifications/unread-count');

        $response->assertOk()->assertJsonPath('unread_count', 2);
    }

    public function test_unread_count_is_zero_when_all_read(): void
    {
        $user = User::factory()->create();
        $this->makeNotification($user, ['read_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    // ── Mark single read ───────────────────────────────────────────────────────

    public function test_user_can_mark_own_notification_read(): void
    {
        $user  = User::factory()->create();
        $notif = $this->makeNotification($user);

        $this->assertNull($notif->read_at);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/notifications/{$notif->id}/read");

        $response->assertOk()->assertJsonPath('notification.id', $notif->id);
        $this->assertNotNull(Notification::find($notif->id)->read_at);
    }

    public function test_user_cannot_mark_another_users_notification_read(): void
    {
        $alice      = User::factory()->create();
        $bob        = User::factory()->create();
        $aliceNotif = $this->makeNotification($alice);

        $this->actingAs($bob, 'sanctum')
            ->patchJson("/api/notifications/{$aliceNotif->id}/read")
            ->assertNotFound();

        $this->assertNull(Notification::find($aliceNotif->id)->read_at);
    }

    public function test_marking_already_read_notification_is_idempotent(): void
    {
        $user   = User::factory()->create();
        $readAt = now()->subHour();
        $notif  = $this->makeNotification($user, ['read_at' => $readAt]);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/notifications/{$notif->id}/read")
            ->assertOk();

        $this->assertEquals(
            $readAt->toDateTimeString(),
            Notification::find($notif->id)->read_at->toDateTimeString()
        );
    }

    // ── Mark all read ──────────────────────────────────────────────────────────

    public function test_mark_all_read_only_affects_current_user(): void
    {
        $alice = User::factory()->create();
        $bob   = User::factory()->create();

        $aliceNotif = $this->makeNotification($alice);
        $bobNotif   = $this->makeNotification($bob);

        $this->actingAs($alice, 'sanctum')
            ->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertNotNull(Notification::find($aliceNotif->id)->read_at);
        $this->assertNull(Notification::find($bobNotif->id)->read_at);
    }

    public function test_mark_all_read_returns_zero_when_already_all_read(): void
    {
        $user = User::factory()->create();
        $this->makeNotification($user, ['read_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 0);
    }

    // ── Storage / data ─────────────────────────────────────────────────────────

    public function test_notification_json_data_is_stored_and_returned(): void
    {
        $user    = User::factory()->create();
        $payload = ['contract_id' => 99, 'action' => 'completed'];
        $this->makeNotification($user, ['data' => $payload]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

        $response->assertOk();
        $this->assertSame($payload, $response->json('data.0.data'));
    }

    public function test_notification_without_data_payload_is_valid(): void
    {
        $user = User::factory()->create();
        $this->makeNotification($user, ['data' => null]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

        $response->assertOk();
        $this->assertNull($response->json('data.0.data'));
    }
}
