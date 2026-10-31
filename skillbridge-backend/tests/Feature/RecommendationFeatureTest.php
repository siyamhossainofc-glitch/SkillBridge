<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Favorite;
use App\Models\JobPost;
use App\Models\Service;
use App\Models\Skill;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecommendationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_matches_are_ranked_and_hidden_providers_are_excluded(): void
    {
        config(['services.ai_matching.key' => null]);
        [$category, $subcategory, $skill] = $this->taxonomy();
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $best = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active', 'address' => 'Dhaka']);
        $other = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active']);
        $hidden = User::factory()->create(['role' => 'provider', 'moderation_status' => 'suspended']);
        $best->skills()->attach($skill);
        $other->skills()->attach(Skill::create(['name' => 'Other skill', 'slug' => 'other-skill', 'category_id' => $category->id]));
        foreach ([$best, $other, $hidden] as $provider) {
            Service::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => 'Published service '.$provider->id, 'slug' => 'published-'.$provider->id, 'description' => 'Visible service', 'status' => 'published', 'moderation_status' => 'visible']);
        }
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'React dashboard', 'description' => 'Build a dashboard', 'category' => $category->name, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'budget_min' => 100, 'budget_max' => 200, 'status' => 'open', 'location' => 'Dhaka', 'work_mode' => 'on-site', 'moderation_status' => 'visible']);
        $job->requiredSkills()->attach($skill);

        Sanctum::actingAs($seeker);
        $this->getJson('/api/jobs/'.$job->id.'/matches')->assertOk()
            ->assertJsonPath('algorithm', 'deterministic-v1')
            ->assertJsonPath('matches.0.provider.id', $best->id)
            ->assertJsonPath('matches.0.match_score', 100)
            ->assertJsonMissing(['provider' => ['id' => $hidden->id]]);
    }

    public function test_only_job_owner_can_read_matches(): void
    {
        config(['services.ai_matching.key' => null]);
        $owner = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $other = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $job = JobPost::create(['user_id' => $owner->id, 'title' => 'Private brief', 'description' => 'Private', 'category' => 'General', 'budget_min' => 1, 'budget_max' => 2, 'status' => 'open', 'moderation_status' => 'visible']);
        Sanctum::actingAs($other);
        $this->getJson('/api/jobs/'.$job->id.'/matches')->assertNotFound();
    }

    public function test_recommendations_use_profile_skills_and_visible_records(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('recommend');
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $provider = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active']);
        $provider->skills()->attach($skill);
        Service::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => 'Relevant service', 'slug' => 'relevant-service', 'description' => 'Relevant', 'status' => 'published', 'moderation_status' => 'visible']);
        Favorite::create(['user_id' => $seeker->id, 'target_type' => 'provider', 'target_id' => $provider->id]);

        Sanctum::actingAs($seeker);
        $this->getJson('/api/recommendations')->assertOk()
            ->assertJsonPath('algorithm', 'deterministic-v1')
            ->assertJsonPath('recommendations.providers.0.provider.id', $provider->id)
            ->assertJsonPath('recommendations.services.0.service.title', 'Relevant service');
    }

    public function test_ai_ranks_only_the_real_eligible_candidate_set(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('ai-success');
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $first = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active']);
        $second = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active']);
        $first->skills()->attach($skill);
        $second->skills()->attach($skill);
        foreach ([$first, $second] as $provider) {
            Service::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => 'AI service '.$provider->id, 'slug' => 'ai-service-'.$provider->id, 'description' => 'Visible', 'status' => 'published', 'moderation_status' => 'visible']);
        }
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'AI brief', 'description' => 'Rank this', 'category' => $category->name, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'budget_min' => 10, 'budget_max' => 20, 'status' => 'open', 'location' => 'Dhaka', 'work_mode' => 'remote', 'moderation_status' => 'visible']);
        $job->requiredSkills()->attach($skill);
        config(['services.ai_matching.key' => 'test-key', 'services.ai_matching.endpoint' => 'https://ai.match/chat', 'services.ai_matching.model' => 'gpt-5-mini']);
        $aiContent = json_encode(['rankings' => [['provider_id' => $second->id, 'score' => 91, 'reasons' => ['Stronger contextual fit']], ['provider_id' => $first->id, 'score' => 64, 'reasons' => ['Skill match']]]]);
        Http::fake(['https://ai.match/*' => Http::response(['choices' => [['message' => ['content' => $aiContent]]]], 200)]);
        Sanctum::actingAs($seeker);

        $this->getJson('/api/jobs/'.$job->id.'/matches')->assertOk()
            ->assertJsonPath('algorithm', 'ai-v1')
            ->assertJsonPath('matches.0.provider.id', $second->id)
            ->assertJsonPath('matches.0.match_score', 91)
            ->assertJsonPath('matches.0.match_source', 'ai-v1');
    }

    public function test_ai_unavailable_returns_deterministic_v1_matches(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('ai-fallback');
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $provider = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active']);
        $provider->skills()->attach($skill);
        Service::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => 'Fallback service', 'slug' => 'fallback-service', 'description' => 'Visible', 'status' => 'published', 'moderation_status' => 'visible']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Fallback brief', 'description' => 'Fallback', 'category' => $category->name, 'category_id' => $category->id, 'budget_min' => 10, 'budget_max' => 20, 'status' => 'open', 'location' => 'Dhaka', 'work_mode' => 'remote', 'moderation_status' => 'visible']);
        $job->requiredSkills()->attach($skill);
        config(['services.ai_matching.key' => 'test-key', 'services.ai_matching.endpoint' => 'https://ai.match/failure']);
        Http::fake(['https://ai.match/*' => Http::response([], 503)]);
        Sanctum::actingAs($seeker);

        $this->getJson('/api/jobs/'.$job->id.'/matches')->assertOk()
            ->assertJsonPath('algorithm', 'deterministic-v1')
            ->assertJsonPath('matches.0.provider.id', $provider->id)
            ->assertJsonMissingPath('matches.0.match_source');
    }

    public function test_fabricated_ai_provider_ids_are_rejected_and_fallback_is_used(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('ai-invalid');
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $provider = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active']);
        $provider->skills()->attach($skill);
        Service::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => 'Safe service', 'slug' => 'safe-service', 'description' => 'Visible', 'status' => 'published', 'moderation_status' => 'visible']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Safe brief', 'description' => 'Reject fabricated IDs', 'category' => $category->name, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'budget_min' => 10, 'budget_max' => 20, 'status' => 'open', 'location' => 'Dhaka', 'work_mode' => 'remote', 'moderation_status' => 'visible']);
        $job->requiredSkills()->attach($skill);
        config(['services.ai_matching.key' => 'test-key', 'services.ai_matching.endpoint' => 'https://ai.match/invalid']);
        $aiContent = json_encode(['rankings' => [['provider_id' => 999999, 'score' => 100, 'reasons' => ['Fabricated']]]]);
        Http::fake(['https://ai.match/*' => Http::response(['choices' => [['message' => ['content' => $aiContent]]]], 200)]);
        Sanctum::actingAs($seeker);

        $this->getJson('/api/jobs/'.$job->id.'/matches')->assertOk()
            ->assertJsonPath('algorithm', 'deterministic-v1')
            ->assertJsonPath('matches.0.provider.id', $provider->id);
    }

    private function taxonomy(string $suffix = 'match'): array
    {
        $category = Category::create(['name' => 'Category '.$suffix, 'slug' => 'category-'.$suffix, 'is_active' => true]);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Subcategory '.$suffix, 'slug' => 'subcategory-'.$suffix, 'is_active' => true]);
        $skill = Skill::create(['name' => 'React '.$suffix, 'slug' => 'react-'.$suffix, 'category_id' => $category->id]);
        return [$category, $subcategory, $skill];
    }
}
