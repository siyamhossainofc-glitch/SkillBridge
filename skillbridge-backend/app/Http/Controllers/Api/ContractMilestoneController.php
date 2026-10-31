<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractMilestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractMilestoneController extends Controller
{
    public function index(Request $request, int $contractId): JsonResponse
    {
        $contract = $this->participantContract($request, $contractId);
        if ($contract instanceof JsonResponse) return $contract;

        return response()->json([
            'milestones' => $contract->milestones()->latest()->get(),
        ]);
    }

    public function store(Request $request, int $contractId): JsonResponse
    {
        $contract = $this->participantContract($request, $contractId);
        if ($contract instanceof JsonResponse) return $contract;
        if ((int) $request->user()->id !== (int) $contract->seeker_id) {
            return response()->json(['message' => 'Only the contract seeker can create milestones.'], 403);
        }
        if (!in_array($contract->status, ['pending', 'active'], true)) {
            return response()->json(['message' => 'Milestones can only be created for pending or active contracts.'], 422);
        }

        $validated = $request->validate($this->milestoneRules());
        $milestone = DB::transaction(function () use ($contract, $validated) {
            $lockedContract = Contract::lockForUpdate()->findOrFail($contract->id);
            $this->ensureTotalWithinContract($lockedContract, $validated['amount']);

            return $lockedContract->milestones()->create([
                ...$validated,
                'status' => 'pending',
            ]);
        });

        return response()->json(['message' => 'Milestone created.', 'milestone' => $milestone], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $milestone = ContractMilestone::with('contract')->find($id);
        $access = $this->milestoneAccess($request, $milestone);
        if ($access instanceof JsonResponse) return $access;
        if ((int) $request->user()->id !== (int) $milestone->contract->seeker_id) {
            return response()->json(['message' => 'Only the contract seeker can edit milestones.'], 403);
        }
        if (!in_array($milestone->status, ['pending', 'rejected'], true)) {
            return response()->json(['message' => 'Only pending or rejected milestones can be edited.'], 422);
        }
        if (!in_array($milestone->contract->status, ['pending', 'active'], true)) {
            return response()->json(['message' => 'Milestones cannot be edited in the current contract state.'], 422);
        }

        $validated = $request->validate($this->milestoneRules(true));
        $updatedMilestone = DB::transaction(function () use ($milestone, $validated) {
            $lockedContract = Contract::lockForUpdate()->findOrFail($milestone->contract_id);
            $lockedMilestone = ContractMilestone::lockForUpdate()->findOrFail($milestone->id);
            $amount = array_key_exists('amount', $validated) ? $validated['amount'] : $lockedMilestone->amount;
            $this->ensureTotalWithinContract($lockedContract, $amount, $lockedMilestone->id);
            $lockedMilestone->update($validated);

            return $lockedMilestone->fresh();
        });

        return response()->json(['message' => 'Milestone updated.', 'milestone' => $updatedMilestone]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $milestone = ContractMilestone::with('contract')->find($id);
        $access = $this->milestoneAccess($request, $milestone);
        if ($access instanceof JsonResponse) return $access;
        if ((int) $request->user()->id !== (int) $milestone->contract->seeker_id) {
            return response()->json(['message' => 'Only the contract seeker can delete milestones.'], 403);
        }
        if (!in_array($milestone->status, ['pending', 'rejected'], true)) {
            return response()->json(['message' => 'Only pending or rejected milestones can be deleted.'], 422);
        }
        if (!in_array($milestone->contract->status, ['pending', 'active'], true)) {
            return response()->json(['message' => 'Milestones cannot be deleted in the current contract state.'], 422);
        }

        $milestone->delete();
        return response()->json(['message' => 'Milestone deleted.']);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'start');
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'submit');
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'approve');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'reject');
    }

    private function transition(Request $request, int $id, string $action): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $id, $action) {
            $milestone = ContractMilestone::with('contract')->lockForUpdate()->find($id);
            $access = $this->milestoneAccess($request, $milestone);
            if ($access instanceof JsonResponse) return ['response' => $access];

            $contract = $milestone->contract;
            $userId = (int) $request->user()->id;
            if (!in_array($contract->status, ['active'], true)) {
                return ['response' => response()->json(['message' => 'Milestone work requires an active contract.'], 422)];
            }

            $updates = match ($action) {
                'start' => $userId === (int) $contract->provider_id && $milestone->status === 'pending'
                    ? ['status' => 'in_progress']
                    : null,
                'submit' => $userId === (int) $contract->provider_id && in_array($milestone->status, ['in_progress', 'rejected'], true)
                    ? ['status' => 'submitted', 'submitted_at' => now(), 'rejected_at' => null]
                    : null,
                'approve' => $userId === (int) $contract->seeker_id && $milestone->status === 'submitted'
                    ? ['status' => 'approved', 'approved_at' => now()]
                    : null,
                'reject' => $userId === (int) $contract->seeker_id && $milestone->status === 'submitted'
                    ? ['status' => 'rejected', 'rejected_at' => now(), 'submitted_at' => null]
                    : null,
                default => null,
            };

            if ($updates === null) {
                return ['response' => response()->json(['message' => 'This milestone action is not allowed in its current state or role.'], 422)];
            }

            $milestone->update($updates);
            return ['milestone' => $milestone->fresh()];
        });

        if (isset($result['response'])) return $result['response'];
        $messages = [
            'start' => 'Milestone started.',
            'submit' => 'Milestone submitted.',
            'approve' => 'Milestone approved.',
            'reject' => 'Milestone rejected.',
        ];
        return response()->json(['message' => $messages[$action], 'milestone' => $result['milestone']]);
    }

    private function milestoneRules(bool $sometimes = false): array
    {
        return [
            'title' => [$sometimes ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => [$sometimes ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:5000'],
            'amount' => [$sometimes ? 'sometimes' : 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'due_date' => [$sometimes ? 'sometimes' : 'nullable', 'nullable', 'date'],
        ];
    }

    private function ensureTotalWithinContract(Contract $contract, $amount, ?int $excludeMilestoneId = null): void
    {
        $query = $contract->milestones();
        if ($excludeMilestoneId !== null) {
            $query->where($query->getModel()->getKeyName(), '!=', $excludeMilestoneId);
        }

        $existingCents = (int) round(((float) $query->sum('amount')) * 100);
        $proposedCents = (int) round(((float) $amount) * 100);
        $contractCents = (int) round(((float) $contract->amount) * 100);

        if ($existingCents + $proposedCents > $contractCents) {
            throw ValidationException::withMessages([
                'amount' => [sprintf(
                    'The total milestone amount cannot exceed the contract amount of %s.',
                    number_format((float) $contract->amount, 2, '.', '')
                )],
            ]);
        }
    }

    private function participantContract(Request $request, int $contractId): Contract|JsonResponse
    {
        $contract = Contract::find($contractId);
        if (!$contract) return response()->json(['message' => 'Contract not found.'], 404);
        if (!$contract->involvesUser((int) $request->user()->id)) return response()->json(['message' => 'Forbidden.'], 403);
        return $contract;
    }

    private function milestoneAccess(Request $request, ?ContractMilestone $milestone): ?JsonResponse
    {
        if (!$milestone) return response()->json(['message' => 'Milestone not found.'], 404);
        if (!$milestone->contract || !$milestone->contract->involvesUser((int) $request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        return null;
    }
}
