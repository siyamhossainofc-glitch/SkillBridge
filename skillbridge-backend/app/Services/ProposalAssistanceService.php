<?php

namespace App\Services;

use App\Models\JobPost;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

class ProposalAssistanceService
{
    public function draft(JobPost $job, User $provider, ?float $amount, ?string $draftMessage): array
    {
        $facts = $this->facts($job, $provider, $amount, $draftMessage);
        $fallback = [
            'message' => $this->fallbackMessage($facts),
            'amount' => $amount,
            'used_skill_ids' => $facts['provider_skill_ids'],
            'warnings' => ['Review and edit this draft before submitting. It contains only profile and job facts.'],
            'source' => 'deterministic-v1',
        ];
        $key = config('services.ai_proposal.key');
        $endpoint = config('services.ai_proposal.endpoint');
        if (!$key || !$endpoint) return $fallback;

        try {
            $response = Http::timeout(20)->withToken($key)->post($endpoint, [
                'model' => config('services.ai_proposal.model'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Write an editable marketplace proposal draft using only supplied facts. Never claim certifications, experience, portfolio work, guarantees, prices, or skills not present in the facts. Keep the amount unchanged. Return only the requested JSON.'],
                    ['role' => 'user', 'content' => json_encode($facts, JSON_THROW_ON_ERROR)],
                ],
                'response_format' => ['type' => 'json_schema', 'json_schema' => [
                    'name' => 'proposal_draft', 'strict' => true, 'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'amount' => ['type' => ['number', 'null']],
                            'used_skill_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['message', 'amount', 'used_skill_ids', 'warnings'],
                        'additionalProperties' => false,
                    ],
                ]],
                'temperature' => 0,
            ]);
            if (!$response->successful()) return $fallback;
            $content = data_get($response->json(), 'choices.0.message.content');
            $draft = is_string($content) ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : null;
            if (!is_array($draft) || !is_array($draft['used_skill_ids'] ?? null) || !is_array($draft['warnings'] ?? null) || trim((string) ($draft['message'] ?? '')) === '') return $fallback;
            $validSkills = array_fill_keys($facts['provider_skill_ids'], true);
            $usedSkills = collect($draft['used_skill_ids'] ?? [])->map(fn ($id) => filter_var($id, FILTER_VALIDATE_INT))->filter(fn ($id) => $id !== false && isset($validSkills[$id]))->unique()->values()->all();
            if (count($usedSkills) !== count($draft['used_skill_ids'] ?? [])) return $fallback;
            if (($draft['amount'] !== null && $amount === null) || ($draft['amount'] !== null && (float) $draft['amount'] !== $amount)) return $fallback;
            return [
                'message' => trim($draft['message']),
                'amount' => $amount,
                'used_skill_ids' => $usedSkills,
                'warnings' => collect($draft['warnings'] ?? [])->filter(fn ($warning) => is_string($warning))->values()->all() ?: $fallback['warnings'],
                'source' => 'ai-v1',
            ];
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function facts(JobPost $job, User $provider, ?float $amount, ?string $draftMessage): array
    {
        return [
            'job' => [
                'id' => (int) $job->id,
                'title' => $job->title,
                'description' => $job->description,
                'category_id' => $job->category_id ? (int) $job->category_id : null,
                'subcategory_id' => $job->subcategory_id ? (int) $job->subcategory_id : null,
                'required_skill_ids' => $job->requiredSkills->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'budget_min' => $job->budget_min,
                'budget_max' => $job->budget_max,
                'location' => $job->location,
                'work_mode' => $job->work_mode,
                'preferred_experience' => $job->preferred_experience,
            ],
            'provider' => [
                'id' => (int) $provider->id,
                'professional_title' => $provider->professional_title,
                'bio' => $provider->bio,
                'address' => $provider->address,
                'skill_ids' => $provider->skills->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'skill_names' => $provider->skills->pluck('name')->values()->all(),
                'service_titles' => $provider->services->where('status', 'published')->where('moderation_status', 'visible')->pluck('title')->values()->all(),
            ],
            'provider_skill_ids' => $provider->skills->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'amount' => $amount,
            'existing_draft_message' => $draftMessage,
        ];
    }

    private function fallbackMessage(array $facts): string
    {
        $job = $facts['job'];
        $provider = $facts['provider'];
        $skills = implode(', ', $provider['skill_names']);
        $message = 'I would like to help with “'.$job['title'].'”.';
        if ($provider['professional_title']) $message .= ' My profile title is '.$provider['professional_title'].'.';
        if ($skills) $message .= ' Relevant profile skills include '.$skills.'.';
        if ($job['work_mode'] || $job['location']) $message .= ' I will work within the stated '.trim(($job['work_mode'] ? $job['work_mode'].' ' : '').($job['location'] ? 'location requirement' : 'work-mode requirement')).'.';
        $message .= ' I will review the full brief and align the work with the supplied requirements.';
        return $message;
    }
}
