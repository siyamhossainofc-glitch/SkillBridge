<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Contract;
use App\Models\ContractMilestone;
use App\Models\EscrowAccount;
use App\Models\EscrowEntry;
use App\Models\JobPost;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Escrow\EscrowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EscrowFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_escrow_requires_authentication_and_participant_access(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture();
        $this->getJson('/api/contracts/'.$contract->id.'/escrow')->assertUnauthorized();
        $outsider = User::factory()->create(['role' => 'seeker']);
        $this->actingAs($outsider, 'sanctum')->getJson('/api/contracts/'.$contract->id.'/escrow')->assertForbidden();
        $this->actingAs($provider, 'sanctum')->getJson('/api/contracts/'.$contract->id.'/escrow')->assertOk();
    }

    public function test_confirmed_payment_creates_one_held_escrow_entry(): void
    {
        [$contract] = $this->contractFixture();
        $payment = $this->holdPayment($contract, 200);
        $account = EscrowAccount::where('contract_id', $contract->id)->first();
        $this->assertSame('200.00', (string) $account->balance);
        $this->assertDatabaseHas('escrow_entries', ['payment_transaction_id' => $payment->id, 'type' => 'hold', 'status' => 'held', 'amount' => '200.00']);

        app(EscrowService::class)->holdConfirmedPayment($payment);
        $this->assertSame(1, EscrowEntry::where('payment_transaction_id', $payment->id)->count());
    }

    public function test_only_seeker_can_release_completed_contract_and_release_is_ledger_consistent(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture('completed');
        $milestone = ContractMilestone::create(['contract_id' => $contract->id, 'title' => 'Completed milestone', 'description' => 'Traceable payment', 'amount' => 200, 'status' => 'approved']);
        $payment = $this->holdPayment($contract, 200, $milestone->id);
        $held = EscrowEntry::where('payment_transaction_id', $payment->id)->where('type', 'hold')->firstOrFail();
        $this->actingAs($provider, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/escrow/release', ['idempotency_key' => 'provider-release'])->assertForbidden();

        $response = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/escrow/release', ['idempotency_key' => 'release-1'])->assertOk();
        $response->assertJsonPath('entry.type', 'release')->assertJsonPath('entry.status', 'released');
        $this->assertDatabaseHas('escrow_accounts', ['contract_id' => $contract->id, 'balance' => '0.00', 'status' => 'closed']);
        $this->assertDatabaseHas('transactions', ['contract_id' => $contract->id, 'type' => 'release', 'status' => 'released', 'amount' => '200.00']);
        $release = EscrowEntry::where('contract_id', $contract->id)->where('type', 'release')->firstOrFail();
        $this->assertSame($payment->id, $release->payment_transaction_id);
        $this->assertSame($milestone->id, $release->milestone_id);
        $this->assertContains($payment->id, $release->metadata['source_payment_transaction_ids']);
        $this->assertContains($milestone->id, $release->metadata['source_milestone_ids']);
        $this->assertContains($held->id, $release->metadata['source_escrow_entry_ids']);

        $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/escrow/release', ['idempotency_key' => 'release-1'])->assertOk();
        $this->assertSame(1, EscrowEntry::where('contract_id', $contract->id)->where('type', 'release')->count());
    }

    public function test_only_seeker_can_refund_cancelled_contract_and_refund_is_idempotent(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture('cancelled');
        $payment = $this->holdPayment($contract, 200);
        $this->actingAs($provider, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/escrow/refund', ['idempotency_key' => 'provider-refund'])->assertForbidden();
        $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/escrow/refund', ['idempotency_key' => 'refund-1'])->assertOk()->assertJsonPath('entry.type', 'refund');
        $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/escrow/refund', ['idempotency_key' => 'refund-1'])->assertOk();
        $this->assertSame(1, EscrowEntry::where('contract_id', $contract->id)->where('type', 'refund')->count());
        $this->assertDatabaseHas('transactions', ['contract_id' => $contract->id, 'type' => 'refund', 'status' => 'refunded', 'amount' => '200.00']);
        $refund = EscrowEntry::where('contract_id', $contract->id)->where('type', 'refund')->firstOrFail();
        $this->assertSame($payment->id, $refund->payment_transaction_id);
        $this->assertContains($payment->id, $refund->metadata['source_payment_transaction_ids']);
    }

    public function test_invalid_transitions_amounts_and_settlements_are_rejected_without_negative_balance(): void
    {
        [$contract, $seeker] = $this->contractFixture('active');
        $this->holdPayment($contract, 200);
        $request = $this->actingAs($seeker, 'sanctum');
        $request->postJson('/api/contracts/'.$contract->id.'/escrow/release', ['idempotency_key' => 'active-release'])->assertUnprocessable();
        $request->postJson('/api/contracts/'.$contract->id.'/escrow/refund', ['idempotency_key' => 'active-refund'])->assertUnprocessable();
        $request->postJson('/api/contracts/'.$contract->id.'/escrow/release', ['amount' => 199, 'idempotency_key' => 'partial-release'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->assertDatabaseHas('escrow_accounts', ['contract_id' => $contract->id, 'balance' => '200.00', 'status' => 'open']);
        $this->assertSame(0, Transaction::where('contract_id', $contract->id)->whereIn('type', ['release', 'refund'])->count());
    }

    public function test_settlement_idempotency_key_cannot_be_reused_for_different_details(): void
    {
        [$contract, $seeker] = $this->contractFixture('completed');
        $this->holdPayment($contract, 200);
        $request = $this->actingAs($seeker, 'sanctum');
        $request->postJson('/api/contracts/'.$contract->id.'/escrow/release', ['idempotency_key' => 'settle-key'])->assertOk();
        $request->postJson('/api/contracts/'.$contract->id.'/escrow/release', ['amount' => 199, 'idempotency_key' => 'settle-key'])->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_escrow_entry_detail_has_participant_isolation(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $this->holdPayment($contract, 200);
        $entry = EscrowEntry::where('contract_id', $contract->id)->first();
        $this->actingAs($seeker, 'sanctum')->getJson('/api/escrow/'.$entry->id)->assertOk();
        $outsider = User::factory()->create(['role' => 'seeker']);
        $this->actingAs($outsider, 'sanctum')->getJson('/api/escrow/'.$entry->id)->assertForbidden();
    }

    private function holdPayment(Contract $contract, int $amount, ?int $milestoneId = null): PaymentTransaction
    {
        $payment = PaymentTransaction::create([
            'contract_id' => $contract->id,
            'milestone_id' => $milestoneId,
            'provider' => 'mock',
            'provider_payment_id' => 'mock-confirmed-'.$contract->id.'-'.$amount.'-'.uniqid(),
            'idempotency_key' => 'payment-'.$contract->id.'-'.$amount.'-'.uniqid(),
            'amount' => $amount,
            'currency' => 'BDT',
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);
        app(EscrowService::class)->holdConfirmedPayment($payment);
        return $payment;
    }

    private function contractFixture(string $status = 'active'): array
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        $provider = User::factory()->create(['role' => 'provider']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Escrow job', 'description' => 'Escrow job', 'category' => 'General', 'budget_min' => 100, 'budget_max' => 1000, 'location' => 'Dhaka', 'status' => 'in_progress']);
        $bid = Bid::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'amount' => 500, 'message' => 'Accepted bid', 'status' => 'accepted']);
        $contract = Contract::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'seeker_id' => $seeker->id, 'bid_id' => $bid->id, 'amount' => 500, 'status' => $status, 'started_at' => in_array($status, ['active', 'completed', 'cancelled'], true) ? now() : null, 'completed_at' => $status === 'completed' ? now() : null]);
        return [$contract, $seeker, $provider];
    }
}
