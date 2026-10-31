<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ServiceFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeker_cannot_create_service(): void
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        [$category, $subcategory] = $this->taxonomy('services-one');
        $this->actingAs($seeker, 'sanctum')->postJson('/api/services', $this->payload($category, $subcategory))->assertForbidden();
    }

    public function test_provider_can_create_service_with_valid_taxonomy(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        [$category, $subcategory] = $this->taxonomy('services-two');
        $response = $this->actingAs($provider, 'sanctum')->postJson('/api/services', $this->payload($category, $subcategory));
        $response->assertCreated()->assertJsonPath('service.provider_id', $provider->id)->assertJsonPath('service.slug', 'web-design');
        $this->assertDatabaseHas('services', ['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'status' => 'draft']);
    }

    public function test_blank_service_title_and_description_are_rejected(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        [$category, $subcategory] = $this->taxonomy('services-blank-service');

        $this->actingAs($provider, 'sanctum')->postJson('/api/services', [
            'title' => '   ',
            'description' => '',
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'description']);
    }

    public function test_provider_cannot_edit_another_providers_service(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $other = User::factory()->create(['role' => 'provider']);
        [$category, $subcategory] = $this->taxonomy('services-three');
        $service = Service::create(array_merge($this->payload($category, $subcategory), ['provider_id' => $owner->id, 'slug' => 'owned-service']));
        $this->actingAs($other, 'sanctum')->putJson('/api/services/'.$service->id, ['title' => 'Tampered'])->assertForbidden();
    }

    public function test_invalid_category_subcategory_combination_is_rejected(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        [$category, $subcategory] = $this->taxonomy('services-four');
        [$otherCategory] = $this->taxonomy('services-five');
        $this->actingAs($provider, 'sanctum')->postJson('/api/services', $this->payload($otherCategory, $subcategory))
            ->assertUnprocessable()->assertJsonValidationErrors(['subcategory_id']);
        $this->assertNotSame($category->id, $otherCategory->id);
    }

    public function test_only_published_services_are_publicly_listed(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        [$category, $subcategory] = $this->taxonomy('services-six');
        foreach (['draft', 'paused', 'archived', 'published'] as $status) {
            Service::create(array_merge($this->payload($category, $subcategory), ['provider_id' => $provider->id, 'title' => ucfirst($status).' service', 'slug' => $status.'-service', 'status' => $status]));
        }
        $response = $this->getJson('/api/services')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('published', $response->json('data.0.status'));
    }

    public function test_service_search_and_taxonomy_filters_work(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        [$category, $subcategory] = $this->taxonomy('services-seven');
        [$otherCategory, $otherSubcategory] = $this->taxonomy('services-eight');
        $matching = Service::create(array_merge($this->payload($category, $subcategory), ['provider_id' => $provider->id, 'slug' => 'logo-design', 'status' => 'published']));
        Service::create(array_merge($this->payload($otherCategory, $otherSubcategory), ['provider_id' => $provider->id, 'title' => 'Other service', 'slug' => 'other-service', 'status' => 'published']));
        $response = $this->getJson('/api/services?search=Web%20Design&category_id='.$category->id.'&subcategory_id='.$subcategory->id)->assertOk();
        $response->assertJsonPath('data.0.id', $matching->id)->assertJsonPath('data.0.provider.id', $provider->id);
    }

    public function test_provider_can_create_update_and_delete_packages(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        [$category, $subcategory] = $this->taxonomy('services-nine');
        $service = Service::create(array_merge($this->payload($category, $subcategory), ['provider_id' => $provider->id, 'slug' => 'packages-service']));
        $request = $this->actingAs($provider, 'sanctum');
        $created = $request->postJson('/api/services/'.$service->id.'/packages', ['name' => 'Basic', 'description' => 'A basic package', 'price' => 100, 'delivery_days' => 3, 'revisions' => 1])->assertCreated();
        $packageId = $created->json('package.id');
        $request->putJson('/api/services/'.$service->id.'/packages/'.$packageId, ['price' => 125])->assertOk()->assertJsonPath('package.price', '125.00');
        $request->deleteJson('/api/services/'.$service->id.'/packages/'.$packageId)->assertOk();
        $this->assertDatabaseMissing('service_packages', ['id' => $packageId]);
    }

    public function test_package_validation_and_duplicate_slugs_are_safe(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        [$category, $subcategory] = $this->taxonomy('services-ten');
        $request = $this->actingAs($provider, 'sanctum');
        $first = $request->postJson('/api/services', $this->payload($category, $subcategory))->assertCreated();
        $second = $request->postJson('/api/services', $this->payload($category, $subcategory))->assertCreated();
        $this->assertSame('web-design-2', $second->json('service.slug'));
        $this->assertDatabaseHas('services', ['id' => $first->json('service.id'), 'slug' => 'web-design']);
        $this->assertDatabaseHas('services', ['id' => $second->json('service.id'), 'slug' => 'web-design-2']);
        $request->postJson('/api/services/'.$first->json('service.id').'/packages', ['name' => 'Basic', 'description' => '', 'price' => -1, 'delivery_days' => 0, 'revisions' => -1])->assertUnprocessable();
    }

    public function test_blank_package_name_and_description_are_rejected(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        [$category, $subcategory] = $this->taxonomy('services-blank-package');
        $service = Service::create(array_merge($this->payload($category, $subcategory), ['provider_id' => $provider->id, 'slug' => 'blank-package-service']));

        $this->actingAs($provider, 'sanctum')->postJson('/api/services/'.$service->id.'/packages', [
            'name' => '   ',
            'description' => "\t\n",
            'price' => 100,
            'delivery_days' => 3,
            'revisions' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'description']);
    }

    private function taxonomy(string $suffix): array
    {
        $category = Category::create(['name' => 'Service Category '.$suffix, 'slug' => $suffix, 'is_active' => true]);
        return [$category, Subcategory::create(['category_id' => $category->id, 'name' => 'Service Subcategory '.$suffix, 'slug' => $suffix.'-sub', 'is_active' => true])];
    }

    private function payload(Category $category, Subcategory $subcategory): array
    {
        return ['title' => 'Web Design', 'description' => 'A real service description.', 'category_id' => $category->id, 'subcategory_id' => $subcategory->id];
    }
}
