<?php

namespace App\Services\Escrow;

use App\Models\Contract;
use App\Models\EscrowAccount;
use App\Models\EscrowEntry;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EscrowService
{
    public function holdConfirmedPayment(PaymentTransaction $payment): EscrowEntry
    {
        return DB::transaction(function () use ($payment) {
            $lockedPayment = PaymentTransaction::lockForUpdate()->findOrFail($payment->id);
            if ($lockedPayment->status !== 'succeeded') {
                throw ValidationException::withMessages(['payment' => ['Only a provider-confirmed payment can be held.']]);
            }
            $contract = Contract::lockForUpdate()->findOrFail($lockedPayment->contract_id);
            $account = $this->accountForLockedContract($contract);
            $key = 'payment-hold-'.$lockedPayment->id;
            $existing = EscrowEntry::where('idempotency_key', $key)->first();
            if ($existing) return $existing;

            $this->ensureNonNegativeAndWithinContract($account, $contract, $lockedPayment->amount);
            $entry = $account->entries()->create([
                'contract_id' => $contract->id,
                'payment_transaction_id' => $lockedPayment->id,
                'milestone_id' => $lockedPayment->milestone_id,
                'amount' => $lockedPayment->amount,
                'type' => 'hold',
                'status' => 'held',
                'idempotency_key' => $key,
                'metadata' => ['source' => 'payment_webhook', 'provider' => $lockedPayment->provider],
            ]);
            $account->update([
                'balance' => $this->decimalFromCents($this->cents($account->balance) + $this->cents($lockedPayment->amount)),
                'status' => 'open',
            ]);
            return $entry;
        });
    }

    public function showForParticipant(Contract $contract, int $userId): EscrowAccount
    {
        if (!$contract->involvesUser($userId)) throw new EscrowAccessDeniedException();
        return EscrowAccount::with(['entries' => fn ($query) => $query->latest()])->firstOrCreate(
            ['contract_id' => $contract->id],
            ['currency' => config('services.payments.currency', 'BDT'), 'balance' => 0, 'status' => 'open']
        );
    }

    public function release(Contract $contract, int $userId, array $input): EscrowEntry
    {
        return $this->settle($contract, $userId, $input, 'release');
    }

    public function refund(Contract $contract, int $userId, array $input): EscrowEntry
    {
        return $this->settle($contract, $userId, $input, 'refund');
    }

    private function settle(Contract $contract, int $userId, array $input, string $type): EscrowEntry
    {
        try {
            return DB::transaction(function () use ($contract, $userId, $input, $type) {
                $lockedContract = Contract::lockForUpdate()->findOrFail($contract->id);
                if (!$lockedContract->involvesUser($userId)) throw new EscrowAccessDeniedException();
                if ((int) $lockedContract->seeker_id !== $userId) {
                    throw new EscrowAccessDeniedException('Only the contract seeker can settle escrow.');
                }
                if ($type === 'release' && $lockedContract->status !== 'completed') {
                    throw ValidationException::withMessages(['contract' => ['Only completed contracts can release escrow.']]);
                }
                if ($type === 'refund' && !in_array($lockedContract->status, ['cancelled', 'disputed'], true)) {
                    throw ValidationException::withMessages(['contract' => ['Refunds require a cancelled or disputed contract.']]);
                }

                $account = EscrowAccount::where('contract_id', $lockedContract->id)->lockForUpdate()->first();
                if (!$account) throw ValidationException::withMessages(['escrow' => ['No escrow account exists for this contract.']]);
                $existing = EscrowEntry::where('idempotency_key', $input['idempotency_key'])->first();
                if ($existing) {
                    $requested = $input['amount'] ?? $existing->amount;
                    if ((int) $existing->escrow_account_id !== (int) $account->id || $existing->type !== $type || $this->cents($existing->amount) !== $this->cents($requested)) {
                        throw ValidationException::withMessages(['idempotency_key' => ['This idempotency key was already used for different escrow details.']]);
                    }
                    return $existing;
                }

                $balance = $this->cents($account->balance);
                if ($balance <= 0) throw ValidationException::withMessages(['escrow' => ['Escrow has no held balance available.']]);
                $requested = $input['amount'] ?? $account->balance;
                if ($this->cents($requested) !== $balance) {
                    throw ValidationException::withMessages(['amount' => ['Partial escrow settlement is not enabled; amount must equal the held balance.']]);
                }

                $heldEntries = $account->entries()
                    ->where('type', 'hold')
                    ->where('status', 'held')
                    ->lockForUpdate()
                    ->get();
                $sourcePaymentIds = $heldEntries->pluck('payment_transaction_id')->filter()->unique()->values()->all();
                $sourceMilestoneIds = $heldEntries->pluck('milestone_id')->filter()->unique()->values()->all();
                $sourceEntryIds = $heldEntries->pluck('id')->values()->all();
                $settlementPaymentId = count($sourcePaymentIds) === 1 ? $sourcePaymentIds[0] : null;
                $settlementMilestoneId = count($sourceMilestoneIds) === 1 ? $sourceMilestoneIds[0] : null;

                $transaction = Transaction::create([
                    'contract_id' => $lockedContract->id,
                    'payer_id' => $type === 'release' ? $lockedContract->seeker_id : $lockedContract->provider_id,
                    'payee_id' => $type === 'release' ? $lockedContract->provider_id : $lockedContract->seeker_id,
                    'amount' => $account->balance,
                    'platform_fee' => 0,
                    'type' => $type,
                    'status' => $type === 'release' ? 'released' : 'refunded',
                ]);
                $entry = $account->entries()->create([
                    'contract_id' => $lockedContract->id,
                    'payment_transaction_id' => $settlementPaymentId,
                    'milestone_id' => $settlementMilestoneId,
                    'transaction_id' => $transaction->id,
                    'amount' => $account->balance,
                    'type' => $type,
                    'status' => $type === 'release' ? 'released' : 'refunded',
                    'idempotency_key' => $input['idempotency_key'],
                    'metadata' => [
                        'source' => 'escrow_settlement',
                        'transaction_id' => $transaction->id,
                        'source_escrow_entry_ids' => $sourceEntryIds,
                        'source_payment_transaction_ids' => $sourcePaymentIds,
                        'source_milestone_ids' => $sourceMilestoneIds,
                    ],
                ]);
                $account->update(['balance' => 0, 'status' => 'closed']);
                return $entry->fresh();
            });
        } catch (QueryException $exception) {
            $existing = EscrowEntry::where('idempotency_key', $input['idempotency_key'])->first();
            if ($existing) return $existing;
            throw $exception;
        }
    }

    private function accountForLockedContract(Contract $contract): EscrowAccount
    {
        return EscrowAccount::where('contract_id', $contract->id)->lockForUpdate()->first()
            ?? EscrowAccount::create([
                'contract_id' => $contract->id,
                'currency' => config('services.payments.currency', 'BDT'),
                'balance' => 0,
                'status' => 'open',
            ]);
    }

    private function ensureNonNegativeAndWithinContract(EscrowAccount $account, Contract $contract, $amount): void
    {
        if ($this->cents($account->balance) < 0 || $this->cents($account->balance) + $this->cents($amount) > $this->cents($contract->amount)) {
            throw ValidationException::withMessages(['escrow' => ['Escrow balance cannot exceed the contract amount.']]);
        }
    }

    private function cents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function decimalFromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

class EscrowAccessDeniedException extends \RuntimeException
{
}
