<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Contract;
use App\Models\ContractMilestone;
use App\Models\Dispute;
use App\Models\EscrowAccount;
use App\Models\JobPost;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Escrow\EscrowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DisputeFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dispute_requires_authentication_participant_access_and_private_fields_are_not_exposed(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture();
        $this->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => 'Issue', 'description' => 'Details'])->assertUnauthorized();
        $outsider = User::factory()->create(['role' => 'seeker']);
        $this->actingAs($outsider, 'sanctum')->getJson('/api/contracts/'.$contract->id.'/disputes')->assertForbidden();
        $response = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => 'Issue', 'description' => 'Details'])->assertCreated();
        $response->assertJsonPath('dispute.raised_by', $seeker->id)->assertJsonPath('dispute.against_user_id', $provider->id)->assertJsonMissing(['email' => $provider->email])->assertJsonMissing(['phone' => $provider->phone]);
        $id = $response->json('dispute.id');
        $this->actingAs($outsider, 'sanctum')->getJson('/api/disputes/'.$id)->assertForbidden();
        $this->actingAs($provider, 'sanctum')->getJson('/api/disputes/'.$id)->assertOk();
    }

    public function test_provider_can_raise_against_seeker_and_self_selection_is_not_possible(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture();
        $response = $this->actingAs($provider, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => 'Provider issue', 'description' => 'Details'])->assertCreated();
        $response->assertJsonPath('dispute.raised_by', $provider->id)->assertJsonPath('dispute.against_user_id', $seeker->id);
        $this->assertNotSame($provider->id, $response->json('dispute.against_user_id'));
    }

    public function test_whitespace_only_reason_is_rejected(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $this->actingAs($seeker, 'sanctum')
            ->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => '   ', 'description' => 'Valid description'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_whitespace_only_description_is_rejected(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $this->actingAs($seeker, 'sanctum')
            ->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => 'Valid reason', 'description' => " \t\n "])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description']);
    }

    public function test_dispute_reason_and_description_are_trimmed_before_persistence(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $response = $this->actingAs($seeker, 'sanctum')
            ->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => '  Delivery issue  ', 'description' => "\n  Work was not delivered.  \t"])
            ->assertCreated()
            ->assertJsonPath('dispute.reason', 'Delivery issue')
            ->assertJsonPath('dispute.description', 'Work was not delivered.');

        $this->assertDatabaseHas('disputes', [
            'id' => $response->json('dispute.id'),
            'reason' => 'Delivery issue',
            'description' => 'Work was not delivered.',
        ]);
    }

    public function test_only_one_active_dispute_per_contract_and_milestone_scope_is_allowed(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $milestone = ContractMilestone::create(['contract_id' => $contract->id, 'title' => 'Milestone', 'description' => 'Work', 'amount' => 100, 'status' => 'pending']);
        $request = $this->actingAs($seeker, 'sanctum');
        $request->postJson('/api/contracts/'.$contract->id.'/disputes', ['milestone_id' => $milestone->id, 'reason' => 'First', 'description' => 'Details'])->assertCreated();
        $request->postJson('/api/contracts/'.$contract->id.'/disputes', ['milestone_id' => $milestone->id, 'reason' => 'Second', 'description' => 'Details'])->assertUnprocessable()->assertJsonValidationErrors(['dispute']);
        $request->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => 'Contract', 'description' => 'Details'])->assertCreated();
        $this->assertSame(2, Dispute::where('contract_id', $contract->id)->count());
    }

    public function test_milestone_must_belong_to_contract_and_invalid_contract_states_are_rejected(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        [$otherContract] = $this->contractFixture();
        $otherMilestone = ContractMilestone::create(['contract_id' => $otherContract->id, 'title' => 'Other', 'description' => 'Other', 'amount' => 100, 'status' => 'pending']);
        $request = $this->actingAs($seeker, 'sanctum');
        $request->postJson('/api/contracts/'.$contract->id.'/disputes', ['milestone_id' => $otherMilestone->id, 'reason' => 'Issue', 'description' => 'Details'])->assertUnprocessable()->assertJsonValidationErrors(['milestone_id']);

        $cancelled = $this->contractFixture('cancelled');
        $this->actingAs($cancelled[1], 'sanctum')->postJson('/api/contracts/'.$cancelled[0]->id.'/disputes', ['reason' => 'Issue', 'description' => 'Details'])->assertUnprocessable()->assertJsonValidationErrors(['contract']);
        $invalidCompleted = $this->contractFixture('completed', false);
        $this->actingAs($invalidCompleted[1], 'sanctum')->postJson('/api/contracts/'.$invalidCompleted[0]->id.'/disputes', ['reason' => 'Issue', 'description' => 'Details'])->assertUnprocessable()->assertJsonValidationErrors(['contract']);
    }

    public function test_creator_can_cancel_only_open_dispute_and_cancel_frees_scope(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture();
        $response = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => 'Issue', 'description' => 'Details'])->assertCreated();
        $id = $response->json('dispute.id');
        $this->actingAs($provider, 'sanctum')->postJson('/api/disputes/'.$id.'/cancel')->assertForbidden();
        $this->actingAs($seeker, 'sanctum')->postJson('/api/disputes/'.$id.'/cancel')->assertOk()->assertJsonPath('dispute.status', 'cancelled');
        $this->actingAs($seeker, 'sanctum')->postJson('/api/disputes/'.$id.'/cancel')->assertUnprocessable();
        $this->actingAs($provider, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => 'New issue', 'description' => 'Details'])->assertCreated();
    }

    public function test_only_admin_can_review_resolution_requires_note_and_terminal_disputes_are_immutable(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => 'Issue', 'description' => 'Details'])->assertCreated();
        $id = $response->json('dispute.id');
        $this->actingAs($seeker, 'sanctum')->getJson('/api/admin/disputes')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/disputes/'.$id.'/resolve', [])->assertUnprocessable()->assertJsonValidationErrors(['resolution']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/disputes/'.$id.'/resolve', ['resolution' => 'Resolved after review.'])->assertOk()->assertJsonPath('dispute.status', 'resolved');
        $this->assertDatabaseHas('disputes', ['id' => $id, 'status' => 'resolved', 'resolved_by' => $admin->id]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/disputes/'.$id.'/reject', ['resolution' => 'Cannot reject now.'])->assertUnprocessable();
        $this->actingAs($seeker, 'sanctum')->postJson('/api/disputes/'.$id.'/cancel')->assertUnprocessable();

        $rejected = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => 'Second issue', 'description' => 'Details'])->assertCreated();
        $rejectedId = $rejected->json('dispute.id');
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/disputes/'.$rejectedId.'/reject', ['resolution' => 'Rejected after review.'])->assertOk()->assertJsonPath('dispute.status', 'rejected');
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/disputes/'.$rejectedId.'/resolve', ['resolution' => 'Cannot resolve now.'])->assertUnprocessable();
    }

    public function test_admin_cannot_resolve_a_dispute_if_admin_is_a_contract_participant(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture();
        $provider->update(['role' => 'admin']);
        $provider->refresh();
        $response = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => 'Issue', 'description' => 'Details'])->assertCreated();
        $this->actingAs($provider, 'sanctum')->postJson('/api/admin/disputes/'.$response->json('dispute.id').'/resolve', ['resolution' => 'Not allowed.'])->assertForbidden();
    }

    public function test_rejection_is_terminal_and_does_not_change_escrow_or_legacy_transactions(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $payment = PaymentTransaction::create(['contract_id' => $contract->id, 'provider' => 'mock', 'provider_payment_id' => 'dispute-payment-'.uniqid(), 'idempotency_key' => 'dispute-payment-key-'.uniqid(), 'amount' => 200, 'currency' => 'BDT', 'status' => 'succeeded', 'paid_at' => now()]);
        app(EscrowService::class)->holdConfirmedPayment($payment);
        $before = EscrowAccount::where('contract_id', $contract->id)->value('balance');
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/disputes', ['reason' => 'Issue', 'description' => 'Details'])->assertCreated();
        $id = $response->json('dispute.id');
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/disputes/'.$id.'/reject', ['resolution' => 'Evidence does not support the claim.'])->assertOk()->assertJsonPath('dispute.status', 'rejected');
        $this->assertSame((string) $before, (string) EscrowAccount::where('contract_id', $contract->id)->value('balance'));
        $this->assertSame(0, Transaction::where('contract_id', $contract->id)->whereIn('type', ['release', 'refund'])->count());
    }

    private function contractFixture(string $status = 'active', bool $completedAt = true): array
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        $provider = User::factory()->create(['role' => 'provider']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Dispute job', 'description' => 'Dispute job', 'category' => 'General', 'budget_min' => 100, 'budget_max' => 1000, 'location' => 'Dhaka', 'status' => 'in_progress']);
        $bid = Bid::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'amount' => 500, 'message' => 'Accepted bid', 'status' => 'accepted']);
        $contract = Contract::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'seeker_id' => $seeker->id, 'bid_id' => $bid->id, 'amount' => 500, 'status' => $status, 'started_at' => in_array($status, ['active', 'completed', 'cancelled'], true) ? now() : null, 'completed_at' => $status === 'completed' && $completedAt ? now() : null]);
        return [$contract, $seeker, $provider];
    }
}
