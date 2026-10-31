<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProviderVerification;
use App\Services\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $verification = ProviderVerification::where('user_id', $request->user()->id)->first();

        return response()->json(['verification' => $this->providerPayload($verification)]);
    }

    public function submit(Request $request): JsonResponse
    {
        $user = $request->user();
        $verification = ProviderVerification::firstOrNew(['user_id' => $user->id]);

        if ($verification->exists && $verification->status === 'pending') {
            return response()->json(['message' => 'A verification request is already pending.'], 422);
        }

        if ($verification->exists && $verification->status === 'verified') {
            return response()->json(['message' => 'This provider is already verified.'], 422);
        }

        $verification->fill([
            'status' => 'pending',
            'submitted_at' => now(),
            'reviewed_at' => null,
            'reviewer_id' => null,
            'rejection_reason' => null,
        ])->save();

        return response()->json([
            'message' => 'Verification request submitted.',
            'verification' => $this->providerPayload($verification),
        ], 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $verifications = ProviderVerification::query()
            ->where('status', 'pending')
            ->with('user:id,name,email,professional_title,role')
            ->latest('submitted_at')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => $verifications->getCollection()->map(fn (ProviderVerification $verification) => $this->adminPayload($verification))->values(),
            'meta' => [
                'current_page' => $verifications->currentPage(),
                'last_page' => $verifications->lastPage(),
                'per_page' => $verifications->perPage(),
                'total' => $verifications->total(),
            ],
        ]);
    }

    public function adminShow(Request $request, int $id): JsonResponse
    {
        $verification = ProviderVerification::with('user:id,name,email,professional_title,role')->find($id);

        if (!$verification) {
            return response()->json(['message' => 'Verification request not found.'], 404);
        }

        return response()->json(['verification' => $this->adminPayload($verification)]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $verification = ProviderVerification::find($id);

        if (!$verification) {
            return response()->json(['message' => 'Verification request not found.'], 404);
        }
        if ($verification->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be approved.'], 422);
        }

        $verification->update([
            'status' => 'verified',
            'reviewed_at' => now(),
            'reviewer_id' => $request->user()->id,
            'rejection_reason' => null,
        ]);
        AdminAuditService::record($request->user(), 'verification.approved', 'verification', $verification->id, null, ['provider_id' => $verification->user_id]);

        return response()->json([
            'message' => 'Provider verification approved.',
            'verification' => $this->adminPayload($verification->fresh()->load('user:id,name,email,professional_title,role')),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);
        $verification = ProviderVerification::find($id);

        if (!$verification) {
            return response()->json(['message' => 'Verification request not found.'], 404);
        }
        if ($verification->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be rejected.'], 422);
        }

        $verification->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
            'reviewer_id' => $request->user()->id,
            'rejection_reason' => trim($validated['rejection_reason']),
        ]);
        AdminAuditService::record($request->user(), 'verification.rejected', 'verification', $verification->id, trim($validated['rejection_reason']), ['provider_id' => $verification->user_id]);

        return response()->json([
            'message' => 'Provider verification rejected.',
            'verification' => $this->adminPayload($verification->fresh()->load('user:id,name,email,professional_title,role')),
        ]);
    }

    private function providerPayload(?ProviderVerification $verification): array
    {
        return [
            'status' => $verification?->status ?? 'unverified',
            'submitted_at' => $verification?->submitted_at?->toIso8601String(),
            'reviewed_at' => $verification?->reviewed_at?->toIso8601String(),
            'rejection_reason' => $verification?->status === 'rejected' ? $verification->rejection_reason : null,
            'is_verified' => $verification?->status === 'verified',
        ];
    }

    private function adminPayload(ProviderVerification $verification): array
    {
        return [
            'id' => $verification->id,
            'status' => $verification->status,
            'submitted_at' => $verification->submitted_at?->toIso8601String(),
            'reviewed_at' => $verification->reviewed_at?->toIso8601String(),
            'reviewer_id' => $verification->reviewer_id,
            'rejection_reason' => $verification->rejection_reason,
            'provider' => $verification->user ? [
                'id' => $verification->user->id,
                'name' => $verification->user->name,
                'email' => $verification->user->email,
                'professional_title' => $verification->user->professional_title,
                'role' => $verification->user->role,
            ] : null,
        ];
    }
}
