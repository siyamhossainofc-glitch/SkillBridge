<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobPost;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function conversations(Request $request)
    {
        $userId = $request->user()->id;

        $messages = Message::where('sender_id', $userId)
            ->orWhere('receiver_id', $userId)
            ->with(['sender:id,name', 'receiver:id,name', 'jobPost:id,title'])
            ->latest()
            ->get();

        $conversations = $messages
            ->groupBy(function ($message) use ($userId) {
                $otherId = $message->sender_id === $userId ? $message->receiver_id : $message->sender_id;
                return $message->job_post_id.'-'.$otherId;
            })
            ->map(function ($group) use ($userId) {
                $latest = $group->first();
                $other = $latest->sender_id === $userId ? $latest->receiver : $latest->sender;

                return [
                    'job_post_id' => $latest->job_post_id,
                    'job_title' => $latest->jobPost->title ?? null,
                    'other_user' => $other,
                    'last_message' => $latest->body,
                    'last_message_at' => $latest->created_at,
                    'unread_count' => $group->where('receiver_id', $userId)->whereNull('read_at')->count(),
                ];
            })
            ->values();

        return response()->json(['conversations' => $conversations]);
    }

    /**
     * Messaging is limited to the job owner and providers who have actually
     * bid on that job (or the provider already attached through a contract).
     */
    private function canParticipate(JobPost $job, int $userId, int $otherUserId): bool
    {
        if ($job->user_id === $userId) {
            return $job->bids()->where('provider_id', $otherUserId)->exists()
                || $job->contract()->where('provider_id', $otherUserId)->exists();
        }

        if ($job->user_id === $otherUserId) {
            return $job->bids()->where('provider_id', $userId)->exists()
                || $job->contract()->where('provider_id', $userId)->exists();
        }

        return false;
    }

    public function thread(Request $request, $jobId, $otherUserId)
    {
        $job = JobPost::find($jobId);
        if (!$job) return response()->json(['message' => 'Job not found'], 404);

        $otherUser = User::find($otherUserId);
        if (!$otherUser) return response()->json(['message' => 'User not found'], 404);

        $userId = $request->user()->id;

        if ($userId === (int) $otherUserId || !$this->canParticipate($job, $userId, (int) $otherUserId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $messages = Message::where('job_post_id', $jobId)
            ->where(function ($query) use ($userId, $otherUserId) {
                $query->where(function ($q) use ($userId, $otherUserId) {
                    $q->where('sender_id', $userId)->where('receiver_id', $otherUserId);
                })->orWhere(function ($q) use ($userId, $otherUserId) {
                    $q->where('sender_id', $otherUserId)->where('receiver_id', $userId);
                });
            })
            ->orderBy('created_at')
            ->get();

        Message::where('job_post_id', $jobId)
            ->where('sender_id', $otherUserId)
            ->where('receiver_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['messages' => $messages]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'job_post_id' => 'required|exists:job_posts,id',
            'body' => 'required|string|max:5000',
        ]);

        $user = $request->user();

        if ((int) $validated['receiver_id'] === $user->id) {
            return response()->json(['message' => 'You cannot message yourself'], 422);
        }

        $job = JobPost::find($validated['job_post_id']);
        if (!$this->canParticipate($job, $user->id, (int) $validated['receiver_id'])) {
            return response()->json(['message' => 'You are not a participant in this job conversation'], 403);
        }

        $message = Message::create([
            'sender_id' => $user->id,
            'receiver_id' => $validated['receiver_id'],
            'job_post_id' => $validated['job_post_id'],
            'body' => $validated['body'],
        ]);

        return response()->json([
            'message' => 'Message sent successfully',
            'data' => $message,
        ], 201);
    }
}
