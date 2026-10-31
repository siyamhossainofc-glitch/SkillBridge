<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Models\JobPost;
use App\Services\FraudDetectionService;
use App\Services\ProposalAssistanceService;
use Illuminate\Http\Request;

class TrustIntelligenceController extends Controller
{
    public function proposal(Request $request, $jobId, ProposalAssistanceService $service)
    {
        $job = JobPost::with(['requiredSkills:id,name,slug'])->find($jobId);
        $provider = $request->user();
        if (!$job || $job->status !== 'open' || $job->moderation_status !== 'visible' || $job->user_id === $provider->id || $provider->role !== 'provider' || $provider->moderation_status !== 'active') {
            return response()->json(['message' => 'Proposal assistance is unavailable for this job.'], 404);
        }
        $validated = $request->validate([
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'draft_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $provider->load([
            'skills:id,name,slug,category_id',
            'services' => fn ($query) => $query->where('status', 'published')->where('moderation_status', 'visible')->select(['id', 'provider_id', 'title', 'status', 'moderation_status']),
        ]);
        return response()->json([
            'job_id' => $job->id,
            'proposal' => $service->draft($job, $provider, array_key_exists('amount', $validated) && $validated['amount'] !== null ? (float) $validated['amount'] : null, $validated['draft_message'] ?? null),
        ]);
    }

    public function fraudForBid($bidId, FraudDetectionService $service)
    {
        $bid = Bid::with(['provider.verification', 'jobPost'])->find($bidId);
        if (!$bid) return response()->json(['message' => 'Bid not found'], 404);
        return response()->json([
            'bid_id' => $bid->id,
            'analysis' => $service->analyze($bid),
        ]);
    }
}
