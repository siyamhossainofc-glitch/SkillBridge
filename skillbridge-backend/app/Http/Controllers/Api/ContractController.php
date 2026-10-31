<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContractController extends Controller
{
    protected const PLATFORM_FEE_RATE = 0.10;

    public function index(Request $request)
    {
        $user = $request->user();

        $contracts = Contract::where('seeker_id', $user->id)
            ->orWhere('provider_id', $user->id)
            ->with(['jobPost:id,title,status', 'provider:id,name,rating', 'seeker:id,name,rating'])
            ->latest()
            ->get();

        return response()->json(['contracts' => $contracts]);
    }

    public function show(Request $request, $id)
    {
        $contract = Contract::with([
            'jobPost', 'provider:id,name,email,rating', 'seeker:id,name,email,rating',
            'bid', 'transactions',
        ])->find($id);

        if (!$contract) return response()->json(['message' => 'Contract not found'], 404);
        if (!$contract->involvesUser($request->user()->id)) return response()->json(['message' => 'Forbidden'], 403);

        return response()->json(['contract' => $contract]);
    }

    public function start(Request $request, $id)
    {
        $result = DB::transaction(function () use ($request, $id) {
            $contract = Contract::lockForUpdate()->find($id);

            if (!$contract) return ['error' => ['message' => 'Contract not found', 'status' => 404]];
            if (!$contract->involvesUser($request->user()->id)) return ['error' => ['message' => 'Forbidden', 'status' => 403]];
            // Only the seeker can start a contract — starting creates the escrow
            // deposit (payer = seeker) and commits the job to the active lifecycle.
            if ($request->user()->id !== $contract->seeker_id) {
                return ['error' => ['message' => 'Only the seeker can start a contract', 'status' => 403]];
            }
            if ($contract->status !== 'pending') return ['error' => ['message' => 'Only pending contracts can be started', 'status' => 422]];

            if ($contract->transactions()->where('type', 'deposit')->exists()) {
                return ['error' => ['message' => 'Escrow has already been funded for this contract', 'status' => 422]];
            }

            $contract->update(['status' => 'active', 'started_at' => now()]);

            Transaction::create([
                'contract_id' => $contract->id,
                'payer_id' => $contract->seeker_id,
                'payee_id' => $contract->provider_id,
                'amount' => $contract->amount,
                'platform_fee' => round($contract->amount * self::PLATFORM_FEE_RATE, 2),
                'type' => 'deposit',
                'status' => 'held',
            ]);

            return ['contract' => $contract->fresh()];
        });

        if (isset($result['error'])) return response()->json(['message' => $result['error']['message']], $result['error']['status']);

        return response()->json(['message' => 'Contract started', 'contract' => $result['contract']]);
    }

    public function complete(Request $request, $id)
    {
        $result = DB::transaction(function () use ($request, $id) {
            $contract = Contract::lockForUpdate()->find($id);

            if (!$contract) return ['error' => ['message' => 'Contract not found', 'status' => 404]];
            if (!$contract->involvesUser($request->user()->id)) return ['error' => ['message' => 'Forbidden', 'status' => 403]];
            // Only the seeker can mark a contract complete.  This action creates
            // the release transaction (funds flow to the provider), so allowing
            // the provider to call it would let them release escrow to themselves.
            if ($request->user()->id !== $contract->seeker_id) {
                return ['error' => ['message' => 'Only the seeker can mark a contract as complete', 'status' => 403]];
            }
            if ($contract->status !== 'active') return ['error' => ['message' => 'Only active contracts can be completed', 'status' => 422]];

            if (!$contract->transactions()->where('type', 'deposit')->where('status', 'held')->exists()) {
                return ['error' => ['message' => 'No held escrow found for this contract', 'status' => 422]];
            }

            if ($contract->transactions()->where('type', 'release')->exists()) {
                return ['error' => ['message' => 'Escrow has already been released for this contract', 'status' => 422]];
            }

            $contract->update(['status' => 'completed', 'completed_at' => now()]);
            $contract->jobPost()->update(['status' => 'completed']);

            $platformFee = round($contract->amount * self::PLATFORM_FEE_RATE, 2);

            Transaction::create([
                'contract_id' => $contract->id,
                'payer_id' => $contract->seeker_id,
                'payee_id' => $contract->provider_id,
                'amount' => $contract->amount - $platformFee,
                'platform_fee' => $platformFee,
                'type' => 'release',
                'status' => 'released',
            ]);

            return ['contract' => $contract->fresh()];
        });

        if (isset($result['error'])) return response()->json(['message' => $result['error']['message']], $result['error']['status']);

        return response()->json(['message' => 'Contract marked as completed', 'contract' => $result['contract']]);
    }

    /**
     * Explicit "hold deposit" action for the frontend's Contract Detail page.
     * The deposit is normally created automatically inside start(); this
     * endpoint is idempotent so clicking it never creates a second deposit.
     */
    public function deposit(Request $request, $id)
    {
        $result = DB::transaction(function () use ($request, $id) {
            $contract = Contract::lockForUpdate()->find($id);

            if (!$contract) return ['error' => ['message' => 'Contract not found', 'status' => 404]];
            if (!$contract->involvesUser($request->user()->id)) return ['error' => ['message' => 'Forbidden', 'status' => 403]];
            // Only the seeker can fund the escrow — they are the payer on every
            // deposit transaction.  A provider cannot hold funds on the seeker's behalf.
            if ($request->user()->id !== $contract->seeker_id) {
                return ['error' => ['message' => 'Only the seeker can fund the escrow deposit', 'status' => 403]];
            }
            if (!in_array($contract->status, ['pending', 'active'], true)) {
                return ['error' => ['message' => 'Deposit can only be held for a pending or active contract', 'status' => 422]];
            }

            $existing = $contract->transactions()->where('type', 'deposit')->first();
            if ($existing) {
                return ['transaction' => $existing, 'message' => 'Escrow already funded for this contract'];
            }

            $transaction = Transaction::create([
                'contract_id' => $contract->id,
                'payer_id' => $contract->seeker_id,
                'payee_id' => $contract->provider_id,
                'amount' => $contract->amount,
                'platform_fee' => round($contract->amount * self::PLATFORM_FEE_RATE, 2),
                'type' => 'deposit',
                'status' => 'held',
            ]);

            return ['transaction' => $transaction, 'message' => 'Deposit held'];
        });

        if (isset($result['error'])) return response()->json(['message' => $result['error']['message']], $result['error']['status']);

        return response()->json(['message' => $result['message'], 'transaction' => $result['transaction']]);
    }

    /**
     * Explicit "release funds" action. The release transaction is normally
     * created automatically inside complete(); this endpoint is idempotent.
     */
    public function release(Request $request, $id)
    {
        $result = DB::transaction(function () use ($request, $id) {
            $contract = Contract::lockForUpdate()->find($id);

            if (!$contract) return ['error' => ['message' => 'Contract not found', 'status' => 404]];
            if (!$contract->involvesUser($request->user()->id)) return ['error' => ['message' => 'Forbidden', 'status' => 403]];
            // Only the seeker can release held escrow to the provider.
            // Allowing the provider to call this endpoint would let them
            // release funds to themselves without seeker approval.
            if ($request->user()->id !== $contract->seeker_id) {
                return ['error' => ['message' => 'Only the seeker can release escrow funds', 'status' => 403]];
            }
            if ($contract->status !== 'completed') return ['error' => ['message' => 'Only completed contracts can release funds', 'status' => 422]];

            if (!$contract->transactions()->where('type', 'deposit')->where('status', 'held')->exists()) {
                return ['error' => ['message' => 'No held escrow found for this contract', 'status' => 422]];
            }

            $existing = $contract->transactions()->where('type', 'release')->first();
            if ($existing) {
                return ['transaction' => $existing, 'message' => 'Funds already released for this contract'];
            }

            $platformFee = round($contract->amount * self::PLATFORM_FEE_RATE, 2);

            $transaction = Transaction::create([
                'contract_id' => $contract->id,
                'payer_id' => $contract->seeker_id,
                'payee_id' => $contract->provider_id,
                'amount' => $contract->amount - $platformFee,
                'platform_fee' => $platformFee,
                'type' => 'release',
                'status' => 'released',
            ]);

            return ['transaction' => $transaction, 'message' => 'Funds released'];
        });

        if (isset($result['error'])) return response()->json(['message' => $result['error']['message']], $result['error']['status']);

        return response()->json(['message' => $result['message'], 'transaction' => $result['transaction']]);
    }

    /**
     * Explicit "refund deposit" action. The refund transaction is normally
     * created automatically inside cancel(); this endpoint is idempotent.
     */
    public function refund(Request $request, $id)
    {
        $result = DB::transaction(function () use ($request, $id) {
            $contract = Contract::lockForUpdate()->find($id);

            if (!$contract) return ['error' => ['message' => 'Contract not found', 'status' => 404]];
            if (!$contract->involvesUser($request->user()->id)) return ['error' => ['message' => 'Forbidden', 'status' => 403]];
            // Only the seeker can claim a refund — the deposit was theirs and
            // the refund payee is the seeker.  A provider cannot issue a refund
            // to the other party on their behalf.
            if ($request->user()->id !== $contract->seeker_id) {
                return ['error' => ['message' => 'Only the seeker can request a deposit refund', 'status' => 403]];
            }
            if (!in_array($contract->status, ['cancelled', 'disputed'], true)) {
                return ['error' => ['message' => 'Refunds are only available for cancelled or disputed contracts', 'status' => 422]];
            }

            if (!$contract->transactions()->where('type', 'deposit')->where('status', 'held')->exists()) {
                return ['error' => ['message' => 'No held escrow found for this contract', 'status' => 422]];
            }

            $existing = $contract->transactions()->where('type', 'refund')->first();
            if ($existing) {
                return ['transaction' => $existing, 'message' => 'Deposit already refunded for this contract'];
            }

            $transaction = Transaction::create([
                'contract_id' => $contract->id,
                'payer_id' => $contract->provider_id,
                'payee_id' => $contract->seeker_id,
                'amount' => $contract->amount,
                'platform_fee' => 0,
                'type' => 'refund',
                'status' => 'refunded',
            ]);

            return ['transaction' => $transaction, 'message' => 'Refund recorded'];
        });

        if (isset($result['error'])) return response()->json(['message' => $result['error']['message']], $result['error']['status']);

        return response()->json(['message' => $result['message'], 'transaction' => $result['transaction']]);
    }

    public function cancel(Request $request, $id)
    {
        $result = DB::transaction(function () use ($request, $id) {
            $contract = Contract::lockForUpdate()->find($id);

            if (!$contract) return ['error' => ['message' => 'Contract not found', 'status' => 404]];
            if (!$contract->involvesUser($request->user()->id)) return ['error' => ['message' => 'Forbidden', 'status' => 403]];
            if (!in_array($contract->status, ['pending', 'active'], true)) return ['error' => ['message' => 'Only pending or active contracts can be cancelled', 'status' => 422]];

            $wasActive = $contract->status === 'active';
            $contract->update(['status' => 'cancelled']);
            $contract->jobPost()->update(['status' => 'open']);

            if ($wasActive && $contract->transactions()->where('type', 'refund')->exists() === false) {
                Transaction::create([
                    'contract_id' => $contract->id,
                    'payer_id' => $contract->provider_id,
                    'payee_id' => $contract->seeker_id,
                    'amount' => $contract->amount,
                    'platform_fee' => 0,
                    'type' => 'refund',
                    'status' => 'refunded',
                ]);
            }

            return ['contract' => $contract->fresh()];
        });

        if (isset($result['error'])) return response()->json(['message' => $result['error']['message']], $result['error']['status']);

        return response()->json(['message' => 'Contract cancelled', 'contract' => $result['contract']]);
    }
}