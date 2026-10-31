<?php

namespace App\Services\Payments;

use App\Models\Contract;
use App\Models\ContractMilestone;
use App\Models\PaymentTransaction;
use App\Services\Escrow\EscrowService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PaymentService
{
    public function __construct(private readonly PaymentProviderManager $providers, private readonly EscrowService $escrow)
    {
    }

    public function createIntent(Contract $contract, int $userId, array $input): PaymentTransaction
    {
        $providerName = $input['provider'] ?? config('services.payments.default', 'mock');
        $currency = strtoupper((string) ($input['currency'] ?? config('services.payments.currency', 'BDT')));
        $amount = $input['amount'];
        if ($currency !== strtoupper((string) config('services.payments.currency', 'BDT'))) {
            throw ValidationException::withMessages(['currency' => ['Unsupported payment currency.']]);
        }

        $wasCreated = false;
        try {
            $payment = DB::transaction(function () use ($contract, $userId, $providerName, $currency, $amount, $input, &$wasCreated) {
                $lockedContract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
                if (!$lockedContract->involvesUser($userId)) {
                    throw ValidationException::withMessages(['contract' => ['You are not a participant in this contract.']]);
                }
                if ((int) $lockedContract->seeker_id !== $userId) {
                    throw ValidationException::withMessages(['payment' => ['Only the contract seeker can initiate a payment.']]);
                }
                if (!in_array($lockedContract->status, ['pending', 'active', 'completed'], true)) {
                    throw ValidationException::withMessages(['contract' => ['Payments cannot be initiated for this contract state.']]);
                }

                $existing = PaymentTransaction::where('idempotency_key', $input['idempotency_key'])->first();
                if ($existing) {
                    $this->assertIdempotencyMatch($existing, $lockedContract, $providerName, $currency, $amount, $input['milestone_id'] ?? null);
                    return $existing;
                }

                $milestone = null;
                if (!empty($input['milestone_id'])) {
                    $milestone = ContractMilestone::whereKey($input['milestone_id'])
                        ->where('contract_id', $lockedContract->id)
                        ->lockForUpdate()
                        ->first();
                    if (!$milestone) {
                        throw ValidationException::withMessages(['milestone_id' => ['The milestone does not belong to this contract.']]);
                    }
                    if ($milestone->status !== 'approved') {
                        throw ValidationException::withMessages(['milestone_id' => ['Only an approved milestone can be paid.']]);
                    }
                    if ($this->cents($amount) !== $this->cents($milestone->amount)) {
                        throw ValidationException::withMessages(['amount' => ['Milestone payments must equal the approved milestone amount.']]);
                    }
                    if (PaymentTransaction::where('milestone_id', $milestone->id)->whereIn('status', ['initiated', 'pending', 'succeeded'])->exists()) {
                        throw ValidationException::withMessages(['milestone_id' => ['This milestone already has an active or completed payment.']]);
                    }
                }

                $existingPaymentCents = $lockedContract->paymentTransactions()
                    ->whereIn('status', ['initiated', 'pending', 'succeeded'])
                    ->sum('amount');
                if ($this->cents($existingPaymentCents) + $this->cents($amount) > $this->cents($lockedContract->amount)) {
                    throw ValidationException::withMessages(['amount' => ['The payment total cannot exceed the contract amount.']]);
                }

                $wasCreated = true;
                return PaymentTransaction::create([
                    'contract_id' => $lockedContract->id,
                    'milestone_id' => $milestone?->id,
                    'provider' => $providerName,
                    'idempotency_key' => $input['idempotency_key'],
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'initiated',
                ]);
            });
        } catch (QueryException $exception) {
            // A concurrent request may win the unique idempotency_key insert.
            // Recover its committed row instead of surfacing a 500.
            $existing = PaymentTransaction::where('idempotency_key', $input['idempotency_key'])->first();
            if (!$existing) throw $exception;
            $this->assertIdempotencyMatch($existing, $contract, $providerName, $currency, $amount, $input['milestone_id'] ?? null);
            return $existing;
        }

        if (!$wasCreated) return $payment;

        try {
            $provider = $this->providers->for($providerName);
            $intent = $provider->createIntent($payment);
            $payment->update([
                'provider_payment_id' => $intent['provider_payment_id'],
                'status' => $intent['status'] === 'pending' ? 'pending' : 'initiated',
            ]);
        } catch (RuntimeException $exception) {
            $payment->update(['status' => 'failed', 'failed_at' => now()]);
            throw $exception;
        }

        return $payment->fresh();
    }

    public function findForParticipant(PaymentTransaction $payment, int $userId): PaymentTransaction
    {
        if (!$payment->contract || !$payment->contract->involvesUser($userId)) {
            throw new PaymentAccessDeniedException();
        }
        return $payment;
    }

    public function processWebhook(string $providerName, string $payload, string $signature): PaymentTransaction
    {
        $provider = $this->providers->for($providerName);
        if (!$provider->verifyWebhookSignature($payload, $signature)) {
            throw new InvalidWebhookSignatureException();
        }
        $event = $provider->parseWebhook($payload);
        $payment = PaymentTransaction::where('provider', $providerName)
            ->where(function ($query) use ($event) {
                $query->where('provider_payment_id', $event['provider_payment_id'])
                    ->orWhere('provider_event_id', $event['event_id']);
            })->first();
        if (!$payment) {
            throw ValidationException::withMessages(['payment' => ['Payment record not found.']]);
        }
        if ($payment->provider_event_id === $event['event_id']) {
            if ($payment->status === 'succeeded') $this->escrow->holdConfirmedPayment($payment);
            return $payment;
        }
        if ($event['amount'] !== null && $this->cents($event['amount']) !== $this->cents($payment->amount)) {
            throw ValidationException::withMessages(['amount' => ['Webhook amount does not match the payment.']]);
        }
        if ($event['currency'] !== null && strtoupper($event['currency']) !== strtoupper($payment->currency)) {
            throw ValidationException::withMessages(['currency' => ['Webhook currency does not match the payment.']]);
        }
        if (!in_array($event['status'], ['pending', 'succeeded', 'failed', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => ['Unsupported provider payment status.']]);
        }
        if (in_array($payment->status, ['succeeded', 'failed', 'cancelled'], true)) {
            if ($payment->status === 'succeeded') $this->escrow->holdConfirmedPayment($payment);
            return $payment;
        }

        $updates = ['status' => $event['status'], 'provider_event_id' => $event['event_id']];
        if ($event['status'] === 'succeeded') $updates['paid_at'] = now();
        if ($event['status'] === 'failed') $updates['failed_at'] = now();
        DB::transaction(function () use ($payment, $updates, $event) {
            $payment->update($updates);
            if ($event['status'] === 'succeeded') $this->escrow->holdConfirmedPayment($payment->fresh());
        });
        return $payment->fresh();
    }

    private function assertIdempotencyMatch(PaymentTransaction $existing, Contract $contract, string $provider, string $currency, $amount, $milestoneId): void
    {
        if ((int) $existing->contract_id !== (int) $contract->id
            || (int) $existing->milestone_id !== (int) ($milestoneId ?: 0)
            || $existing->provider !== $provider
            || $this->cents($existing->amount) !== $this->cents($amount)
            || strtoupper($existing->currency) !== strtoupper($currency)) {
            throw ValidationException::withMessages(['idempotency_key' => ['This idempotency key was already used for different payment details.']]);
        }
    }

    private function cents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}

class InvalidWebhookSignatureException extends RuntimeException
{
}

class PaymentAccessDeniedException extends RuntimeException
{
}
