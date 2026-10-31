<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * All transactions belonging to the authenticated user, as payer or payee.
     * Transactions are system-generated from the contract lifecycle only —
     * there is no manual "create transaction" endpoint.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $transactions = Transaction::where('payer_id', $user->id)
            ->orWhere('payee_id', $user->id)
            ->with('contract:id,job_post_id,status')
            ->latest()
            ->get();

        return response()->json([
            'transactions' => $transactions,
        ]);
    }

    public function forContract(Request $request, $contractId)
    {
        $contract = Contract::find($contractId);

        if (!$contract) {
            return response()->json([
                'message' => 'Contract not found'
            ], 404);
        }

        if (!$contract->involvesUser($request->user()->id)) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        $transactions = $contract->transactions()->latest()->get();

        return response()->json([
            'transactions' => $transactions,
        ]);
    }
}
