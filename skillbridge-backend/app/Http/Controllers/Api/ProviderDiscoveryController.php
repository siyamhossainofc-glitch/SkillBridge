<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SmartRankingService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class ProviderDiscoveryController extends Controller
{
    public function index(Request $request, SmartRankingService $ranking)
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'skill_id' => ['sometimes', 'nullable', 'integer', 'exists:skills,id'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'sort' => ['sometimes', 'nullable', 'in:recent,rating,name,smart'],
        ]);

        $query = User::query()
            ->where('role', 'provider')->where('moderation_status', 'active')
            ->select(['id', 'name', 'professional_title', 'bio', 'address', 'rating', 'created_at'])
            ->with(['skills:id,name,slug,category_id', 'skills.category:id,name,slug', 'serviceAreas:id,provider_id,area_name,city,district,postal_code,is_active', 'availabilities:id,provider_id,day_of_week,start_time,end_time,is_active'])
            ->withCount([
                'services as published_services_count' => fn ($services) => $services->where('status', 'published')->where('moderation_status', 'visible'),
                'portfolioItems as published_portfolio_count' => fn ($items) => $items->where('status', 'published')->where('moderation_status', 'visible'),
            ])
            ->withExists(['verification as is_verified' => fn ($verification) => $verification->where('status', 'verified')]);

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('professional_title', 'like', "%{$search}%")
                    ->orWhere('bio', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhereHas('skills', fn ($skills) => $skills->where('name', 'like', "%{$search}%"));
            });
        }
        if (!empty($validated['location'])) {
            $location = trim($validated['location']);
            $query->where(function ($builder) use ($location) {
                $builder->where('address', 'like', '%'.$location.'%')->orWhereHas('serviceAreas', function ($areas) use ($location) {
                    $areas->where('is_active', true)->where(function ($area) use ($location) {
                        $area->where('area_name', 'like', '%'.$location.'%')->orWhere('city', 'like', '%'.$location.'%')->orWhere('district', 'like', '%'.$location.'%')->orWhere('postal_code', 'like', '%'.$location.'%');
                    });
                });
            });
        }
        if (!empty($validated['skill_id'])) $query->whereHas('skills', fn ($skills) => $skills->whereKey($validated['skill_id']));
        if (!empty($validated['category_id'])) $query->whereHas('skills', fn ($skills) => $skills->where('category_id', $validated['category_id']));

        if (($validated['sort'] ?? 'recent') === 'smart') {
            $ranked = $ranking->providers($query->limit(1000)->get(), $validated['location'] ?? null);
            $page = LengthAwarePaginator::resolveCurrentPage();
            $perPage = 12;
            return response()->json((new LengthAwarePaginator($ranked->forPage($page, $perPage)->values(), $ranked->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]))->withQueryString());
        }

        match ($validated['sort'] ?? 'recent') {
            'rating' => $query->orderByDesc('rating')->orderBy('name'),
            'name' => $query->orderBy('name'),
            default => $query->latest(),
        };

        return response()->json($query->paginate(12)->withQueryString());
    }
}
