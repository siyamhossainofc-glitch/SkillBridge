<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Skill;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class JobUnderstandingService
{
    public function parse(string $text): array
    {
        $apiKey = config('services.ai_parser.key');
        $endpoint = config('services.ai_parser.endpoint');
        if (!$apiKey || !$endpoint) {
            throw new RuntimeException('AI parsing is not configured.');
        }

        $catalog = Category::query()
            ->where('is_active', true)
            ->with(['subcategories' => fn ($query) => $query->where('is_active', true)->select(['id', 'category_id', 'name', 'slug'])])
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
        $skills = Skill::query()->with('category:id,name,slug')->orderBy('name')->get(['id', 'name', 'slug', 'category_id']);

        $schema = [
            'type' => 'object',
            'properties' => [
                'category_id' => ['type' => ['integer', 'null']],
                'subcategory_id' => ['type' => ['integer', 'null']],
                'required_skill_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'work_mode' => ['type' => ['string', 'null'], 'enum' => ['remote', 'on-site', 'hybrid', null]],
                'location' => ['type' => ['string', 'null']],
                'budget_min' => ['type' => ['number', 'null']],
                'budget_max' => ['type' => ['number', 'null']],
                'deadline' => ['type' => ['string', 'null']],
                'urgency' => ['type' => ['string', 'null'], 'enum' => ['low', 'normal', 'high', 'urgent', null]],
                'preferred_experience' => ['type' => ['string', 'null']],
            ],
            'required' => ['category_id', 'subcategory_id', 'required_skill_ids', 'work_mode', 'location', 'budget_min', 'budget_max', 'deadline', 'urgency', 'preferred_experience'],
            'additionalProperties' => false,
        ];

        $response = Http::timeout(20)->withToken($apiKey)->post($endpoint, [
            'model' => config('services.ai_parser.model'),
            'messages' => [
                ['role' => 'system', 'content' => 'Extract job requirements only from the user request. Use only IDs from the supplied catalog. Never invent missing values. Return JSON matching the schema. Interpret relative dates using today as '.now()->toDateString().'.'],
                ['role' => 'user', 'content' => json_encode(['request' => $text, 'categories' => $catalog, 'skills' => $skills], JSON_THROW_ON_ERROR)],
            ],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'job_requirements', 'strict' => true, 'schema' => $schema]],
            'temperature' => 0,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('The AI parser request failed.');
        }
        $content = data_get($response->json(), 'choices.0.message.content');
        $parsed = is_string($content) ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($parsed)) {
            throw new RuntimeException('The AI parser returned an invalid response.');
        }

        return $this->normalize($parsed, $catalog, $skills);
    }

    private function normalize(array $parsed, $categories, $skills): array
    {
        $categoryIds = $categories->pluck('id')->map(fn ($id) => (int) $id)->all();
        $categoryId = isset($parsed['category_id']) ? (int) $parsed['category_id'] : null;
        $subcategoryId = isset($parsed['subcategory_id']) ? (int) $parsed['subcategory_id'] : null;
        if (!$categoryId || !in_array($categoryId, $categoryIds, true)) $categoryId = null;
        $validSubcategoryIds = $categories->firstWhere('id', $categoryId)?->subcategories->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [];
        if (!$subcategoryId || !in_array($subcategoryId, $validSubcategoryIds, true)) $subcategoryId = null;
        $skillIds = $skills->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
            'required_skill_ids' => collect($parsed['required_skill_ids'] ?? [])->map(fn ($id) => (int) $id)->filter(fn ($id) => in_array($id, $skillIds, true))->unique()->values()->all(),
            'work_mode' => in_array($parsed['work_mode'] ?? null, ['remote', 'on-site', 'hybrid'], true) ? $parsed['work_mode'] : null,
            'location' => filled($parsed['location'] ?? null) ? trim((string) $parsed['location']) : null,
            'budget_min' => isset($parsed['budget_min']) ? max(0, (float) $parsed['budget_min']) : null,
            'budget_max' => isset($parsed['budget_max']) ? max(0, (float) $parsed['budget_max']) : null,
            'deadline' => $this->validDate($parsed['deadline'] ?? null),
            'urgency' => in_array($parsed['urgency'] ?? null, ['low', 'normal', 'high', 'urgent'], true) ? $parsed['urgency'] : null,
            'preferred_experience' => filled($parsed['preferred_experience'] ?? null) ? trim((string) $parsed['preferred_experience']) : null,
        ];
    }

    private function validDate(?string $date): ?string
    {
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        return checkdate($month, $day, $year) ? $date : null;
    }
}
