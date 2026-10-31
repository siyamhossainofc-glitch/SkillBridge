<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Skill;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JobUnderstandingFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeker_can_parse_a_request_into_catalog_backed_requirements(): void
    {
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $category = Category::create(['name' => 'Development and Technology', 'slug' => 'development-and-technology', 'is_active' => true]);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Web Development', 'slug' => 'web-development', 'is_active' => true]);
        $skill = Skill::create(['name' => 'React', 'slug' => 'react', 'category_id' => $category->id]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode(['category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'required_skill_ids' => [$skill->id], 'work_mode' => 'remote', 'location' => 'Dhaka', 'budget_min' => 50000, 'budget_max' => 80000, 'deadline' => '2026-10-01', 'urgency' => 'urgent', 'preferred_experience' => '3+ years React'])]]]], 200)]);
        config(['services.ai_parser.key' => 'test-key', 'services.ai_parser.endpoint' => 'https://ai.test/chat/completions']);
        Sanctum::actingAs($seeker);

        $this->postJson('/api/jobs/understand', ['text' => 'Urgent remote React dashboard in Dhaka'])->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('requirements.category_id', $category->id)
            ->assertJsonPath('requirements.required_skill_ids.0', $skill->id)
            ->assertJsonPath('requirements.urgency', 'urgent');
    }

    public function test_ai_failure_returns_manual_fallback_signal(): void
    {
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        Http::fake(['*' => Http::response([], 500)]);
        config(['services.ai_parser.key' => 'test-key', 'services.ai_parser.endpoint' => 'https://ai.test/chat/completions']);
        Sanctum::actingAs($seeker);

        $this->postJson('/api/jobs/understand', ['text' => 'Need a website redesign'])->assertStatus(503)
            ->assertJsonPath('code', 'AI_PARSER_UNAVAILABLE')
            ->assertJsonPath('fallback_available', true);
    }

    public function test_job_creation_persists_ai_requirement_fields_and_required_skills(): void
    {
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $category = Category::create(['name' => 'Design', 'slug' => 'design', 'is_active' => true]);
        $skill = Skill::create(['name' => 'Figma', 'slug' => 'figma', 'category_id' => $category->id]);
        Sanctum::actingAs($seeker);

        $response = $this->postJson('/api/jobs', [
            'title' => 'Product design', 'description' => 'Design a product flow', 'category_id' => $category->id,
            'budget_min' => 100, 'budget_max' => 200, 'location' => 'Remote', 'work_mode' => 'remote',
            'required_skill_ids' => [$skill->id], 'deadline' => '2026-10-01', 'urgency' => 'high', 'preferred_experience' => '2 years',
        ])->assertCreated();

        $jobId = $response->json('job.id');
        $this->assertDatabaseHas('job_posts', ['id' => $jobId, 'deadline' => '2026-10-01', 'urgency' => 'high', 'preferred_experience' => '2 years']);
        $this->assertDatabaseHas('job_skill', ['job_post_id' => $jobId, 'skill_id' => $skill->id]);
    }
}
