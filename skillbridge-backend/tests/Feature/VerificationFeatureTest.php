<?php

namespace Tests\Feature;

use App\Models\ProviderVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class VerificationFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_verification_requires_authentication_and_provider_role(): void
    {
        $this->getJson('/api/verification')->assertUnauthorized();
        $this->postJson('/api/verification')->assertUnauthorized();

        $seeker = User::factory()->create(['role' => 'seeker']);
        $this->actingAs($seeker, 'sanctum')->getJson('/api/verification')->assertForbidden();
        $this->actingAs($seeker, 'sanctum')->postJson('/api/verification')->assertForbidden();
    }

    public function test_provider_can_submit_once_and_duplicate_pending_is_rejected(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $request = $this->actingAs($provider, 'sanctum');

        $request->getJson('/api/verification')
            ->assertOk()
            ->assertJsonPath('verification.status', 'unverified')
            ->assertJsonPath('verification.is_verified', false);

        $request->postJson('/api/verification')
            ->assertCreated()
            ->assertJsonPath('verification.status', 'pending');
        $request->postJson('/api/verification')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A verification request is already pending.');

        $this->assertDatabaseHas('provider_verifications', [
            'user_id' => $provider->id,
            'status' => 'pending',
        ]);
    }

    public function test_only_admin_can_review_and_rejection_reason_is_required(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $verification = ProviderVerification::create(['user_id' => $provider->id, 'status' => 'pending', 'submitted_at' => now()]);

        $this->actingAs($otherProvider, 'sanctum')->getJson('/api/admin/verifications')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/verifications/'.$verification->id.'/reject', [])->assertUnprocessable()->assertJsonValidationErrors(['rejection_reason']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/verifications/'.$verification->id.'/reject', ['rejection_reason' => 'Please provide clearer verification details.'])->assertOk();

        $verification->refresh();
        $this->assertSame('rejected', $verification->status);
        $this->assertSame($admin->id, $verification->reviewer_id);
        $this->assertSame('Please provide clearer verification details.', $verification->rejection_reason);
    }

    public function test_rejected_provider_can_resubmit_and_admin_can_approve(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);
        $verification = ProviderVerification::create([
            'user_id' => $provider->id,
            'status' => 'rejected',
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now()->subHour(),
            'reviewer_id' => $admin->id,
            'rejection_reason' => 'Needs another review.',
        ]);

        $this->actingAs($provider, 'sanctum')->postJson('/api/verification')->assertCreated()->assertJsonPath('verification.status', 'pending');
        $verification->refresh();
        $this->assertNull($verification->rejection_reason);
        $this->assertNull($verification->reviewer_id);
        $this->assertNull($verification->reviewed_at);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/verifications/'.$verification->id)->assertOk()->assertJsonPath('verification.provider.id', $provider->id);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/verifications/'.$verification->id.'/approve')->assertOk()->assertJsonPath('verification.status', 'verified');
        $this->assertDatabaseHas('provider_verifications', ['id' => $verification->id, 'status' => 'verified', 'reviewer_id' => $admin->id]);
    }

    public function test_public_profiles_show_only_verified_badge_and_no_workflow_data(): void
    {
        $verified = User::factory()->create(['role' => 'provider']);
        $unverified = User::factory()->create(['role' => 'provider']);
        ProviderVerification::create(['user_id' => $verified->id, 'status' => 'verified', 'reviewed_at' => now()]);
        ProviderVerification::create(['user_id' => $unverified->id, 'status' => 'pending', 'submitted_at' => now()]);

        $verifiedProfile = $this->getJson('/api/users/'.$verified->id)->assertOk();
        $verifiedProfile->assertJsonPath('is_verified', true)
            ->assertJsonMissing(['status' => 'verified'])
            ->assertJsonMissing(['reviewer_id' => $verified->id]);
        $verifiedProfile->assertJsonMissing(['email' => $verified->email])->assertJsonMissing(['phone' => $verified->phone]);

        $unverifiedProfile = $this->getJson('/api/users/'.$unverified->id)->assertOk();
        $unverifiedProfile->assertJsonPath('is_verified', false)
            ->assertJsonMissing(['status' => 'pending'])
            ->assertJsonMissing(['rejection_reason' => null]);
    }
}
