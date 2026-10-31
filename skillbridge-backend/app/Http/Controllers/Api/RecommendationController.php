<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobPost;
use App\Services\RecommendationService;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function matches(Request $request, $jobId, RecommendationService $service)
    {
        $job = JobPost::with(['requiredSkills:id,name,slug'])->find($jobId);
        if (!$job || $job->user_id !== $request->user()->id || $job->moderation_status !== 'visible' || $job->status !== 'open') {
            return response()->json(['message' => 'Job not found'], 404);
        }

        return response()->json([
            'job_id' => $job->id,
            ...$service->matchProvidersWithAi($job),
        ]);
    }

    public function index(Request $request, RecommendationService $service)
    {
        return response()->json([
            'recommendations' => $service->recommendations($request->user()),
            'algorithm' => 'deterministic-v1',
        ]);
    }
}
