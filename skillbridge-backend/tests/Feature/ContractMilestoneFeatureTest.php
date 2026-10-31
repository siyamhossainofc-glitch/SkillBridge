<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Contract;
use App\Models\ContractMilestone;
use App\Models\JobPost;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ContractMilestoneFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_milestones_require_authentication_and_participant_access(): void
    {
        [$contract] = $this->contractFixture('active');
        $this->getJson('/api/contracts/'.$contract->id.'/milestones')->assertUnauthorized();
        $this->postJson('/api/contracts/'.$contract->id.'/milestones', $this->payload())->assertUnauthorized();

        $outsider = User::factory()->create(['role' => 'seeker']);
        $this->actingAs($outsider, 'sanctum')->getJson('/api/contracts/'.$contract->id.'/milestones')->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/milestones', $this->payload())->assertForbidden();
    }

    public function test_seeker_can_create_edit_and_delete_pending_milestones_with_amount_validation(): void
    {
        [$contract, $seeker] = $this->contractFixture('pending');
        $request = $this->actingAs($seeker, 'sanctum');

        $request->postJson('/api/contracts/'.$contract->id.'/milestones', ['title' => 'Design handoff', 'amount' => -1])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $created = $request->postJson('/api/contracts/'.$contract->id.'/milestones', $this->payload())->assertCreated()->json('milestone');
        $this->assertSame('pending', $created['status']);
        $this->assertSame('125.00', $created['amount']);

        $request->patchJson('/api/milestones/'.$created['id'], ['title' => 'Updated handoff', 'amount' => 140, 'due_date' => '2026-10-01'])->assertOk()->assertJsonPath('milestone.title', 'Updated handoff');
        $request->deleteJson('/api/milestones/'.$created['id'])->assertOk();
        $this->assertDatabaseMissing('contract_milestones', ['id' => $created['id']]);
    }

    public function test_total_milestone_amount_at_contract_amount_succeeds(): void
    {
        [$contract, $seeker] = $this->contractFixture('pending');
        $request = $this->actingAs($seeker, 'sanctum');

        $request->postJson('/api/contracts/'.$contract->id.'/milestones', array_merge($this->payload(), ['amount' => 300]))->assertCreated();
        $request->postJson('/api/contracts/'.$contract->id.'/milestones', array_merge($this->payload(), ['title' => 'Final deliverable', 'amount' => 200]))->assertCreated();

        $this->assertSame('500.00', (string) ContractMilestone::where('contract_id', $contract->id)->sum('amount'));
    }

    public function test_creating_milestone_that_exceeds_contract_amount_returns_business_validation_error(): void
    {
        [$contract, $seeker] = $this->contractFixture('pending');
        $request = $this->actingAs($seeker, 'sanctum');
        $request->postJson('/api/contracts/'.$contract->id.'/milestones', array_merge($this->payload(), ['amount' => 400]))->assertCreated();

        $request->postJson('/api/contracts/'.$contract->id.'/milestones', array_merge($this->payload(), ['title' => 'Over budget', 'amount' => 101]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount'])
            ->assertJsonPath('errors.amount.0', 'The total milestone amount cannot exceed the contract amount of 500.00.');
    }

    public function test_updating_milestone_that_exceeds_contract_amount_returns_business_validation_error(): void
    {
        [$contract, $seeker] = $this->contractFixture('pending');
        $request = $this->actingAs($seeker, 'sanctum');
        $first = $request->postJson('/api/contracts/'.$contract->id.'/milestones', array_merge($this->payload(), ['amount' => 300]))->assertCreated()->json('milestone');
        $request->postJson('/api/contracts/'.$contract->id.'/milestones', array_merge($this->payload(), ['title' => 'Second deliverable', 'amount' => 200]))->assertCreated();

        $request->patchJson('/api/milestones/'.$first['id'], ['amount' => 301])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount'])
            ->assertJsonPath('errors.amount.0', 'The total milestone amount cannot exceed the contract amount of 500.00.');
        $this->assertDatabaseHas('contract_milestones', ['id' => $first['id'], 'amount' => '300.00']);
    }

    public function test_approved_milestone_cannot_be_changed_even_within_total(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture('active');
        $milestone = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/milestones', $this->payload())->assertCreated()->json('milestone');
        $providerRequest = $this->actingAs($provider, 'sanctum');
        $providerRequest->postJson('/api/milestones/'.$milestone['id'].'/start')->assertOk();
        $providerRequest->postJson('/api/milestones/'.$milestone['id'].'/submit')->assertOk();
        $this->actingAs($seeker, 'sanctum')->postJson('/api/milestones/'.$milestone['id'].'/approve')->assertOk();

        $this->actingAs($seeker, 'sanctum')->patchJson('/api/milestones/'.$milestone['id'], ['amount' => 124])->assertUnprocessable();
        $this->assertDatabaseHas('contract_milestones', ['id' => $milestone['id'], 'status' => 'approved', 'amount' => '125.00']);
    }

    public function test_contract_state_and_roles_control_milestone_creation_and_start(): void
    {
        [$pending, $seeker, $provider] = $this->contractFixture('pending');
        $this->actingAs($provider, 'sanctum')->postJson('/api/contracts/'.$pending->id.'/milestones', $this->payload())->assertForbidden();
        $created = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$pending->id.'/milestones', $this->payload())->assertCreated()->json('milestone');
        $this->actingAs($provider, 'sanctum')->postJson('/api/milestones/'.$created['id'].'/start')->assertUnprocessable();

        [$cancelled] = $this->contractFixture('cancelled');
        $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$cancelled->id.'/milestones', $this->payload())->assertUnprocessable();
    }

    public function test_active_milestone_lifecycle_and_approved_immutability(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture('active');
        $milestone = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/milestones', $this->payload())->assertCreated()->json('milestone');
        $request = $this->actingAs($provider, 'sanctum');

        $request->postJson('/api/milestones/'.$milestone['id'].'/start')->assertOk()->assertJsonPath('milestone.status', 'in_progress');
        $request->postJson('/api/milestones/'.$milestone['id'].'/submit')->assertOk()->assertJsonPath('milestone.status', 'submitted');
        $request->postJson('/api/milestones/'.$milestone['id'].'/approve')->assertForbidden();
        $this->actingAs($seeker, 'sanctum')->postJson('/api/milestones/'.$milestone['id'].'/approve')->assertOk()->assertJsonPath('milestone.status', 'approved');

        $this->actingAs($seeker, 'sanctum')->patchJson('/api/milestones/'.$milestone['id'], ['title' => 'Tampered', 'amount' => 999])->assertUnprocessable();
        $this->actingAs($seeker, 'sanctum')->deleteJson('/api/milestones/'.$milestone['id'])->assertUnprocessable();
        $this->assertDatabaseMissing('transactions', ['contract_id' => $contract->id]);
        $this->assertDatabaseHas('contract_milestones', ['id' => $milestone['id'], 'status' => 'approved', 'amount' => '125.00']);
    }

    public function test_rejected_milestone_can_be_resubmitted_but_invalid_transitions_are_rejected(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture('active');
        $milestone = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/milestones', $this->payload())->assertCreated()->json('milestone');
        $providerRequest = $this->actingAs($provider, 'sanctum');
        $providerRequest->postJson('/api/milestones/'.$milestone['id'].'/start')->assertOk();
        $providerRequest->postJson('/api/milestones/'.$milestone['id'].'/submit')->assertOk();
        $this->actingAs($seeker, 'sanctum')->postJson('/api/milestones/'.$milestone['id'].'/reject')->assertOk()->assertJsonPath('milestone.status', 'rejected')->assertJsonPath('milestone.submitted_at', null);
        $this->assertDatabaseHas('contract_milestones', ['id' => $milestone['id'], 'status' => 'rejected', 'submitted_at' => null]);
        $this->actingAs($seeker, 'sanctum')->postJson('/api/milestones/'.$milestone['id'].'/approve')->assertUnprocessable();
        $resubmitted = $providerRequest->postJson('/api/milestones/'.$milestone['id'].'/submit')->assertOk()->assertJsonPath('milestone.status', 'submitted');
        $this->assertNotNull($resubmitted->json('milestone.submitted_at'));
        $this->assertNull($resubmitted->json('milestone.rejected_at'));
    }

    private function payload(): array
    {
        return ['title' => 'Build first deliverable', 'description' => 'Complete the agreed first milestone.', 'amount' => 125, 'due_date' => '2026-09-30'];
    }

    private function contractFixture(string $status): array
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        $provider = User::factory()->create(['role' => 'provider']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Milestone job', 'description' => 'Milestone job', 'category' => 'General', 'budget_min' => 100, 'budget_max' => 1000, 'location' => 'Dhaka', 'status' => 'in_progress']);
        $bid = Bid::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'amount' => 500, 'message' => 'Accepted bid', 'status' => 'accepted']);
        $contract = Contract::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'seeker_id' => $seeker->id, 'bid_id' => $bid->id, 'amount' => 500, 'status' => $status, 'started_at' => $status === 'active' ? now() : null]);
        return [$contract, $seeker, $provider];
    }
}
