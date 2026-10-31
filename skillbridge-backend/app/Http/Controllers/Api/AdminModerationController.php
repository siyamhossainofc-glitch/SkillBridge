<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\JobPost;
use App\Models\PortfolioItem;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Services\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminModerationController extends Controller
{
    private const CONTENT = [
        'jobs' => [JobPost::class, 'user_id'],
        'services' => [Service::class, 'provider_id'],
        'portfolio' => [PortfolioItem::class, 'provider_id'],
        'reviews' => [Review::class, 'reviewer_id'],
    ];

    public function summary(): JsonResponse
    {
        $counts = [
            'users' => [
                'active' => User::where('moderation_status', 'active')->count(),
                'suspended' => User::where('moderation_status', 'suspended')->count(),
            ],
            'jobs' => $this->moderationCounts(JobPost::class),
            'services' => $this->moderationCounts(Service::class),
            'portfolio' => $this->moderationCounts(PortfolioItem::class),
            'reviews' => $this->moderationCounts(Review::class),
        ];

        return response()->json(['counts' => $counts]);
    }

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:active,suspended'],
            'role' => ['sometimes', 'in:seeker,provider,admin'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $users = User::query()
            ->select(['id', 'name', 'email', 'phone', 'role', 'moderation_status', 'professional_title', 'rating', 'created_at'])
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('moderation_status', $status))
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($validated['search'] ?? null, fn ($q, $search) => $q->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->latest()
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();
        return response()->json($this->pagination($users));
    }

    public function user(User $user): JsonResponse
    {
        return response()->json(['user' => $user->only(['id', 'name', 'email', 'phone', 'role', 'moderation_status', 'professional_title', 'rating', 'bio', 'created_at'])]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000', 'regex:/.*\S.*/']]);
        if ($user->id === $request->user()->id || $user->role === 'admin') {
            return response()->json(['message' => 'Administrators cannot suspend themselves or another administrator.'], 422);
        }
        if ($user->moderation_status === 'suspended') {
            return response()->json(['message' => 'User is already suspended.'], 422);
        }
        DB::transaction(function () use ($request, $user, $validated): void {
            $user->update(['moderation_status' => 'suspended']);
            AdminAuditService::record($request->user(), 'user.suspended', 'user', $user->id, trim($validated['reason']), ['previous_status' => 'active']);
        });
        return response()->json(['message' => 'User suspended.', 'user' => $user->fresh()->only(['id', 'name', 'role', 'moderation_status'])]);
    }

    public function restore(Request $request, User $user): JsonResponse
    {
        if ($user->moderation_status === 'active') {
            return response()->json(['message' => 'User is already active.'], 422);
        }
        DB::transaction(function () use ($request, $user): void {
            $user->update(['moderation_status' => 'active']);
            AdminAuditService::record($request->user(), 'user.restored', 'user', $user->id, null, ['previous_status' => 'suspended']);
        });
        return response()->json(['message' => 'User restored.', 'user' => $user->fresh()->only(['id', 'name', 'role', 'moderation_status'])]);
    }

    public function content(Request $request, string $target): JsonResponse
    {
        abort_unless(array_key_exists($target, self::CONTENT), 404);
        [$model] = self::CONTENT[$target];
        $validated = $request->validate([
            'status' => ['sometimes', 'in:visible,hidden'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $relation = $target === 'jobs' ? 'user' : ($target === 'services' || $target === 'portfolio' ? 'provider' : 'reviewer');
        $query = $model::query()->with($relation);
        $query->when($validated['status'] ?? null, fn ($q, $status) => $q->where('moderation_status', $status));
        if (!empty($validated['search']) && in_array($target, ['jobs', 'services', 'portfolio'], true)) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$validated['search']}%")->orWhere('description', 'like', "%{$validated['search']}%"));
        }
        $items = $query->latest()->paginate($validated['per_page'] ?? 20)->withQueryString();
        return response()->json($this->pagination($items));
    }

    public function contentItem(string $target, int $id): JsonResponse
    {
        abort_unless(array_key_exists($target, self::CONTENT), 404);
        [$model] = self::CONTENT[$target];
        $relations = $target === 'jobs' ? ['user'] : ($target === 'services' || $target === 'portfolio' ? ['provider'] : ['reviewer', 'reviewee']);
        $item = $model::with($relations)->find($id);
        if (!$item) return response()->json(['message' => 'Moderation target not found.'], 404);

        return response()->json(['item' => $this->adminSafeContent($target, $item)]);
    }

    public function hide(Request $request, string $target, int $id): JsonResponse
    {
        return $this->setContentVisibility($request, $target, $id, 'hidden');
    }

    public function restoreContent(Request $request, string $target, int $id): JsonResponse
    {
        return $this->setContentVisibility($request, $target, $id, 'visible');
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $validated = $request->validate(['action' => ['sometimes', 'string', 'max:100'], 'target_type' => ['sometimes', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $logs = AdminAuditLog::with('admin:id,name,role')->when($validated['action'] ?? null, fn ($q, $v) => $q->where('action', $v))->when($validated['target_type'] ?? null, fn ($q, $v) => $q->where('target_type', $v))->latest()->paginate($validated['per_page'] ?? 30)->withQueryString();
        return response()->json($this->pagination($logs));
    }

    private function moderationCounts(string $model): array
    {
        return [
            'visible' => $model::where('moderation_status', 'visible')->count(),
            'hidden' => $model::where('moderation_status', 'hidden')->count(),
        ];
    }

    private function adminSafeContent(string $target, object $item): array
    {
        $owner = $item->relationLoaded('user') ? $item->user : ($item->relationLoaded('provider') ? $item->provider : $item->reviewer);
        $ownerPayload = $owner ? $owner->only(['id', 'name', 'role']) : null;
        $common = [
            'id' => $item->id,
            'moderation_status' => $item->moderation_status,
            'moderation_reason' => $item->moderation_reason,
            'moderated_by' => $item->moderated_by,
            'moderated_at' => $item->moderated_at,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
        ];

        return match ($target) {
            'jobs' => $common + ['title' => $item->title, 'description' => $item->description, 'category' => $item->category, 'status' => $item->status, 'location' => $item->location, 'budget_min' => $item->budget_min, 'budget_max' => $item->budget_max, 'owner' => $ownerPayload],
            'services' => $common + ['title' => $item->title, 'description' => $item->description, 'status' => $item->status, 'owner' => $ownerPayload],
            'portfolio' => $common + ['title' => $item->title, 'description' => $item->description, 'status' => $item->status, 'project_url' => $item->project_url, 'image_url' => $item->image_url, 'owner' => $ownerPayload],
            'reviews' => $common + ['rating' => $item->rating, 'comment' => $item->comment, 'status' => $item->status, 'owner' => $ownerPayload, 'reviewee' => $item->relationLoaded('reviewee') && $item->reviewee ? $item->reviewee->only(['id', 'name', 'role']) : null],
        };
    }

    private function setContentVisibility(Request $request, string $target, int $id, string $visibility): JsonResponse
    {
        abort_unless(array_key_exists($target, self::CONTENT), 404);
        $validated = $request->validate(['reason' => [$visibility === 'hidden' ? 'required' : 'sometimes', 'nullable', 'string', 'max:2000', 'regex:/.*\S.*/']]);
        [$model] = self::CONTENT[$target];
        $item = $model::find($id);
        if (!$item) return response()->json(['message' => 'Moderation target not found.'], 404);
        if ($item->moderation_status === $visibility) return response()->json(['message' => "Content is already {$visibility}."], 422);
        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : null;
        DB::transaction(function () use ($request, $item, $visibility, $reason, $target): void {
            $item->update(['moderation_status' => $visibility, 'moderation_reason' => $visibility === 'hidden' ? $reason : null, 'moderated_by' => $request->user()->id, 'moderated_at' => now()]);
            if ($target === 'reviews') {
                $average = Review::where('reviewee_id', $item->reviewee_id)->where('status', 'published')->where('moderation_status', 'visible')->avg('rating');
                User::whereKey($item->reviewee_id)->update(['rating' => $average === null ? 0 : round((float) $average, 2)]);
            }
            AdminAuditService::record($request->user(), $visibility === 'hidden' ? 'content.hidden' : 'content.restored', $target, $item->id, $reason, ['model' => $item::class]);
        });
        return response()->json(['message' => $visibility === 'hidden' ? 'Content hidden.' : 'Content restored.', 'item' => $item->fresh()]);
    }

    private function pagination($paginator): array
    {
        return ['data' => $paginator->getCollection()->values(), 'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }
}
