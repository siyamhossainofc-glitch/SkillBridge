<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PortfolioItem;
use App\Models\ProviderAvailability;
use App\Models\ProviderServiceArea;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Skill;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdvancedSearchProfileCompletionFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_provider_search_filters_by_skill_and_excludes_seekers(): void
    {
        $category = Category::create(['name' => 'Search category', 'slug' => 'search-category-' . uniqid(), 'is_active' => true]);
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel-' . uniqid(), 'category_id' => $category->id]);
        $provider = User::factory()->create(['role' => 'provider', 'professional_title' => 'Laravel Developer', 'address' => 'Dhaka']);
        $provider->skills()->attach($skill->id);
        User::factory()->create(['role' => 'seeker', 'name' => 'Laravel seeker']);

        $response = $this->getJson('/api/providers?skill_id=' . $skill->id . '&location=Dhaka')->assertOk();
        $response->assertJsonPath('data.0.id', $provider->id);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_service_price_search_only_returns_published_matching_services(): void
    {
        [$category, $subcategory] = $this->taxonomy('price-search');
        $provider = User::factory()->create(['role' => 'provider']);
        $matching = Service::create($this->servicePayload($provider, $category, $subcategory, 'Published match', 'published'));
        ServicePackage::create(['service_id' => $matching->id, 'name' => 'Basic', 'description' => 'Basic', 'price' => 100, 'delivery_days' => 3, 'revisions' => 1]);
        $unpublished = Service::create($this->servicePayload($provider, $category, $subcategory, 'Draft hidden', 'draft'));
        ServicePackage::create(['service_id' => $unpublished->id, 'name' => 'Basic', 'description' => 'Basic', 'price' => 100, 'delivery_days' => 3, 'revisions' => 1]);

        $response = $this->getJson('/api/services?price_min=50&price_max=150')->assertOk();
        $this->assertSame([$matching->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_provider_completion_includes_actual_features_and_never_exceeds_100(): void
    {
        $provider = User::factory()->create(['role' => 'provider', 'professional_title' => 'Developer', 'bio' => 'Builds products', 'address' => 'Dhaka']);
        $category = Category::create(['name' => 'Completion category', 'slug' => 'completion-category-' . uniqid(), 'is_active' => true]);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Web', 'slug' => 'web-' . uniqid(), 'is_active' => true]);
        $skill = Skill::create(['name' => 'PHP', 'slug' => 'php-' . uniqid(), 'category_id' => $category->id]);
        $provider->skills()->attach($skill->id);
        $service = Service::create($this->servicePayload($provider, $category, $subcategory, 'Published service', 'published'));
        ServicePackage::create(['service_id' => $service->id, 'name' => 'Basic', 'description' => 'Basic', 'price' => 100, 'delivery_days' => 3, 'revisions' => 1]);
        PortfolioItem::create(['provider_id' => $provider->id, 'title' => 'Work', 'description' => 'Work', 'status' => 'published']);
        ProviderAvailability::create(['provider_id' => $provider->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true]);
        ProviderServiceArea::create(['provider_id' => $provider->id, 'area_name' => 'Gulshan', 'is_active' => true]);

        $completion = $this->actingAs($provider, 'sanctum')->getJson('/api/profile/completion')->assertOk()->json();
        $this->assertSame(100, $completion['completion_percentage']);
        $this->assertLessThanOrEqual(100, $completion['completion_percentage']);
        $this->assertCount(0, $completion['missing_items']);
    }

    public function test_empty_provider_completion_excludes_provider_only_keys_for_seeker(): void
    {
        // UserFactory always generates a non-null name (NOT NULL column), so the
        // 'name' check (weight 10) is satisfied from the start. A provider with
        // no other data therefore starts at 10%, not 0%.
        $provider = User::factory()->create(['role' => 'provider', 'professional_title' => null, 'bio' => null, 'address' => null]);
        $completion = $this->actingAs($provider, 'sanctum')->getJson('/api/profile/completion')->assertOk()->json();
        // Only 'name' (weight 10) is satisfied; all other provider checks are missing.
        $this->assertSame(10, $completion['completion_percentage']);
        $this->assertContains('services', array_column($completion['missing_items'], 'key'));
        $this->assertContains('professional_title', array_column($completion['missing_items'], 'key'));
        $this->assertContains('bio', array_column($completion['missing_items'], 'key'));
        $this->assertContains('availability', array_column($completion['missing_items'], 'key'));
        $this->assertContains('service_areas', array_column($completion['missing_items'], 'key'));

        // Seekers must NOT see provider-only keys in their missing_items.
        $seeker = User::factory()->create(['role' => 'seeker']);
        $seekerCompletion = $this->actingAs($seeker, 'sanctum')->getJson('/api/profile/completion')->assertOk()->json();
        $this->assertNotContains('services', array_column($seekerCompletion['missing_items'], 'key'));
        $this->assertNotContains('availability', array_column($seekerCompletion['missing_items'], 'key'));
        $this->assertNotContains('service_areas', array_column($seekerCompletion['missing_items'], 'key'));
        $this->assertNotContains('portfolio', array_column($seekerCompletion['missing_items'], 'key'));
    }

    /**
     * Regression: price_min and price_max must be satisfied by the SAME package
     * row, not by two different packages independently.
     *
     * A service with packages at $50 and $500 must NOT appear when filtering
     * price_min=100 & price_max=200, because no single package falls in [100,200].
     */
    public function test_service_price_filter_requires_single_package_in_range(): void
    {
        [$category, $subcategory] = $this->taxonomy('price-regression');
        $provider = User::factory()->create(['role' => 'provider']);

        // Service whose packages straddle the requested range ($50 and $500).
        // No single package is in $100–$200.
        $straddle = Service::create($this->servicePayload($provider, $category, $subcategory, 'Straddle Service', 'published'));
        ServicePackage::create(['service_id' => $straddle->id, 'name' => 'Cheap',     'description' => 'desc', 'price' => 50,  'delivery_days' => 1, 'revisions' => 0]);
        ServicePackage::create(['service_id' => $straddle->id, 'name' => 'Expensive', 'description' => 'desc', 'price' => 500, 'delivery_days' => 5, 'revisions' => 2]);

        // Service with a package genuinely within the requested range.
        $inRange = Service::create($this->servicePayload($provider, $category, $subcategory, 'In Range Service', 'published'));
        ServicePackage::create(['service_id' => $inRange->id, 'name' => 'Mid', 'description' => 'desc', 'price' => 150, 'delivery_days' => 3, 'revisions' => 1]);

        // Service with a package exactly at the lower bound (boundary inclusion).
        $atMin = Service::create($this->servicePayload($provider, $category, $subcategory, 'At Min Service', 'published'));
        ServicePackage::create(['service_id' => $atMin->id, 'name' => 'At Min', 'description' => 'desc', 'price' => 100, 'delivery_days' => 2, 'revisions' => 1]);

        $response = $this->getJson('/api/services?price_min=100&price_max=200')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($inRange->id, $ids,    'In-range service should be returned.');
        $this->assertContains($atMin->id,   $ids,    'Boundary-inclusive service should be returned.');
        $this->assertNotContains($straddle->id, $ids, 'Straddle service must NOT be returned (no single package in range).');
    }

    public function test_completion_is_private_to_authenticated_current_user(): void
    {
        $user = User::factory()->create(['role' => 'seeker']);
        $other = User::factory()->create(['role' => 'seeker']);
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/profile/completion')->assertOk();
        $this->assertArrayNotHasKey('user_id', $response->json());
        $this->assertNotSame($user->id, $other->id);
        $this->getJson('/api/profile/completion')->assertUnauthorized();
    }

    private function taxonomy(string $suffix): array
    {
        $category = Category::create(['name' => $suffix, 'slug' => $suffix . '-' . uniqid(), 'is_active' => true]);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => $suffix . ' sub', 'slug' => $suffix . '-sub-' . uniqid(), 'is_active' => true]);
        return [$category, $subcategory];
    }

    private function servicePayload(User $provider, Category $category, Subcategory $subcategory, string $title, string $status): array
    {
        return ['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title)) . '-' . uniqid(), 'description' => 'Useful service description', 'status' => $status];
    }
}
