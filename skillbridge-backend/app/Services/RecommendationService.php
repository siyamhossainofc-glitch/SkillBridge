<?php

namespace App\Services;

use App\Models\Favorite;
use App\Models\JobPost;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;
use Throwable;

class RecommendationService
{
    public function matchProvidersWithAi(JobPost $job): array
    {
        $deterministic = $this->matchProviders($job);
        $fallback = ['matches' => $deterministic, 'algorithm' => 'deterministic-v1'];
        $apiKey = config('services.ai_matching.key');
        $endpoint = config('services.ai_matching.endpoint');
        if (!$apiKey || !$endpoint || $deterministic->isEmpty()) {
            return $fallback;
        }

        try {
            $candidateIds = $deterministic->pluck('provider.id')->map(fn ($id) => (int) $id)->values()->all();
            $candidateSet = array_fill_keys($candidateIds, true);
            $response = Http::timeout(20)->withToken($apiKey)->post($endpoint, [
                'model' => config('services.ai_matching.model'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Rank only the supplied eligible provider candidates for this job. Never create or alter provider IDs. Return every candidate exactly once. Score each from 0 to 100 based on job requirements, skills, taxonomy, location/service area, availability, rating, and verification.'],
                    ['role' => 'user', 'content' => json_encode([
                        'job' => $this->jobRequirements($job),
                        'eligible_candidates' => $deterministic->map(fn (array $match) => $this->candidateForAi($match))->values()->all(),
                    ], JSON_THROW_ON_ERROR)],
                ],
                'response_format' => ['type' => 'json_schema', 'json_schema' => [
                    'name' => 'provider_matching', 'strict' => true, 'schema' => [
                        'type' => 'object',
                        'properties' => ['rankings' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => ['provider_id' => ['type' => 'integer'], 'score' => ['type' => 'number'], 'reasons' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            'required' => ['provider_id', 'score', 'reasons'], 'additionalProperties' => false,
                        ]]],
                        'required' => ['rankings'], 'additionalProperties' => false,
                    ],
                ]],
                'temperature' => 0,
            ]);
            if (!$response->successful()) return $fallback;
            $content = data_get($response->json(), 'choices.0.message.content');
            $rankings = is_string($content) ? json_decode($content, true, 512, JSON_THROW_ON_ERROR)['rankings'] ?? null : null;
            if (!is_array($rankings) || count($rankings) !== count($candidateIds)) return $fallback;

            $seen = [];
            $ranked = collect($rankings)->map(function (array $ranking) use ($candidateSet, &$seen, $deterministic) {
                $providerId = filter_var($ranking['provider_id'] ?? null, FILTER_VALIDATE_INT);
                $score = $ranking['score'] ?? null;
                if (!$providerId || !isset($candidateSet[$providerId]) || isset($seen[$providerId]) || !is_numeric($score)) return null;
                $seen[$providerId] = true;
                $match = $deterministic->first(fn (array $candidate) => (int) $candidate['provider']['id'] === $providerId);
                $match['match_score'] = max(0, min(100, (int) round((float) $score)));
                $match['match_reasons'] = collect($ranking['reasons'] ?? [])->filter(fn ($reason) => is_string($reason) && trim($reason) !== '')->map(fn ($reason) => trim($reason))->values()->all();
                $match['match_source'] = 'ai-v1';
                return $match;
            })->filter()->values();
            if (count($seen) !== count($candidateIds)) return $fallback;

            return ['matches' => $ranked->sortByDesc('match_score')->values(), 'algorithm' => 'ai-v1'];
        } catch (Throwable) {
            return $fallback;
        }
    }

    public function matchProviders(JobPost $job, int $limit = 12): Collection
    {
        $requiredSkillIds = $job->requiredSkills->pluck('id')->map(fn ($id) => (int) $id)->all();
        $providers = User::query()
            ->where('role', 'provider')
            ->where('moderation_status', 'active')
            ->with([
                'skills:id,name,slug,category_id',
                'skills.category:id,name,slug',
                'services' => fn ($query) => $query->where('status', 'published')->where('moderation_status', 'visible')->with(['category:id,name,slug', 'subcategory:id,category_id,name,slug']),
                'serviceAreas' => fn ($query) => $query->where('is_active', true),
                'availabilities' => fn ($query) => $query->where('is_active', true),
            ])
            ->withExists(['verification as is_verified' => fn ($query) => $query->where('status', 'verified')])
            ->get(['id', 'name', 'professional_title', 'bio', 'address', 'rating', 'created_at']);

        return $providers
            ->map(fn (User $provider) => $this->scoreProvider($provider, $job, $requiredSkillIds))
            ->filter(fn (array $match) => $match['eligible'])
            ->sort(fn (array $left, array $right) => [$right['match_score'], $right['matched_skill_count'], $right['provider']['id']] <=> [$left['match_score'], $left['matched_skill_count'], $left['provider']['id']])
            ->values()
            ->take($limit);
    }

    public function recommendations(User $user, int $limit = 8): array
    {
        $signals = $this->signals($user);
        $providers = User::query()
            ->where('role', 'provider')->where('moderation_status', 'active')
            ->where('id', '!=', $user->id)
            ->with(['skills:id,name,slug,category_id', 'skills.category:id,name,slug'])
            ->withExists(['verification as is_verified' => fn ($query) => $query->where('status', 'verified')])
            ->get(['id', 'name', 'professional_title', 'bio', 'address', 'rating', 'created_at'])
            ->map(fn (User $provider) => $this->scoreRecommendation($provider, $signals))
            ->sort(fn (array $left, array $right) => [$right['recommendation_score'], $right['provider']['id']] <=> [$left['recommendation_score'], $left['provider']['id']])
            ->values()->take($limit);

        $jobs = JobPost::query()
            ->where('status', 'open')->where('moderation_status', 'visible')
            ->whereHas('user', fn ($query) => $query->where('moderation_status', 'active'))
            ->with(['categoryRelation:id,name,slug', 'subcategory:id,category_id,name,slug', 'requiredSkills:id,name,slug'])
            ->latest('created_at')->get()
            ->map(fn (JobPost $job) => $this->scoreJobRecommendation($job, $signals))
            ->sort(fn (array $left, array $right) => [$right['recommendation_score'], $right['job']['id']] <=> [$left['recommendation_score'], $left['job']['id']])
            ->values()->take($limit);

        $services = Service::query()
            ->where('status', 'published')->where('moderation_status', 'visible')
            ->whereHas('provider', fn ($query) => $query->where('moderation_status', 'active'))
            ->with(['category:id,name,slug', 'subcategory:id,category_id,name,slug', 'provider:id,name,professional_title,rating', 'provider.skills:id,name,slug,category_id'])
            ->withCount('packages')->latest('created_at')->get()
            ->map(fn (Service $service) => $this->scoreServiceRecommendation($service, $signals))
            ->sort(fn (array $left, array $right) => [$right['recommendation_score'], $right['service']['id']] <=> [$left['recommendation_score'], $left['service']['id']])
            ->values()->take($limit);

        return ['providers' => $providers, 'jobs' => $jobs, 'services' => $services];
    }

    private function signals(User $user): array
    {
        $skills = $user->skills()->get(['skills.id', 'skills.category_id']);
        $categoryIds = $skills->pluck('category_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $favoriteServiceIds = Favorite::where('user_id', $user->id)->where('target_type', 'service')->pluck('target_id');
        $favoriteProviderIds = Favorite::where('user_id', $user->id)->where('target_type', 'provider')->pluck('target_id');
        $favoriteCategories = Service::whereIn('id', $favoriteServiceIds)->pluck('category_id')->map(fn ($id) => (int) $id)->all();
        $savedJobCategories = $user->savedJobs()->join('job_posts', 'job_posts.id', '=', 'saved_jobs.job_post_id')->pluck('job_posts.category_id')->filter()->map(fn ($id) => (int) $id)->all();

        return [
            'skill_ids' => $skills->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'category_ids' => array_values(array_unique(array_merge($categoryIds, $favoriteCategories, $savedJobCategories))),
            'favorite_provider_ids' => $favoriteProviderIds->map(fn ($id) => (int) $id)->all(),
        ];
    }

    private function scoreProvider(User $provider, JobPost $job, array $requiredSkillIds): array
    {
        $providerSkillIds = $provider->skills->pluck('id')->map(fn ($id) => (int) $id)->all();
        $matchedSkills = array_values(array_intersect($requiredSkillIds, $providerSkillIds));
        $hasCategory = $job->category_id && ($provider->skills->contains('category_id', $job->category_id) || $provider->services->contains('category_id', $job->category_id));
        $hasSubcategory = $job->subcategory_id && $provider->services->contains('subcategory_id', $job->subcategory_id);
        $locationMatch = $job->work_mode === 'remote' || !$job->location || stripos((string) $provider->address, (string) $job->location) !== false || $provider->serviceAreas->contains(fn ($area) => stripos((string) $area->area_name, (string) $job->location) !== false || stripos((string) $area->city, (string) $job->location) !== false || stripos((string) $area->district, (string) $job->location) !== false);
        $hasAvailability = $provider->availabilities->isNotEmpty();
        $eligible = !$requiredSkillIds && !$job->category_id ? $provider->services->isNotEmpty() : (bool) ($matchedSkills || $hasCategory || $hasSubcategory);
        $score = min(100, count($requiredSkillIds) ? (int) round(count($matchedSkills) / count($requiredSkillIds) * 70) : 0);
        $score += $hasCategory ? 20 : 0;
        $score += $hasSubcategory ? 7 : 0;
        $score += $locationMatch ? 3 : 0;
        $score += $hasAvailability ? 2 : 0;
        $score = min(100, $score);

        return [
            'provider' => $provider,
            'match_score' => $score,
            'matched_skill_count' => count($matchedSkills),
            'matched_skill_ids' => $matchedSkills,
            'match_reasons' => array_values(array_filter([
                count($matchedSkills) ? count($matchedSkills).' required skill'.(count($matchedSkills) === 1 ? '' : 's').' matched' : null,
                $hasCategory ? 'Category capability matched' : null,
                $hasSubcategory ? 'Subcategory service matched' : null,
                $locationMatch ? 'Work location is compatible' : null,
                $hasAvailability ? 'Provider has active availability' : null,
            ])),
            'eligible' => $eligible,
        ];
    }

    private function jobRequirements(JobPost $job): array
    {
        return [
            'id' => (int) $job->id,
            'title' => $job->title,
            'description' => $job->description,
            'category_id' => $job->category_id ? (int) $job->category_id : null,
            'subcategory_id' => $job->subcategory_id ? (int) $job->subcategory_id : null,
            'required_skill_ids' => $job->requiredSkills->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'location' => $job->location,
            'work_mode' => $job->work_mode,
            'budget_min' => $job->budget_min,
            'budget_max' => $job->budget_max,
            'preferred_experience' => $job->preferred_experience,
        ];
    }

    private function candidateForAi(array $match): array
    {
        $provider = $match['provider'];
        return [
            'provider_id' => (int) $provider->id,
            'name' => $provider->name,
            'professional_title' => $provider->professional_title,
            'bio' => $provider->bio,
            'address' => $provider->address,
            'rating' => $provider->rating,
            'is_verified' => (bool) $provider->is_verified,
            'skill_ids' => $provider->skills->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'service_area_names' => $provider->serviceAreas->map(fn ($area) => $area->area_name)->values()->all(),
            'has_active_availability' => $provider->availabilities->isNotEmpty(),
            'deterministic_score' => $match['match_score'],
        ];
    }

    private function scoreRecommendation(User $provider, array $signals): array
    {
        $skillIds = $provider->skills->pluck('id')->map(fn ($id) => (int) $id)->all();
        $categoryIds = $provider->skills->pluck('category_id')->filter()->map(fn ($id) => (int) $id)->all();
        $skillOverlap = count(array_intersect($signals['skill_ids'], $skillIds));
        $categoryOverlap = count(array_intersect($signals['category_ids'], $categoryIds));
        $isFavorite = in_array((int) $provider->id, $signals['favorite_provider_ids'], true);
        $score = min(70, $skillOverlap * 20) + min(20, $categoryOverlap * 10) + ($isFavorite ? 10 : 0) + ($provider->is_verified ? 5 : 0) + ($provider->rating ? min(5, (int) floor((float) $provider->rating)) : 0);
        return ['provider' => $provider, 'recommendation_score' => min(100, $score), 'recommendation_reasons' => array_values(array_filter([$skillOverlap ? 'Shares '.$skillOverlap.' profile skill'.($skillOverlap === 1 ? '' : 's') : null, $categoryOverlap ? 'Works in a relevant category' : null, $isFavorite ? 'Previously favorited' : null, $provider->is_verified ? 'Verified provider' : null]))];
    }

    private function scoreJobRecommendation(JobPost $job, array $signals): array
    {
        $skillOverlap = count(array_intersect($signals['skill_ids'], $job->requiredSkills->pluck('id')->map(fn ($id) => (int) $id)->all()));
        $category = $job->category_id && in_array((int) $job->category_id, $signals['category_ids'], true);
        return ['job' => $job, 'recommendation_score' => min(80, $skillOverlap * 20) + ($category ? 20 : 0), 'recommendation_reasons' => array_values(array_filter([$skillOverlap ? 'Matches '.$skillOverlap.' profile skill'.($skillOverlap === 1 ? '' : 's') : null, $category ? 'Relevant category' : null]))];
    }

    private function scoreServiceRecommendation(Service $service, array $signals): array
    {
        $category = $service->category_id && in_array((int) $service->category_id, $signals['category_ids'], true);
        $provider = $service->provider;
        $providerSkillOverlap = $provider ? $provider->skills->pluck('id')->intersect($signals['skill_ids'])->count() : 0;
        return ['service' => $service, 'recommendation_score' => ($category ? 60 : 0) + min(30, $providerSkillOverlap * 15) + 10, 'recommendation_reasons' => array_values(array_filter([$category ? 'Relevant category' : null, $providerSkillOverlap ? 'Provider shares profile skills' : null]))];
    }
}
