<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\EscrowAccount;
use App\Services\Escrow\EscrowAccessDeniedException;
use App\Services\Escrow\EscrowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EscrowController extends Controller
{
    public function __construct(private readonly EscrowService $escrow)
    {
    }

    public function show(Request $request, int $contractId): JsonResponse
    {
        $contract = Contract::find($contractId);
        if (!$contract) return response()->json(['message' => 'Contract not found.'], 404);
        try {
            $account = $this->escrow->showForParticipant($contract, (int) $request->user()->id);
        } catch (EscrowAccessDeniedException $exception) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        return response()->json(['escrow' => $this->accountPayload($account)]);
    }

    public function release(Request $request, int $contractId): JsonResponse
    {
        return $this->settle($request, $contractId, 'release');
    }

    public function refund(Request $request, int $contractId): JsonResponse
    {
        return $this->settle($request, $contractId, 'refund');
    }

    public function byId(Request $request, int $id): JsonResponse
    {
        $entry = \App\Models\EscrowEntry::with('contract')->find($id);
        if (!$entry) return response()->json(['message' => 'Escrow entry not found.'], 404);
        if (!$entry->contract->involvesUser((int) $request->user()->id)) return response()->json(['message' => 'Forbidden.'], 403);
        return response()->json(['entry' => $this->entryPayload($entry)]);
    }

    private function settle(Request $request, int $contractId, string $type): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);
        $contract = Contract::find($contractId);
        if (!$contract) return response()->json(['message' => 'Contract not found.'], 404);
        try {
            $entry = $type === 'release'
                ? $this->escrow->release($contract, (int) $request->user()->id, $validated)
                : $this->escrow->refund($contract, (int) $request->user()->id, $validated);
        } catch (EscrowAccessDeniedException $exception) {
            return response()->json(['message' => $exception->getMessage() ?: 'Forbidden.'], 403);
        }
        return response()->json(['message' => ucfirst($type).' recorded.', 'entry' => $this->entryPayload($entry)]);
    }

    private function accountPayload(EscrowAccount $account): array
    {
        return [
            'id' => $account->id,
            'contract_id' => $account->contract_id,
            'currency' => $account->currency,
            'balance' => $account->balance,
            'status' => $account->status,
            'entries' => $account->entries->map(fn ($entry) => $this->entryPayload($entry))->values(),
        ];
    }

    private function entryPayload($entry): array
    {
        return [
            'id' => $entry->id,
            'contract_id' => $entry->contract_id,
            'payment_transaction_id' => $entry->payment_transaction_id,
            'milestone_id' => $entry->milestone_id,
            'transaction_id' => $entry->transaction_id,
            'amount' => $entry->amount,
            'type' => $entry->type,
            'status' => $entry->status,
            'metadata' => $entry->metadata,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
