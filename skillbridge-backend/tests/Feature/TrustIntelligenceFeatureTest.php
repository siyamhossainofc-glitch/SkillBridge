<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Category;
use App\Models\JobPost;
use App\Models\Service;
use App\Models\Skill;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrustIntelligenceFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_can_generate_an_ai_proposal_draft_from_grounded_facts(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('proposal-success');
        [$seeker, $provider, $job] = $this->jobAndProvider($category, $subcategory, $skill);
        config(['services.ai_proposal.key' => 'test-key', 'services.ai_proposal.endpoint' => 'https://ai.proposal/chat']);
        $content = json_encode(['message' => 'I can help with this React brief using my stored React skill.', 'amount' => 150, 'used_skill_ids' => [$skill->id], 'warnings' => ['Review before sending.']]);
        Http::fake(['https://ai.proposal/*' => Http::response(['choices' => [['message' => ['content' => $content]]]], 200)]);
        Sanctum::actingAs($provider);

        $this->postJson('/api/jobs/'.$job->id.'/proposal-assistance', ['amount' => 150])->assertOk()
            ->assertJsonPath('proposal.source', 'ai-v1')
            ->assertJsonPath('proposal.amount', 150)
            ->assertJsonPath('proposal.used_skill_ids.0', $skill->id);
    }

    public function test_proposal_ai_failure_returns_editable_safe_fallback_and_manual_bid_still_works(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('proposal-fallback');
        [$seeker, $provider, $job] = $this->jobAndProvider($category, $subcategory, $skill);
        config(['services.ai_proposal.key' => 'test-key', 'services.ai_proposal.endpoint' => 'https://ai.proposal/failure']);
        Http::fake(['https://ai.proposal/*' => Http::response([], 503)]);
        Sanctum::actingAs($provider);

        $this->postJson('/api/jobs/'.$job->id.'/proposal-assistance', ['amount' => 150])->assertOk()->assertJsonPath('proposal.source', 'deterministic-v1')->assertJsonPath('proposal.amount', 150);
        $this->postJson('/api/jobs/'.$job->id.'/bids', ['amount' => 150, 'message' => 'I can complete this brief using my stored skills.'])->assertCreated();
    }

    public function test_fabricated_provider_skill_facts_are_rejected(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('proposal-invalid');
        [$seeker, $provider, $job] = $this->jobAndProvider($category, $subcategory, $skill);
        config(['services.ai_proposal.key' => 'test-key', 'services.ai_proposal.endpoint' => 'https://ai.proposal/invalid']);
        $content = json_encode(['message' => 'I am certified in an unknown skill.', 'amount' => 150, 'used_skill_ids' => [999999], 'warnings' => []]);
        Http::fake(['https://ai.proposal/*' => Http::response(['choices' => [['message' => ['content' => $content]]]], 200)]);
        Sanctum::actingAs($provider);

        $this->postJson('/api/jobs/'.$job->id.'/proposal-assistance', ['amount' => 150])->assertOk()->assertJsonPath('proposal.source', 'deterministic-v1')->assertJsonMissing(['used_skill_ids' => [999999]]);
    }

    public function test_proposal_assistance_rejects_hidden_jobs(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('proposal-hidden');
        [$seeker, $provider, $job] = $this->jobAndProvider($category, $subcategory, $skill);
        $job->update(['moderation_status' => 'hidden']);
        Sanctum::actingAs($provider);

        $this->postJson('/api/jobs/'.$job->id.'/proposal-assistance', ['amount' => 150])->assertNotFound();
    }

    public function test_admin_can_read_ai_fraud_analysis_with_explainable_reasons_and_no_action(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('fraud-success');
        [$seeker, $provider, $job] = $this->jobAndProvider($category, $subcategory, $skill);
        $bid = Bid::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'amount' => 500, 'message' => 'Bid', 'status' => 'pending']);
        $admin = User::factory()->create(['role' => 'admin', 'moderation_status' => 'active']);
        config(['services.ai_fraud.key' => 'test-key', 'services.ai_fraud.endpoint' => 'https://ai.fraud/chat']);
        $content = json_encode(['risk_score' => 72, 'reasons' => ['Bid is materially above the stated budget.'], 'signal_keys' => ['amount_far_above_budget']]);
        Http::fake(['https://ai.fraud/*' => Http::response(['choices' => [['message' => ['content' => $content]]]], 200)]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/fraud/bids/'.$bid->id)->assertOk()->assertJsonPath('analysis.source', 'ai-v1')->assertJsonPath('analysis.risk_score', 72)->assertJsonPath('analysis.action_taken', false)->assertJsonPath('analysis.reasons.0', 'Bid is materially above the stated budget.');
        $this->assertDatabaseHas('users', ['id' => $provider->id, 'moderation_status' => 'active']);
    }

    public function test_fraud_ai_failure_uses_deterministic_fallback_and_non_admin_is_forbidden(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('fraud-fallback');
        [$seeker, $provider, $job] = $this->jobAndProvider($category, $subcategory, $skill);
        $bid = Bid::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'amount' => 500, 'message' => 'Bid', 'status' => 'pending']);
        config(['services.ai_fraud.key' => 'test-key', 'services.ai_fraud.endpoint' => 'https://ai.fraud/failure']);
        Http::fake(['https://ai.fraud/*' => Http::response([], 500)]);
        Sanctum::actingAs($provider);
        $this->getJson('/api/admin/fraud/bids/'.$bid->id)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin', 'moderation_status' => 'active']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/fraud/bids/'.$bid->id)->assertOk()->assertJsonPath('analysis.source', 'deterministic-v1')->assertJsonPath('analysis.action_taken', false)->assertJsonStructure(['analysis' => ['risk_score', 'risk_level', 'reasons', 'signal_keys', 'action_taken', 'notice']]);
    }

    private function jobAndProvider(Category $category, Subcategory $subcategory, Skill $skill): array
    {
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $provider = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active']);
        $provider->skills()->attach($skill);
        Service::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => 'Grounded service '.$provider->id, 'slug' => 'grounded-'.$provider->id, 'description' => 'Visible service', 'status' => 'published', 'moderation_status' => 'visible']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Grounded brief', 'description' => 'Use stored facts only', 'category' => $category->name, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'budget_min' => 100, 'budget_max' => 200, 'status' => 'open', 'location' => 'Dhaka', 'work_mode' => 'remote', 'moderation_status' => 'visible']);
        $job->requiredSkills()->attach($skill);
        return [$seeker, $provider, $job];
    }

    private function taxonomy(string $suffix): array
    {
        $category = Category::create(['name' => 'Category '.$suffix, 'slug' => 'category-'.$suffix, 'is_active' => true]);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Subcategory '.$suffix, 'slug' => 'subcategory-'.$suffix, 'is_active' => true]);
        $skill = Skill::create(['name' => 'React '.$suffix, 'slug' => 'react-'.$suffix, 'category_id' => $category->id]);
        return [$category, $subcategory, $skill];
    }
}
