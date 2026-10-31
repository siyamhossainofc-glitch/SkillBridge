<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Contract;
use App\Models\ContractMilestone;
use App\Models\JobPost;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PaymentFoundationFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_payment_intent_requires_authentication_and_contract_participant_authorization(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture();
        $payload = $this->paymentPayload('auth-key');
        $this->postJson('/api/contracts/'.$contract->id.'/payments', $payload)->assertUnauthorized();

        $outsider = User::factory()->create(['role' => 'seeker']);
        $this->actingAs($outsider, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/payments', $payload)->assertForbidden();
        $this->actingAs($provider, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/payments', $payload)->assertUnprocessable();
    }

    public function test_payment_amount_and_milestone_amount_rules_are_server_enforced(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $request = $this->actingAs($seeker, 'sanctum');
        $request->postJson('/api/contracts/'.$contract->id.'/payments', $this->paymentPayload('over-contract', 501))
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $milestone = ContractMilestone::create(['contract_id' => $contract->id, 'title' => 'Approved work', 'description' => 'Ready to pay', 'amount' => 125, 'status' => 'approved']);
        $request->postJson('/api/contracts/'.$contract->id.'/payments', $this->paymentPayload('wrong-milestone', 124, $milestone->id))
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $request->postJson('/api/contracts/'.$contract->id.'/payments', array_merge($this->paymentPayload('client-force', 125, $milestone->id), ['status' => 'succeeded']))
            ->assertCreated()->assertJsonPath('payment.status', 'pending');
    }

    public function test_same_idempotency_key_returns_the_same_payment_without_duplicate_rows(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $request = $this->actingAs($seeker, 'sanctum');
        $payload = $this->paymentPayload('same-key', 200);
        $first = $request->postJson('/api/contracts/'.$contract->id.'/payments', $payload)->assertCreated()->json('payment');
        $second = $request->postJson('/api/contracts/'.$contract->id.'/payments', $payload)->assertCreated()->json('payment');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, PaymentTransaction::where('idempotency_key', 'same-key')->count());
        $this->assertSame('pending', $second['status']);

        $request->postJson('/api/contracts/'.$contract->id.'/payments', $this->paymentPayload('same-key', 201))
            ->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_simulated_concurrent_payment_attempts_cannot_exceed_contract_amount(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $request = $this->actingAs($seeker, 'sanctum');
        $request->postJson('/api/contracts/'.$contract->id.'/payments', $this->paymentPayload('concurrent-a', 300))->assertCreated();
        $request->postJson('/api/contracts/'.$contract->id.'/payments', $this->paymentPayload('concurrent-b', 250))
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->assertSame(300, (int) PaymentTransaction::where('contract_id', $contract->id)->whereIn('status', ['initiated', 'pending', 'succeeded'])->sum('amount'));
    }

    public function test_signed_webhook_is_authoritative_idempotent_and_duplicate_safe(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $payment = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/payments', $this->paymentPayload('webhook-key', 200))->assertCreated()->json('payment');
        $payload = json_encode(['event_id' => 'evt-1', 'provider_payment_id' => $payment['provider_payment_id'], 'status' => 'succeeded', 'amount' => '200.00', 'currency' => 'BDT'], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, (string) config('services.payments.mock.webhook_secret'));

        $this->withHeader('X-Payment-Signature', $signature)->postJson('/api/payments/webhook/mock', json_decode($payload, true))
            ->assertOk()->assertJsonPath('payment.status', 'succeeded');
        $this->withHeader('X-Payment-Signature', $signature)->postJson('/api/payments/webhook/mock', json_decode($payload, true))
            ->assertOk()->assertJsonPath('payment.status', 'succeeded');
        $this->assertSame(1, PaymentTransaction::where('provider_event_id', 'evt-1')->count());
        $this->assertNotNull(PaymentTransaction::find($payment['id'])->paid_at);
    }

    public function test_invalid_signature_and_provider_failure_do_not_claim_success(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $payment = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/payments', $this->paymentPayload('bad-signature', 200))->assertCreated()->json('payment');
        $payload = ['event_id' => 'evt-bad', 'provider_payment_id' => $payment['provider_payment_id'], 'status' => 'succeeded', 'amount' => '200.00', 'currency' => 'BDT'];
        $this->withHeader('X-Payment-Signature', 'invalid')->postJson('/api/payments/webhook/mock', $payload)->assertUnauthorized();
        $this->assertDatabaseHas('payment_transactions', ['id' => $payment['id'], 'status' => 'pending']);

        Config::set('services.payments.mock.fail_create', true);
        $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/payments', $this->paymentPayload('provider-failure', 100))->assertStatus(502);
        Config::set('services.payments.mock.fail_create', false);
        $this->assertDatabaseHas('payment_transactions', ['idempotency_key' => 'provider-failure', 'status' => 'failed']);
    }

    public function test_payment_status_is_private_to_contract_participants(): void
    {
        [$contract, $seeker] = $this->contractFixture();
        $payment = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/payments', $this->paymentPayload('private-status', 100))->assertCreated()->json('payment');
        $outsider = User::factory()->create(['role' => 'seeker']);
        $this->actingAs($outsider, 'sanctum')->getJson('/api/payments/'.$payment['id'])->assertForbidden();
    }

    private function paymentPayload(string $key, int $amount = 100, ?int $milestoneId = null): array
    {
        return ['amount' => $amount, 'currency' => 'BDT', 'provider' => 'mock', 'idempotency_key' => $key, 'milestone_id' => $milestoneId];
    }

    private function contractFixture(): array
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        $provider = User::factory()->create(['role' => 'provider']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Payment job', 'description' => 'Payment job', 'category' => 'General', 'budget_min' => 100, 'budget_max' => 1000, 'location' => 'Dhaka', 'status' => 'in_progress']);
        $bid = Bid::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'amount' => 500, 'message' => 'Accepted bid', 'status' => 'accepted']);
        $contract = Contract::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'seeker_id' => $seeker->id, 'bid_id' => $bid->id, 'amount' => 500, 'status' => 'active', 'started_at' => now()]);
        return [$contract, $seeker, $provider];
    }
}
