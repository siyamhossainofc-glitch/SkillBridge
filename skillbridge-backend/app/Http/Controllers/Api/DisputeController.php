<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractMilestone;
use App\Models\Dispute;
use App\Services\AdminAuditService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DisputeController extends Controller
{
    public function store(Request $request, int $contractId): JsonResponse
    {
        $validated = $request->validate([
            'milestone_id' => ['sometimes', 'nullable', 'integer'],
            'reason' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim($value) === '') $fail('The reason must contain non-whitespace characters.');
            }],
            'description' => ['required', 'string', 'max:10000', function (string $attribute, mixed $value, \Closure $fail): void {
                if (trim($value) === '') $fail('The description must contain non-whitespace characters.');
            }],
        ]);

        try {
            $dispute = DB::transaction(function () use ($request, $contractId, $validated) {
                $contract = Contract::lockForUpdate()->find($contractId);
                if (!$contract) abort(404, 'Contract not found.');
                $userId = (int) $request->user()->id;
                if (!$contract->involvesUser($userId)) abort(403, 'Forbidden.');
                if (!in_array($contract->status, ['pending', 'active', 'completed'], true) || ($contract->status === 'completed' && !$contract->completed_at)) {
                    throw ValidationException::withMessages(['contract' => ['This contract is not in a valid state for a dispute.']]);
                }

                $milestone = null;
                if (!empty($validated['milestone_id'])) {
                    $milestone = ContractMilestone::where('id', $validated['milestone_id'])
                        ->where('contract_id', $contract->id)
                        ->first();
                    if (!$milestone) {
                        throw ValidationException::withMessages(['milestone_id' => ['The milestone does not belong to this contract.']]);
                    }
                }

                $scopeKey = $this->scopeKey($contract->id, $milestone?->id);
                if (Dispute::where('active_scope_key', $scopeKey)->exists()) {
                    throw ValidationException::withMessages(['dispute' => ['An active dispute already exists for this contract scope.']]);
                }

                $againstUserId = (int) $contract->seeker_id === $userId
                    ? (int) $contract->provider_id
                    : (int) $contract->seeker_id;

                return Dispute::create([
                    'contract_id' => $contract->id,
                    'raised_by' => $userId,
                    'against_user_id' => $againstUserId,
                    'milestone_id' => $milestone?->id,
                    'reason' => trim($validated['reason']),
                    'description' => trim($validated['description']),
                    'status' => 'open',
                    'active_scope_key' => $scopeKey,
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueConflict($exception)) {
                throw ValidationException::withMessages(['dispute' => ['An active dispute already exists for this contract scope.']]);
            }
            throw $exception;
        }

        return response()->json(['message' => 'Dispute opened.', 'dispute' => $this->participantPayload($dispute->load(['raisedBy', 'againstUser', 'milestone']))], 201);
    }

    public function index(Request $request, int $contractId): JsonResponse
    {
        $contract = Contract::find($contractId);
        if (!$contract) return response()->json(['message' => 'Contract not found.'], 404);
        if (!$contract->involvesUser((int) $request->user()->id)) return response()->json(['message' => 'Forbidden.'], 403);

        $disputes = $contract->disputes()->with(['raisedBy', 'againstUser', 'milestone'])->latest()->get();
        return response()->json(['disputes' => $disputes->map(fn (Dispute $dispute) => $this->participantPayload($dispute))->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $dispute = Dispute::with(['contract', 'raisedBy', 'againstUser', 'milestone'])->find($id);
        if (!$dispute) return response()->json(['message' => 'Dispute not found.'], 404);
        if (!$dispute->contract->involvesUser((int) $request->user()->id) && $request->user()->role !== 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        return response()->json(['dispute' => $this->fullPayload($dispute)]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $dispute = DB::transaction(function () use ($request, $id) {
            $dispute = Dispute::with('contract')->lockForUpdate()->find($id);
            if (!$dispute) abort(404, 'Dispute not found.');
            if (!$dispute->contract->involvesUser((int) $request->user()->id)) abort(403, 'Forbidden.');
            if ((int) $dispute->raised_by !== (int) $request->user()->id) abort(403, 'Only the dispute creator can cancel it.');
            if ($dispute->status !== 'open') throw ValidationException::withMessages(['status' => ['Only open disputes can be cancelled.']]);
            $dispute->update(['status' => 'cancelled', 'active_scope_key' => null]);
            return $dispute;
        });
        return response()->json(['message' => 'Dispute cancelled.', 'dispute' => $this->fullPayload($dispute->fresh()->load(['contract', 'raisedBy', 'againstUser', 'milestone']))]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $disputes = Dispute::with(['contract', 'raisedBy', 'againstUser', 'milestone'])
            ->whereIn('status', ['open', 'under_review'])
            ->latest()
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();
        return response()->json([
            'data' => $disputes->getCollection()->map(fn (Dispute $dispute) => $this->fullPayload($dispute))->values(),
            'meta' => ['current_page' => $disputes->currentPage(), 'last_page' => $disputes->lastPage(), 'per_page' => $disputes->perPage(), 'total' => $disputes->total()],
        ]);
    }

    public function adminShow(Request $request, int $id): JsonResponse
    {
        $dispute = Dispute::with(['contract', 'raisedBy', 'againstUser', 'milestone', 'resolvedBy'])->find($id);
        if (!$dispute) return response()->json(['message' => 'Dispute not found.'], 404);
        return response()->json(['dispute' => $this->fullPayload($dispute)]);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        return $this->adminDecision($request, $id, 'resolved');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->adminDecision($request, $id, 'rejected');
    }

    private function adminDecision(Request $request, int $id, string $status): JsonResponse
    {
        $validated = $request->validate(['resolution' => ['required', 'string', 'max:10000']]);
        $dispute = DB::transaction(function () use ($request, $id, $status, $validated) {
            $dispute = Dispute::with('contract')->lockForUpdate()->find($id);
            if (!$dispute) abort(404, 'Dispute not found.');
            if ((int) $request->user()->id === (int) $dispute->raised_by || (int) $request->user()->id === (int) $dispute->against_user_id) {
                abort(403, 'A contract participant cannot resolve its own dispute.');
            }
            if (!in_array($dispute->status, ['open', 'under_review'], true)) {
                throw ValidationException::withMessages(['status' => ['Only open or under-review disputes can be resolved.']]);
            }
            $dispute->update([
                'status' => $status,
                'resolution' => trim($validated['resolution']),
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
                'active_scope_key' => null,
            ]);
            AdminAuditService::record($request->user(), 'dispute.'.($status === 'resolved' ? 'resolved' : 'rejected'), 'dispute', $dispute->id, trim($validated['resolution']), ['contract_id' => $dispute->contract_id]);
            return $dispute;
        });
        return response()->json(['message' => 'Dispute '.($status === 'resolved' ? 'resolved' : 'rejected').'.', 'dispute' => $this->fullPayload($dispute->fresh()->load(['contract', 'raisedBy', 'againstUser', 'milestone', 'resolvedBy']))]);
    }

    private function scopeKey(int $contractId, ?int $milestoneId): string
    {
        return 'contract:'.$contractId.':'.($milestoneId ? 'milestone:'.$milestoneId : 'contract');
    }

    private function participantPayload(Dispute $dispute): array
    {
        return [
            'id' => $dispute->id,
            'contract_id' => $dispute->contract_id,
            'raised_by' => $dispute->raised_by,
            'against_user_id' => $dispute->against_user_id,
            'milestone_id' => $dispute->milestone_id,
            'reason' => $dispute->reason,
            'description' => $dispute->description,
            'status' => $dispute->status,
            'resolution' => $dispute->resolution,
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            'raised_by_user' => $this->userPayload($dispute->raisedBy),
            'against_user' => $this->userPayload($dispute->againstUser),
            'milestone' => $dispute->milestone ? ['id' => $dispute->milestone->id, 'title' => $dispute->milestone->title] : null,
        ];
    }

    private function fullPayload(Dispute $dispute): array
    {
        return array_merge($this->participantPayload($dispute), [
            'resolved_by' => $dispute->resolved_by,
            'resolved_by_user' => $this->userPayload($dispute->resolvedBy),
        ]);
    }

    private function userPayload($user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'role' => $user->role] : null;
    }

    private function isUniqueConflict(QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'unique') || str_contains(strtolower($exception->getMessage()), 'duplicate');
    }
}
