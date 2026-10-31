<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     * Current user's notifications, newest first, paginated.
     */
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page'     => ['sometimes', 'integer', 'min:1'],
        ]);

        $perPage = (int) $request->query('per_page', 15);
        // Belt-and-suspenders clamp — validation above already enforces this,
        // but keeps paginate() safe even if middleware bypasses validation.
        $perPage = max(1, min($perPage, 50));

        $notifications = Notification::forUser($request->user()->id)
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data'       => NotificationResource::collection($notifications->items()),
            'pagination' => [
                'total'        => $notifications->total(),
                'per_page'     => $notifications->perPage(),
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     */
    public function unreadCount(Request $request)
    {
        $count = Notification::forUser($request->user()->id)
            ->unread()
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    /**
     * PATCH /api/notifications/{id}/read
     * Mark a single notification as read; only the owner may do this.
     */
    public function markRead(Request $request, int $id)
    {
        $notification = Notification::forUser($request->user()->id)->find($id);

        if (!$notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->markRead();

        return response()->json([
            'message'      => 'Notification marked as read.',
            'notification' => new NotificationResource($notification->fresh()),
        ]);
    }

    /**
     * PATCH /api/notifications/read-all
     * Mark all of the current user's unread notifications as read.
     */
    public function markAllRead(Request $request)
    {
        $updated = Notification::forUser($request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'updated' => $updated,
        ]);
    }
}
