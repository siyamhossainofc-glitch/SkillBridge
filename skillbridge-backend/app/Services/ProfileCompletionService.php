<?php

namespace App\Services;

use App\Models\User;

class ProfileCompletionService
{
    public function calculate(User $user): array
    {
        $user->loadCount([
            'skills',
            'jobPosts',
            'services as published_services_count' => fn ($query) => $query->where('status', 'published'),
            'portfolioItems as published_portfolio_count' => fn ($query) => $query->where('status', 'published'),
            'availabilities as active_availabilities_count' => fn ($query) => $query->where('is_active', true),
            'serviceAreas as active_service_areas_count' => fn ($query) => $query->where('is_active', true),
        ]);

        $checks = $user->role === 'provider'
            ? [
                ['key' => 'name', 'label' => 'Add your name', 'weight' => 10, 'done' => filled($user->name), 'link' => '/profile'],
                ['key' => 'professional_title', 'label' => 'Add a professional title', 'weight' => 15, 'done' => filled($user->professional_title), 'link' => '/profile'],
                ['key' => 'bio', 'label' => 'Write a useful bio', 'weight' => 15, 'done' => filled($user->bio), 'link' => '/profile'],
                ['key' => 'location', 'label' => 'Add your location', 'weight' => 10, 'done' => filled($user->address), 'link' => '/profile'],
                ['key' => 'skills', 'label' => 'Attach at least one skill', 'weight' => 10, 'done' => $user->skills_count > 0, 'link' => '/profile'],
                ['key' => 'services', 'label' => 'Publish a service', 'weight' => 15, 'done' => $user->published_services_count > 0, 'link' => '/my-services'],
                ['key' => 'portfolio', 'label' => 'Publish portfolio work', 'weight' => 10, 'done' => $user->published_portfolio_count > 0, 'link' => '/my-portfolio'],
                ['key' => 'availability', 'label' => 'Add availability windows', 'weight' => 8, 'done' => $user->active_availabilities_count > 0, 'link' => '/availability'],
                ['key' => 'service_areas', 'label' => 'Add service areas', 'weight' => 7, 'done' => $user->active_service_areas_count > 0, 'link' => '/service-areas'],
            ]
            : [
                ['key' => 'name', 'label' => 'Add your name', 'weight' => 25, 'done' => filled($user->name), 'link' => '/profile'],
                ['key' => 'bio', 'label' => 'Describe what you are looking for', 'weight' => 25, 'done' => filled($user->bio), 'link' => '/profile'],
                ['key' => 'location', 'label' => 'Add your location or work preference', 'weight' => 20, 'done' => filled($user->address), 'link' => '/profile'],
                ['key' => 'skills', 'label' => 'Add relevant skills or preferences', 'weight' => 15, 'done' => $user->skills_count > 0, 'link' => '/profile'],
                ['key' => 'job_post', 'label' => 'Post a clear job brief', 'weight' => 15, 'done' => $user->job_posts_count > 0, 'link' => '/jobs/new'],
            ];

        $completed = collect($checks)->where('done', true)->values();
        $missing = collect($checks)->where('done', false)->values();
        $percentage = min(100, max(0, (int) $completed->sum('weight')));

        return [
            'completion_percentage' => $percentage,
            'completed_items' => $completed->map(fn ($item) => ['key' => $item['key'], 'label' => $item['label']])->values()->all(),
            'missing_items' => $missing->map(fn ($item) => ['key' => $item['key'], 'label' => $item['label'], 'link' => $item['link']])->values()->all(),
            'next_steps' => $missing->sortByDesc('weight')->map(fn ($item) => ['key' => $item['key'], 'label' => $item['label'], 'link' => $item['link']])->values()->all(),
        ];
    }
}
