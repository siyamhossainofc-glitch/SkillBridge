<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\PaymentTransaction;
use App\Services\Payments\InvalidWebhookSignatureException;
use App\Services\Payments\PaymentAccessDeniedException;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function store(Request $request, int $contractId): JsonResponse
    {
        $validated = $request->validate([
            'milestone_id' => ['sometimes', 'nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'provider' => ['sometimes', 'nullable', 'string', 'in:mock'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);
        $contract = Contract::find($contractId);
        if (!$contract) return response()->json(['message' => 'Contract not found.'], 404);
        if (!$contract->involvesUser((int) $request->user()->id)) return response()->json(['message' => 'Forbidden.'], 403);

        try {
            $payment = $this->payments->createIntent($contract, (int) $request->user()->id, $validated);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => 'Unsupported payment provider.'], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => 'The payment provider could not create a payment intent.'], 502);
        }

        return response()->json([
            'message' => 'Payment intent created. Awaiting provider confirmation.',
            'payment' => $this->payload($payment),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $payment = PaymentTransaction::with('contract')->find($id);
        if (!$payment) return response()->json(['message' => 'Payment not found.'], 404);
        try {
            $payment = $this->payments->findForParticipant($payment, (int) $request->user()->id);
        } catch (PaymentAccessDeniedException $exception) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['payment' => $this->payload($payment)]);
    }

    public function webhook(Request $request, string $provider): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('X-Payment-Signature', '');
        try {
            $payment = $this->payments->processWebhook($provider, $payload, $signature);
        } catch (InvalidWebhookSignatureException $exception) {
            return response()->json(['message' => 'Invalid payment webhook signature.'], 401);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => 'Unsupported payment provider.'], 400);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => 'Payment webhook could not be processed.'], 400);
        }

        return response()->json(['received' => true, 'payment' => $this->payload($payment)]);
    }

    private function payload(PaymentTransaction $payment): array
    {
        return [
            'id' => $payment->id,
            'provider' => $payment->provider,
            'provider_payment_id' => $payment->provider_payment_id,
            'contract_id' => $payment->contract_id,
            'milestone_id' => $payment->milestone_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'failed_at' => $payment->failed_at?->toIso8601String(),
        ];
    }
}
