<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobPost;
use App\Models\SavedJob;
use Illuminate\Http\Request;

class SavedJobController extends Controller
{
    public function store(Request $request, $jobId)
    {
        $job = JobPost::findOrFail($jobId);
        $saved = SavedJob::firstOrCreate(['user_id' => $request->user()->id, 'job_post_id' => $job->id]);
        return response()->json(['message' => 'Job saved successfully', 'saved' => true, 'saved_job' => $saved], $saved->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, $jobId)
    {
        SavedJob::where('user_id', $request->user()->id)->where('job_post_id', $jobId)->delete();
        return response()->json(['message' => 'Job unsaved successfully', 'saved' => false]);
    }

    public function index(Request $request)
    {
        $saved = SavedJob::where('user_id', $request->user()->id)->with(['job' => function ($query) {
            $query->with(['categoryRelation:id,name,slug', 'subcategory:id,category_id,name,slug', 'seeker:id,name']);
        }])->latest()->get();
        return response()->json(['saved_jobs' => $saved->map(fn ($record) => ['id' => $record->id, 'saved_at' => $record->created_at, 'job' => $record->job])->values()]);
    }
}
