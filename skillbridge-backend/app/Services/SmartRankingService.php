<?php

namespace App\Services;

use Illuminate\Support\Collection;

class SmartRankingService
{
    public function providers(Collection $providers, ?string $location = null): Collection
    {
        return $providers->map(function ($provider) use ($location) {
            $skillCount = $provider->relationLoaded('skills') ? $provider->skills->count() : 0;
            $services = (int) ($provider->published_services_count ?? 0);
            $portfolio = (int) ($provider->published_portfolio_count ?? 0);
            $verification = (bool) ($provider->is_verified ?? false);
            $activeAreas = $provider->relationLoaded('serviceAreas') ? $provider->serviceAreas->where('is_active', true) : collect();
            $areaMatch = $location && $activeAreas->contains(fn ($area) => collect([$area->area_name, $area->city, $area->district, $area->postal_code])->filter()->contains(fn ($value) => stripos((string) $value, $location) !== false));
            $addressMatch = $location && stripos((string) $provider->address, $location) !== false;
            $activeAvailability = $provider->relationLoaded('availabilities') ? $provider->availabilities->where('is_active', true)->count() : 0;
            $completeness = collect([$provider->name, $provider->professional_title, $provider->bio, $provider->address])->filter(fn ($value) => filled($value))->count() * 5;
            $score = min(100, $completeness + min(20, $skillCount * 4) + min(20, $services * 5) + min(10, $portfolio * 3) + ($verification ? 15 : 0) + ($provider->rating ? min(10, (float) $provider->rating * 2) : 0) + ($areaMatch ? 10 : ($addressMatch ? 5 : 0)) + ($activeAvailability ? 5 : 0));
            $provider->smart_rank_score = (int) round($score);
            $provider->smart_rank_reasons = array_values(array_filter([
                $skillCount ? $skillCount.' stored skill'.($skillCount === 1 ? '' : 's') : null,
                $services ? $services.' published service'.($services === 1 ? '' : 's') : null,
                $portfolio ? $portfolio.' visible portfolio item'.($portfolio === 1 ? '' : 's') : null,
                $verification ? 'Verified provider' : null,
                $provider->rating ? 'Rated profile' : null,
                $areaMatch ? 'Active service area matched' : ($addressMatch ? 'Provider address matched' : null),
                $activeAvailability ? 'Active availability recorded' : null,
            ]));
            $provider->unsetRelation('serviceAreas');
            $provider->unsetRelation('availabilities');
            return $provider;
        })->sort(fn ($left, $right) => [$right->smart_rank_score, $right->rating ?? 0, $right->id] <=> [$left->smart_rank_score, $left->rating ?? 0, $left->id])->values();
    }

    public function jobs(Collection $jobs, ?float $requestedMin = null, ?float $requestedMax = null): Collection
    {
        return $jobs->map(function ($job) use ($requestedMin, $requestedMax) {
            $skills = $job->relationLoaded('requiredSkills') ? $job->requiredSkills->count() : 0;
            $bids = (int) ($job->bids_count ?? 0);
            [$budgetScore, $budgetReason] = $this->budgetFit($job, $requestedMin, $requestedMax);
            $score = ($job->category_id ? 25 : 0) + ($job->subcategory_id ? 15 : 0) + min(20, $skills * 5) + $budgetScore + min(15, $bids * 3) + ($job->deadline ? 5 : 0) + ($job->urgency === 'urgent' ? 5 : ($job->urgency === 'high' ? 3 : 0));
            $job->smart_rank_score = min(100, $score);
            $job->smart_rank_reasons = array_values(array_filter([$job->category_id ? 'Categorized brief' : null, $skills ? $skills.' required skill'.($skills === 1 ? '' : 's') : null, $budgetReason, $bids ? $bids.' bid'.($bids === 1 ? '' : 's') : null, $job->deadline ? 'Deadline supplied' : null, $job->urgency === 'urgent' ? 'Urgent brief' : null]));
            return $job;
        })->sort(fn ($left, $right) => [$right->smart_rank_score, $right->bids_count ?? 0, $right->created_at?->timestamp ?? 0, $right->id] <=> [$left->smart_rank_score, $left->bids_count ?? 0, $left->created_at?->timestamp ?? 0, $left->id])->values();
    }

    private function budgetFit(object $job, ?float $requestedMin, ?float $requestedMax): array
    {
        $jobMin = $job->budget_min !== null ? (float) $job->budget_min : null;
        $jobMax = $job->budget_max !== null ? (float) $job->budget_max : null;
        if ($jobMin === null && $jobMax === null) return [0, null];
        if ($requestedMin === null && $requestedMax === null) return [$jobMin !== null && $jobMax !== null ? 10 : 5, $jobMin !== null && $jobMax !== null ? 'Budget range is defined' : 'Budget guidance is supplied'];
        $targetMin = $requestedMin ?? $requestedMax;
        $targetMax = $requestedMax ?? $requestedMin;
        if (($jobMax !== null && $jobMax < $targetMin) || ($jobMin !== null && $jobMin > $targetMax)) return [0, null];
        $targetMid = ($targetMin + $targetMax) / 2;
        $jobMid = (($jobMin ?? $jobMax) + ($jobMax ?? $jobMin)) / 2;
        $span = max(1, abs($targetMax - $targetMin));
        return [max(1, (int) round(15 - min(14, abs($jobMid - $targetMid) / $span * 5))), 'Budget overlaps the requested range'];
    }

    public function services(Collection $services): Collection
    {
        return $services->map(function ($service) {
            $packages = (int) ($service->packages_count ?? 0);
            $provider = $service->provider;
            $providerComplete = $provider ? collect([$provider->professional_title, $provider->bio, $provider->address])->filter(fn ($value) => filled($value))->count() : 0;
            $verified = (bool) ($provider?->is_verified ?? false);
            $score = ($service->category_id ? 25 : 0) + ($service->subcategory_id ? 15 : 0) + min(20, $packages * 7) + min(15, $providerComplete * 5) + ($verified ? 15 : 0) + ($provider?->rating ? min(10, (float) $provider->rating * 2) : 0);
            $service->smart_rank_score = min(100, (int) round($score));
            $service->smart_rank_reasons = array_values(array_filter([$service->category_id ? 'Categorized service' : null, $packages ? $packages.' package'.($packages === 1 ? '' : 's') : null, $providerComplete >= 2 ? 'Complete provider profile' : null, $verified ? 'Verified provider' : null, $provider?->rating ? 'Rated provider' : null]));
            return $service;
        })->sort(fn ($left, $right) => [$right->smart_rank_score, $right->provider?->rating ?? 0, $right->id] <=> [$left->smart_rank_score, $left->provider?->rating ?? 0, $left->id])->values();
    }
}
