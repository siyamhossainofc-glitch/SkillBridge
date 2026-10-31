<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Contract;
use App\Models\JobPost;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminModerationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_endpoints_require_admin_role_and_prevent_idor_suspension(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'moderation_status' => 'active']);
        $user = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/users')->assertForbidden();
        $this->patchJson('/api/admin/users/'.$admin->id.'/suspend', ['reason' => 'attempt'])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/users/'.$admin->id.'/suspend', ['reason' => 'self'])->assertUnprocessable();
        $this->patchJson('/api/admin/users/'.$user->id.'/suspend', ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->patchJson('/api/admin/users/'.$user->id.'/suspend', ['reason' => 'Repeated abuse reports'])->assertOk();
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user.suspended', 'target_type' => 'user', 'target_id' => $user->id]);
    }

    public function test_suspended_seeker_cannot_create_marketplace_jobs(): void
    {
        $user = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'suspended']);
        Sanctum::actingAs($user);
        $this->postJson('/api/jobs', [
            'title' => 'Should be blocked', 'description' => 'Blocked', 'category' => 'General',
            'budget_min' => 10, 'budget_max' => 20, 'location' => 'Dhaka',
        ])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    public function test_public_user_response_excludes_private_fields_and_suspended_users(): void
    {
        $active = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active', 'phone' => '01700000000']);
        $this->getJson('/api/users/'.$active->id)->assertOk()->assertJsonMissingPath('user.email')->assertJsonMissingPath('user.phone');
        $suspended = User::factory()->create(['role' => 'provider', 'moderation_status' => 'suspended']);
        $this->getJson('/api/users/'.$suspended->id)->assertNotFound();
    }

    public function test_admin_hiding_job_is_audited_and_removed_from_public_browse(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'moderation_status' => 'active']);
        $owner = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $job = JobPost::create(['user_id' => $owner->id, 'title' => 'Moderated job', 'description' => 'Description', 'category' => 'General', 'budget_min' => 10, 'budget_max' => 20, 'location' => 'Dhaka', 'status' => 'open']);
        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/jobs/'.$job->id.'/hide', ['reason' => 'Policy violation'])->assertOk();
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'content.hidden', 'target_type' => 'jobs', 'target_id' => $job->id]);
        $this->getJson('/api/jobs')->assertOk()->assertJsonMissing(['id' => $job->id]);
        $this->patchJson('/api/admin/jobs/'.$job->id.'/restore')->assertOk();
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'content.restored', 'target_type' => 'jobs', 'target_id' => $job->id]);
    }

    public function test_admin_content_details_use_allowlisted_payloads(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'moderation_status' => 'active']);
        $owner = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active', 'phone' => '01700000000', 'address' => 'Private address']);
        $job = JobPost::create(['user_id' => $owner->id, 'title' => 'Safe detail', 'description' => 'Description', 'category' => 'General', 'budget_min' => 10, 'budget_max' => 20, 'location' => 'Dhaka', 'status' => 'open']);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/admin/jobs/'.$job->id)->assertOk();
        $response->assertJsonPath('item.id', $job->id)
            ->assertJsonPath('item.owner.id', $owner->id)
            ->assertJsonMissingPath('item.user_id')
            ->assertJsonMissingPath('item.owner.email')
            ->assertJsonMissingPath('item.owner.phone')
            ->assertJsonMissingPath('item.bids')
            ->assertJsonMissingPath('item.messages');
    }

    public function test_admin_summary_returns_moderation_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'moderation_status' => 'active']);
        User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        User::factory()->create(['role' => 'provider', 'moderation_status' => 'suspended']);
        $owner = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        JobPost::create(['user_id' => $owner->id, 'title' => 'Visible', 'description' => 'Description', 'category' => 'General', 'budget_min' => 10, 'budget_max' => 20, 'location' => 'Dhaka', 'status' => 'open', 'moderation_status' => 'visible']);
        JobPost::create(['user_id' => $owner->id, 'title' => 'Hidden', 'description' => 'Description', 'category' => 'General', 'budget_min' => 10, 'budget_max' => 20, 'location' => 'Dhaka', 'status' => 'open', 'moderation_status' => 'hidden']);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/summary')->assertOk()
            ->assertJsonStructure(['counts' => ['users' => ['active', 'suspended'], 'jobs' => ['visible', 'hidden'], 'services' => ['visible', 'hidden'], 'portfolio' => ['visible', 'hidden'], 'reviews' => ['visible', 'hidden']]])
            ->assertJsonPath('counts.jobs.visible', 1)
            ->assertJsonPath('counts.jobs.hidden', 1);
    }

    public function test_review_moderation_updates_public_aggregate(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'moderation_status' => 'active']);
        $reviewee = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active', 'rating' => 5]);
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Review job', 'description' => 'Description', 'category' => 'General', 'budget_min' => 10, 'budget_max' => 20, 'location' => 'Dhaka', 'status' => 'completed']);
        $bid = Bid::create(['job_post_id' => $job->id, 'provider_id' => $reviewee->id, 'amount' => 15, 'message' => 'Accepted', 'status' => 'accepted']);
        $contract = Contract::create(['job_post_id' => $job->id, 'provider_id' => $reviewee->id, 'seeker_id' => $seeker->id, 'bid_id' => $bid->id, 'amount' => 15, 'status' => 'completed', 'completed_at' => now()]);
        $review = Review::create(['contract_id' => $contract->id, 'reviewer_id' => $seeker->id, 'reviewee_id' => $reviewee->id, 'rating' => 5, 'comment' => 'Good', 'status' => 'published', 'moderation_status' => 'visible']);
        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/reviews/'.$review->id.'/hide', ['reason' => 'Abusive content'])->assertOk();
        $this->getJson('/api/providers/'.$reviewee->id.'/reviews')->assertOk()->assertJsonPath('review_count', 0);
        $this->assertDatabaseHas('admin_audit_logs', ['target_type' => 'reviews', 'target_id' => $review->id]);
    }
}
