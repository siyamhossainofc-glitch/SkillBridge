<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Category;
use App\Models\JobPost;
use App\Models\ProviderVerification;
use App\Models\ProviderServiceArea;
use App\Models\ProviderAvailability;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Skill;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmartRankingIntelligenceFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_smart_ranking_is_deterministic_and_excludes_suspended_providers(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('providers');
        $best = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active', 'professional_title' => 'Senior developer', 'bio' => 'Detailed profile', 'address' => 'Dhaka', 'rating' => 5]);
        $best->skills()->attach($skill);
        ProviderVerification::create(['user_id' => $best->id, 'status' => 'verified']);
        Service::create(['provider_id' => $best->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => 'Visible service', 'slug' => 'visible-provider-service', 'description' => 'Complete', 'status' => 'published', 'moderation_status' => 'visible']);
        $weak = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active', 'name' => 'Weak profile']);
        $hidden = User::factory()->create(['role' => 'provider', 'moderation_status' => 'suspended']);
        Sanctum::actingAs(User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']));

        $this->getJson('/api/providers?sort=smart')->assertOk()->assertJsonPath('data.0.id', $best->id)->assertJsonMissing(['id' => $hidden->id]);
        $this->getJson('/api/providers?sort=smart')->assertJsonPath('data.0.id', $best->id);
    }

    public function test_job_and_service_smart_ranking_use_real_signals(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('results');
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $provider = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active', 'professional_title' => 'Designer', 'bio' => 'Detailed', 'address' => 'Dhaka', 'rating' => 5]);
        $provider->skills()->attach($skill);
        $bestJob = JobPost::create(['user_id' => $seeker->id, 'title' => 'Categorized urgent job', 'description' => 'Detailed', 'category' => $category->name, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'budget_min' => 100, 'budget_max' => 200, 'status' => 'open', 'location' => 'Dhaka', 'urgency' => 'urgent', 'moderation_status' => 'visible']);
        $bestJob->requiredSkills()->attach($skill);
        $plainJob = JobPost::create(['user_id' => $seeker->id, 'title' => 'Plain job', 'description' => 'Plain', 'category' => 'General', 'budget_min' => null, 'budget_max' => null, 'status' => 'open', 'moderation_status' => 'visible']);
        Bid::create(['job_post_id' => $bestJob->id, 'provider_id' => $provider->id, 'amount' => 150, 'message' => 'Bid', 'status' => 'pending']);
        $service = Service::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => 'Ranked service', 'slug' => 'ranked-service', 'description' => 'Detailed', 'status' => 'published', 'moderation_status' => 'visible']);
        ServicePackage::create(['service_id' => $service->id, 'name' => 'Basic', 'description' => 'Basic', 'price' => 100, 'delivery_days' => 3, 'revisions' => 1]);

        $this->getJson('/api/jobs?sort=smart')->assertOk()->assertJsonPath('data.0.id', $bestJob->id)->assertJsonPath('data.0.smart_rank_score', 63);
        $this->getJson('/api/jobs?sort=smart&budget_min=100&budget_max=200')->assertOk()->assertJsonPath('data.0.id', $bestJob->id)->assertJsonPath('data.0.smart_rank_score', 68)->assertJsonFragment(['Budget overlaps the requested range']);
        $this->getJson('/api/services?sort=smart')->assertOk()->assertJsonPath('data.0.id', $service->id)->assertJsonPath('data.0.smart_rank_score', 72);
        $this->assertDatabaseHas('job_posts', ['id' => $plainJob->id]);
    }

    public function test_provider_location_and_availability_signals_use_active_stored_records(): void
    {
        [$category, $subcategory, $skill] = $this->taxonomy('compatibility');
        $matched = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active', 'address' => 'Other place']);
        $matched->skills()->attach($skill);
        ProviderServiceArea::create(['provider_id' => $matched->id, 'area_name' => 'Dhaka North', 'city' => 'Dhaka', 'is_active' => true]);
        ProviderAvailability::create(['provider_id' => $matched->id, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'is_active' => true]);
        $unmatched = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active', 'address' => 'Chattogram']);
        Sanctum::actingAs(User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']));

        $response = $this->getJson('/api/providers?sort=smart&location=Dhaka')->assertOk();
        $response->assertJsonPath('data.0.id', $matched->id)->assertJsonFragment(['Active service area matched'])->assertJsonFragment(['Active availability recorded'])->assertJsonMissing(['id' => $unmatched->id]);
    }

    public function test_marketplace_intelligence_is_admin_only_and_aggregates_visible_records(): void
    {
        [$category, $subcategory] = $this->taxonomyWithoutSkill('intelligence');
        $seeker = User::factory()->create(['role' => 'seeker', 'moderation_status' => 'active']);
        $provider = User::factory()->create(['role' => 'provider', 'moderation_status' => 'active']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Demand job', 'description' => 'Demand', 'category' => $category->name, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'budget_min' => 10, 'budget_max' => 20, 'status' => 'open', 'moderation_status' => 'visible']);
        Service::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => 'Supply service', 'slug' => 'supply-service', 'description' => 'Supply', 'status' => 'published', 'moderation_status' => 'visible']);
        $admin = User::factory()->create(['role' => 'admin', 'moderation_status' => 'active']);
        Sanctum::actingAs($seeker);
        $this->getJson('/api/admin/intelligence')->assertForbidden();
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/intelligence?days=30')->assertOk()->assertJsonPath('demand_trends.0.category_id', $category->id)->assertJsonPath('demand_trends.0.job_demand', 1)->assertJsonPath('demand_trends.0.published_service_supply', 1)->assertJsonPath('privacy.fraud_signals_included', false);
        $this->assertDatabaseHas('job_posts', ['id' => $job->id]);
    }

    public function test_marketplace_intelligence_handles_empty_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'moderation_status' => 'active']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/intelligence')->assertOk()->assertJsonPath('demand_trends', [])->assertJsonPath('activity.visible_jobs_created', 0)->assertJsonPath('activity.bids_submitted', 0)->assertJsonPath('activity.average_bids_per_job', 0);
    }

    private function taxonomy(string $suffix): array
    {
        [$category, $subcategory] = $this->taxonomyWithoutSkill($suffix);
        return [$category, $subcategory, Skill::create(['name' => 'Skill '.$suffix, 'slug' => 'skill-'.$suffix, 'category_id' => $category->id])];
    }

    private function taxonomyWithoutSkill(string $suffix): array
    {
        $category = Category::create(['name' => 'Category '.$suffix, 'slug' => 'category-'.$suffix, 'is_active' => true]);
        return [$category, Subcategory::create(['category_id' => $category->id, 'name' => 'Subcategory '.$suffix, 'slug' => 'subcategory-'.$suffix, 'is_active' => true])];
    }
}
