<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\JobPost;
use App\Models\Subcategory;
use Illuminate\Validation\ValidationException;
use App\Services\SmartRankingService;
use Illuminate\Pagination\LengthAwarePaginator;

class JobController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'sometimes|nullable|string|max:255',
            'q' => 'sometimes|nullable|string|max:255',
            'category' => 'sometimes|nullable|string|max:255',
            'category_id' => 'sometimes|nullable|integer|exists:categories,id',
            'subcategory_id' => 'sometimes|nullable|integer|exists:subcategories,id',
            'work_mode' => 'sometimes|nullable|in:remote,on-site,hybrid',
            'location' => 'sometimes|nullable|string|max:255',
            'budget_min' => 'sometimes|nullable|numeric|min:0',
            'budget_max' => 'sometimes|nullable|numeric|min:0',
            'sort' => 'sometimes|nullable|in:recent,budget_low,budget_high,smart',
        ]);
        $this->validateCategoryFilterCombination($validated);
        if (isset($validated['budget_min'], $validated['budget_max']) && $validated['budget_min'] > $validated['budget_max']) {
            throw ValidationException::withMessages(['budget_max' => ['The maximum budget must be greater than or equal to the minimum budget.']]);
        }

        $query = JobPost::query()
            ->with(['categoryRelation:id,name,slug', 'subcategory:id,category_id,name,slug', 'requiredSkills:id,name,slug'])
            ->withCount('bids')
            ->where('moderation_status', 'visible')
            ->whereHas('user', fn ($user) => $user->where('moderation_status', 'active'))
            ;

        $search = $validated['search'] ?? $validated['q'] ?? null;
        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($builder) use ($term) {
                $builder->where('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%")
                    ->orWhere('location', 'like', "%{$term}%")
                    ->orWhereHas('categoryRelation', fn ($category) => $category->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('subcategory', fn ($subcategory) => $subcategory->where('name', 'like', "%{$term}%"));
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('subcategory_id')) {
            $query->where('subcategory_id', $request->input('subcategory_id'));
        }

        if ($request->filled('work_mode')) {
            $query->where('work_mode', $request->input('work_mode'));
        }

        if ($request->filled('location')) {
            $query->where('location', 'like', '%' . trim($request->input('location')) . '%');
        }

        // Existing budgets are a min/max range. These filters return jobs whose
        // ranges overlap the requested range, rather than requiring exact values.
        if ($request->filled('budget_min')) {
            $query->where(function ($builder) use ($request) {
                $builder->whereNull('budget_max')->orWhere('budget_max', '>=', $request->input('budget_min'));
            });
        }
        if ($request->filled('budget_max')) {
            $query->where(function ($builder) use ($request) {
                $builder->whereNull('budget_min')->orWhere('budget_min', '<=', $request->input('budget_max'));
            });
        }

        if ($request->boolean('mine')) {
            // Dashboard "my jobs" view: show all of the current user's jobs,
            // regardless of status.
            $user = $request->user('sanctum');
            if (!$user) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }
            $query->where('user_id', $user->id);
        } else {
            // Public "Browse Jobs" view: only open jobs are up for bidding.
            $query->where('status', 'open');
        }

        if (($validated['sort'] ?? 'recent') === 'smart') {
            $ranked = app(SmartRankingService::class)->jobs($query->limit(1000)->get(), isset($validated['budget_min']) ? (float) $validated['budget_min'] : null, isset($validated['budget_max']) ? (float) $validated['budget_max'] : null);
            $page = LengthAwarePaginator::resolveCurrentPage();
            $perPage = 10;
            return response()->json((new LengthAwarePaginator($ranked->forPage($page, $perPage)->values(), $ranked->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]))->withQueryString());
        }

        match ($validated['sort'] ?? 'recent') {
            'budget_low' => $query->orderByRaw('COALESCE(budget_min, budget_max, 0) asc')->latest('created_at'),
            'budget_high' => $query->orderByRaw('COALESCE(budget_max, budget_min, 0) desc')->latest('created_at'),
            default => $query->latest('created_at'),
        };

        $jobs = $query->paginate(10)->withQueryString();

        return response()->json($jobs);
    }

    public function show($id)
    {
        // Eager-load the seeker relation (alias for user()) so the frontend
        // can read job.seeker.name / job.seeker.id without a second request.
        $job = JobPost::with(['seeker:id,name,rating', 'categoryRelation:id,name,slug', 'subcategory:id,category_id,name,slug', 'requiredSkills:id,name,slug'])
            ->where('moderation_status', 'visible')
            ->whereHas('user', fn ($user) => $user->where('moderation_status', 'active'))
            ->find($id);

        if (!$job) {
            return response()->json([
                'message' => 'Job not found'
            ], 404);
        }

        return response()->json([
            'job' => $job,
        ]);
    }

    public function myJobs(Request $request)
    {
        $user = $request->user();

        $jobs = $user->jobPosts()->with(['categoryRelation:id,name,slug', 'subcategory:id,category_id,name,slug', 'requiredSkills:id,name,slug'])->latest()->get();

        return response()->json([
            'jobs' => $jobs,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'nullable|string|max:255|required_without:category_id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:subcategories,id',
            'budget_min' => 'required|numeric|min:0',
            'budget_max' => 'required|numeric|gte:budget_min',
            'location' => 'required|string|max:255',
            'work_mode' => 'nullable|in:remote,on-site,hybrid',
            'lat' => 'sometimes|nullable|numeric',
            'lng' => 'sometimes|nullable|numeric',
            'required_skill_ids' => ['sometimes', 'array'],
            'required_skill_ids.*' => ['integer', 'exists:skills,id'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'urgency' => ['sometimes', 'nullable', 'in:low,normal,high,urgent'],
            'preferred_experience' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $this->validateCategorySelection($validated);
        if (!empty($validated['category_id'])) {
            $validated['category'] = Category::findOrFail($validated['category_id'])->name;
        }

        $validated['status'] = 'open';

        $job = $user->jobPosts()->create($validated);
        if (array_key_exists('required_skill_ids', $validated)) {
            $job->requiredSkills()->sync($validated['required_skill_ids'] ?? []);
        }

        return response()->json([
            'message' => 'Job created successfully',
            'job' => $job,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $job = JobPost::find($id);

        if (!$job) {
            return response()->json([
                'message' => 'Job not found'
            ], 404);
        }

        if ($job->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        if ($job->status !== 'open') {
            return response()->json([
                'message' => 'Only open jobs can be edited'
            ], 422);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'category' => 'sometimes|nullable|string|max:255',
            'category_id' => 'sometimes|nullable|integer|exists:categories,id',
            'subcategory_id' => 'sometimes|nullable|integer|exists:subcategories,id',
            'budget_min' => 'sometimes|numeric|min:0',
            'budget_max' => 'sometimes|numeric|gte:budget_min',
            'location' => 'sometimes|string|max:255',
            'work_mode' => 'sometimes|nullable|in:remote,on-site,hybrid',
            'lat' => 'sometimes|nullable|numeric',
            'lng' => 'sometimes|nullable|numeric',
            'required_skill_ids' => ['sometimes', 'array'],
            'required_skill_ids.*' => ['integer', 'exists:skills,id'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'urgency' => ['sometimes', 'nullable', 'in:low,normal,high,urgent'],
            'preferred_experience' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $this->validateCategorySelection($validated, $job);
        if (array_key_exists('category_id', $validated) && $validated['category_id']) {
            $validated['category'] = Category::findOrFail($validated['category_id'])->name;
        }
        if (array_key_exists('category_id', $validated) && $validated['category_id'] !== $job->category_id && !array_key_exists('subcategory_id', $validated)) {
            $validated['subcategory_id'] = null;
        }

        $job->update($validated);
        if (array_key_exists('required_skill_ids', $validated)) {
            $job->requiredSkills()->sync($validated['required_skill_ids'] ?? []);
        }

        return response()->json([
            'message' => 'Job updated successfully',
            'job' => $job,
        ]);
    }

    public function cancel(Request $request, $id)
    {
        $job = JobPost::find($id);

        if (!$job) {
            return response()->json([
                'message' => 'Job not found'
            ], 404);
        }

        if ($job->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        if ($job->status !== 'open') {
            return response()->json([
                'message' => 'Only open jobs can be cancelled'
            ], 422);
        }

        $job->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Job cancelled successfully',
            'job' => $job,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $job = JobPost::find($id);

        if (!$job) {
            return response()->json([
                'message' => 'Job not found'
            ], 404);
        }

        if ($job->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        if ($job->status !== 'open' || $job->bids()->exists()) {
            return response()->json([
                'message' => 'Job cannot be deleted once it has received bids or is no longer open'
            ], 422);
        }

        $job->delete();

        return response()->json([
            'message' => 'Job deleted successfully',
        ]);
    }

    public function bids(Request $request, $id)
    {
        $job = JobPost::find($id);

        if (!$job) {
            return response()->json([
                'message' => 'Job not found'
            ], 404);
        }

        $user = $request->user();
        $isOwner = $job->user_id === $user->id;

        if ($isOwner) {
            // Job owner sees every bid received.
            $bids = $job->bids()->with('provider:id,name,email,rating')->latest()->get();
        } elseif ($user->role === 'provider') {
            // A provider can only see their own bid on this job, never
            // competitors' bids or amounts.
            $bids = $job->bids()->where('provider_id', $user->id)->with('provider:id,name,email,rating')->latest()->get();
        } else {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        return response()->json([
            'bids' => $bids,
        ]);
    }

    private function validateCategorySelection(array $validated, ?JobPost $job = null): void
    {
        if (!empty($validated['subcategory_id'])) {
            $subcategory = Subcategory::findOrFail($validated['subcategory_id']);
            $categoryId = $validated['category_id'] ?? $job?->category_id;
            if (!$categoryId || $subcategory->category_id !== (int) $categoryId) {
                abort(422, 'The selected subcategory does not belong to the selected category.');
            }
        }
    }

    private function validateCategoryFilterCombination(array $validated): void
    {
        if (!empty($validated['category_id']) && !empty($validated['subcategory_id'])
            && !Subcategory::whereKey($validated['subcategory_id'])
                ->where('category_id', $validated['category_id'])
                ->exists()) {
            throw ValidationException::withMessages([
                'subcategory_id' => ['The selected subcategory does not belong to the selected category.'],
            ]);
        }
    }
}
