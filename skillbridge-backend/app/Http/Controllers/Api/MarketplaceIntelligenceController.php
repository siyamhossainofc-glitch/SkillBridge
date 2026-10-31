<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Models\Category;
use App\Models\JobPost;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MarketplaceIntelligenceController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:365']]);
        $days = (int) ($validated['days'] ?? 30);
        $since = Carbon::now()->subDays($days);
        $visibleJobs = JobPost::query()->where('moderation_status', 'visible')->whereHas('user', fn ($query) => $query->where('moderation_status', 'active'))->where('created_at', '>=', $since)->with('categoryRelation:id,name,slug')->withCount('bids')->get();
        $visibleServices = Service::query()->where('status', 'published')->where('moderation_status', 'visible')->whereHas('provider', fn ($query) => $query->where('moderation_status', 'active'))->where('created_at', '>=', $since)->with('category:id,name,slug')->get();
        $categories = Category::query()->where('is_active', true)->get(['id', 'name', 'slug']);
        $jobsByCategory = $visibleJobs->groupBy('category_id');
        $servicesByCategory = $visibleServices->groupBy('category_id');
        $bids = Bid::query()->where('created_at', '>=', $since)->whereHas('jobPost', fn ($query) => $query->where('moderation_status', 'visible')->whereHas('user', fn ($owner) => $owner->where('moderation_status', 'active')))->count();
        $trend = $categories->map(function ($category) use ($jobsByCategory, $servicesByCategory) {
            $jobs = $jobsByCategory->get($category->id, collect());
            $services = $servicesByCategory->get($category->id, collect());
            return ['category_id' => $category->id, 'category' => $category->name, 'slug' => $category->slug, 'job_demand' => $jobs->count(), 'bid_activity' => (int) $jobs->sum('bids_count'), 'published_service_supply' => $services->count()];
        })->filter(fn ($row) => $row['job_demand'] || $row['published_service_supply'])->sortByDesc(fn ($row) => [$row['job_demand'], $row['bid_activity'], $row['published_service_supply']])->values();

        return response()->json([
            'period_days' => $days,
            'generated_at' => Carbon::now()->toISOString(),
            'demand_trends' => $trend,
            'supply' => ['active_providers' => User::where('role', 'provider')->where('moderation_status', 'active')->count(), 'published_visible_services' => $visibleServices->count()],
            'activity' => ['visible_jobs_created' => $visibleJobs->count(), 'bids_submitted' => $bids, 'average_bids_per_job' => $visibleJobs->count() ? round($bids / $visibleJobs->count(), 2) : 0],
            'health' => ['active_provider_to_job_ratio' => $visibleJobs->count() ? round(User::where('role', 'provider')->where('moderation_status', 'active')->count() / $visibleJobs->count(), 2) : null, 'job_to_service_supply_ratio' => $visibleServices->count() ? round($visibleJobs->count() / $visibleServices->count(), 2) : null],
            'privacy' => ['scope' => 'admin', 'fraud_signals_included' => false, 'punitive_action_taken' => false],
        ]);
    }
}
