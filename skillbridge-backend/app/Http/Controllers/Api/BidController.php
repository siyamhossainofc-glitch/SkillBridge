<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Models\Contract;
use App\Models\JobPost;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BidController extends Controller
{
    public function store(Request $request, $jobId)
    {
        $job = JobPost::find($jobId);

        if (!$job) {
            return response()->json(['message' => 'Job not found'], 404);
        }

        if ($job->status !== 'open') {
            return response()->json(['message' => 'This job is not open for bids'], 422);
        }

        $user = $request->user();

        if ($job->user_id === $user->id) {
            return response()->json(['message' => 'You cannot bid on your own job'], 422);
        }

        if ($job->bids()->where('provider_id', $user->id)->exists()) {
            return response()->json(['message' => 'You have already placed a bid on this job'], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'message' => 'sometimes|nullable|string|max:2000',
        ]);

        $bid = $job->bids()->create([
            'provider_id' => $user->id,
            'amount' => $validated['amount'],
            'message' => $validated['message'] ?? null,
            'status' => 'pending',
        ]);

        Notification::createOnce(
            $job->user_id,
            'bid.received',
            'New bid received',
            "A provider submitted a bid on {$job->title}.",
            ['bid_id' => $bid->id, 'job_id' => $job->id, 'link' => "/jobs/{$job->id}"]
        );

        return response()->json([
            'message' => 'Bid placed successfully',
            'bid' => array_merge($bid->toArray(), ['status' => $bid->status]),
        ], 201);
    }

    public function myBids(Request $request)
    {
        $bids = $request->user()->bids()
            ->with('jobPost:id,title,status,category,budget_min,budget_max,location,work_mode')
            ->latest()
            ->get();

        return response()->json(['bids' => $bids]);
    }

    public function withdraw(Request $request, $id)
    {
        $bid = Bid::find($id);

        if (!$bid) {
            return response()->json(['message' => 'Bid not found'], 404);
        }

        if ($bid->provider_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($bid->status !== 'pending') {
            return response()->json(['message' => 'Only pending bids can be withdrawn'], 422);
        }

        $bid->update(['status' => 'withdrawn']);

        return response()->json([
            'message' => 'Bid withdrawn successfully',
            'bid' => $bid,
        ]);
    }

    public function accept(Request $request, $id)
    {
        $result = DB::transaction(function () use ($request, $id) {
            $bid = Bid::lockForUpdate()->find($id);

            if (!$bid) {
                return ['error' => ['message' => 'Bid not found', 'status' => 404]];
            }

            $job = JobPost::lockForUpdate()->find($bid->job_post_id);

            if (!$job || $job->user_id !== $request->user()->id) {
                return ['error' => ['message' => 'Forbidden', 'status' => 403]];
            }

            if ($job->status !== 'open') {
                return ['error' => ['message' => 'This job is no longer open', 'status' => 422]];
            }

            if ($bid->status !== 'pending') {
                return ['error' => ['message' => 'Only pending bids can be accepted', 'status' => 422]];
            }

            $existingContract = Contract::where('job_post_id', $job->id)->lockForUpdate()->first();

            if ($existingContract) {
                return ['error' => ['message' => 'This job already has a contract', 'status' => 422]];
            }

            $bid->update(['status' => 'accepted']);

            $rejectedBids = $job->bids()
                ->where('id', '!=', $bid->id)
                ->where('status', 'pending')
                ->get();

            foreach ($rejectedBids as $rejectedBid) {
                $rejectedBid->update(['status' => 'rejected']);
                Notification::createOnce(
                    $rejectedBid->provider_id,
                    'bid.rejected',
                    'Bid not selected',
                    "Your bid on {$job->title} was not selected.",
                    ['bid_id' => $rejectedBid->id, 'job_id' => $job->id, 'link' => '/my-bids']
                );
            }

            $job->update(['status' => 'in_progress']);

            Notification::createOnce(
                $bid->provider_id,
                'bid.accepted',
                'Bid accepted',
                "Your bid on {$job->title} was accepted.",
                ['bid_id' => $bid->id, 'job_id' => $job->id, 'link' => "/jobs/{$job->id}"]
            );

            $contract = Contract::create([
                'job_post_id' => $job->id,
                'provider_id' => $bid->provider_id,
                'seeker_id' => $job->user_id,
                'bid_id' => $bid->id,
                'amount' => $bid->amount,
                'status' => 'pending',
            ]);

            return ['contract' => $contract];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']['message']], $result['error']['status']);
        }

        return response()->json([
            'message' => 'Bid accepted, contract created',
            'contract' => $result['contract'],
        ], 201);
    }
}
