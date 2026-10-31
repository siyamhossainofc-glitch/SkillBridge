<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    public function store(Request $request, int $contractId): JsonResponse
    {
        $rawPayload = json_decode($request->getContent(), true);
        if (is_array($rawPayload)
            && array_key_exists('comment', $rawPayload)
            && is_string($rawPayload['comment'])
            && trim($rawPayload['comment']) === '') {
            throw ValidationException::withMessages([
                'comment' => ['The comment field must contain non-whitespace text.'],
            ]);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && trim((string) $value) === '') {
                    $fail('The '.$attribute.' field must contain non-whitespace text.');
                }
            }],
        ]);

        try {
            $review = DB::transaction(function () use ($request, $contractId, $validated) {
                $contract = Contract::lockForUpdate()->find($contractId);
                if (!$contract) abort(404, 'Contract not found.');
                $userId = (int) $request->user()->id;
                $seekerId = (int) $contract->seeker_id;
                $providerId = (int) $contract->provider_id;
                if ($seekerId === $providerId) {
                    throw ValidationException::withMessages(['contract' => ['A contract cannot be reviewed when both participants are the same user.']]);
                }
                if (!in_array($userId, [$seekerId, $providerId], true)) abort(403, 'Forbidden.');
                if ($contract->status !== 'completed' || !$contract->completed_at) {
                    throw ValidationException::withMessages(['contract' => ['Only legitimately completed contracts can be reviewed.']]);
                }

                $revieweeId = $userId === $seekerId ? $providerId : $seekerId;
                if ($revieweeId === $userId) {
                    throw ValidationException::withMessages(['reviewee_id' => ['You cannot review yourself.']]);
                }
                if (Review::where('contract_id', $contract->id)
                    ->where('reviewer_id', $userId)
                    ->where('reviewee_id', $revieweeId)
                    ->exists()) {
                    throw ValidationException::withMessages(['review' => ['You have already reviewed this participant for this contract.']]);
                }

                $review = Review::create([
                    'reviewer_id' => $userId,
                    'reviewee_id' => $revieweeId,
                    'contract_id' => $contract->id,
                    'rating' => $validated['rating'],
                    'comment' => isset($validated['comment']) ? trim($validated['comment']) : null,
                    'status' => 'published',
                ]);

                $average = Review::where('reviewee_id', $revieweeId)->where('status', 'published')->where('moderation_status', 'visible')->avg('rating');
                User::whereKey($revieweeId)->update(['rating' => $average === null ? 0 : round((float) $average, 2)]);
                return $review;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueConflict($exception)) {
                throw ValidationException::withMessages(['review' => ['You have already reviewed this participant for this contract.']]);
            }
            throw $exception;
        }

        return response()->json(['message' => 'Review submitted successfully', 'review' => $this->payload($review->load(['reviewer', 'reviewee']))], 201);
    }

    public function forContract(Request $request, int $contractId): JsonResponse
    {
        $contract = Contract::find($contractId);
        if (!$contract) return response()->json(['message' => 'Contract not found.'], 404);
        if (!$contract->involvesUser((int) $request->user()->id)) return response()->json(['message' => 'Forbidden.'], 403);

        $reviews = $contract->reviews()->where('status', 'published')->where('moderation_status', 'visible')->with(['reviewer:id,name,role', 'reviewee:id,name,role'])->latest()->get();
        return response()->json(['reviews' => $reviews->map(fn (Review $review) => $this->payload($review))->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $review = Review::with(['contract', 'reviewer:id,name,role', 'reviewee:id,name,role'])->find($id);
        if (!$review) return response()->json(['message' => 'Review not found.'], 404);
        if (!$review->contract->involvesUser((int) $request->user()->id)) return response()->json(['message' => 'Forbidden.'], 403);
        return response()->json(['review' => $this->payload($review)]);
    }

    public function forProvider(int $providerId): JsonResponse
    {
        $provider = User::whereKey($providerId)->where('role', 'provider')->where('moderation_status', 'active')->first();
        if (!$provider) return response()->json(['message' => 'Provider not found.'], 404);
        return $this->publicReviews($provider);
    }

    public function forUser(int $userId): JsonResponse
    {
        $user = User::where('moderation_status', 'active')->find($userId);
        if (!$user) return response()->json(['message' => 'User not found.'], 404);
        return $this->publicReviews($user);
    }

    private function publicReviews(User $user): JsonResponse
    {
        $query = Review::where('reviewee_id', $user->id)->where('status', 'published')->where('moderation_status', 'visible');
        $reviews = (clone $query)->with('reviewer:id,name,role')->latest()->get();
        $average = (clone $query)->avg('rating');
        return response()->json([
            'reviews' => $reviews->map(fn (Review $review) => $this->payload($review))->values(),
            'average_rating' => $average === null ? null : round((float) $average, 2),
            'review_count' => $reviews->count(),
        ]);
    }

    private function payload(Review $review): array
    {
        return [
            'id' => $review->id,
            'contract_id' => $review->contract_id,
            'reviewer_id' => $review->reviewer_id,
            'reviewee_id' => $review->reviewee_id,
            'rating' => (int) $review->rating,
            'comment' => $review->comment,
            'status' => $review->status,
            'created_at' => $review->created_at?->toIso8601String(),
            'reviewer' => $review->relationLoaded('reviewer') && $review->reviewer ? ['id' => $review->reviewer->id, 'name' => $review->reviewer->name, 'role' => $review->reviewer->role] : null,
            'reviewee' => $review->relationLoaded('reviewee') && $review->reviewee ? ['id' => $review->reviewee->id, 'name' => $review->reviewee->name, 'role' => $review->reviewee->role] : null,
        ];
    }

    private function isUniqueConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }
}
