<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\JobPost;
use App\Models\Service;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FavoritesSavedJobsFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authenticated_user_can_save_duplicate_save_is_idempotent_and_unsave(): void
    {
        $user = User::factory()->create(['role' => 'seeker']); $job = JobPost::factory()->create();
        $request = $this->actingAs($user, 'sanctum');
        $request->postJson('/api/jobs/'.$job->id.'/save')->assertCreated()->assertJsonPath('saved', true);
        $request->postJson('/api/jobs/'.$job->id.'/save')->assertOk()->assertJsonPath('saved', true);
        $this->assertDatabaseCount('saved_jobs', 1);
        $request->deleteJson('/api/jobs/'.$job->id.'/save')->assertOk()->assertJsonPath('saved', false);
        $this->assertDatabaseMissing('saved_jobs', ['user_id' => $user->id, 'job_post_id' => $job->id]);
    }

    public function test_unauthenticated_save_is_rejected_and_saved_jobs_are_scoped_to_current_user(): void
    {
        $job = JobPost::factory()->create(); $this->postJson('/api/jobs/'.$job->id.'/save')->assertUnauthorized();
        $first = User::factory()->create(['role' => 'seeker']); $second = User::factory()->create(['role' => 'provider']);
        $this->actingAs($first, 'sanctum')->postJson('/api/jobs/'.$job->id.'/save')->assertCreated();
        $this->actingAs($second, 'sanctum')->getJson('/api/saved-jobs')->assertOk()->assertJsonCount(0, 'saved_jobs');
        $this->actingAs($first, 'sanctum')->getJson('/api/saved-jobs')->assertOk()->assertJsonPath('saved_jobs.0.job.id', $job->id);
    }

    public function test_users_can_favorite_providers_and_services_without_duplicates(): void
    {
        $user = User::factory()->create(['role' => 'seeker']); $provider = User::factory()->create(['role' => 'provider']); [$category, $subcategory] = $this->taxonomy();
        $service = Service::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => 'Favorite service', 'slug' => 'favorite-service', 'description' => 'A service', 'status' => 'published']);
        $request = $this->actingAs($user, 'sanctum');
        $request->postJson('/api/favorites/provider/'.$provider->id)->assertCreated(); $request->postJson('/api/favorites/provider/'.$provider->id)->assertOk();
        $request->postJson('/api/favorites/service/'.$service->id)->assertCreated(); $request->postJson('/api/favorites/service/'.$service->id)->assertOk();
        $this->assertDatabaseCount('favorites', 2);
        $request->getJson('/api/favorites')->assertOk()->assertJsonCount(1, 'providers')->assertJsonCount(1, 'services');
        $request->deleteJson('/api/favorites/provider/'.$provider->id)->assertOk(); $request->deleteJson('/api/favorites/service/'.$service->id)->assertOk();
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_invalid_favorite_targets_are_rejected_and_seeker_and_provider_are_supported(): void
    {
        $seeker = User::factory()->create(['role' => 'seeker']); $provider = User::factory()->create(['role' => 'provider']); $serviceProvider = User::factory()->create(['role' => 'provider']); [$category, $subcategory] = $this->taxonomy('invalid');
        $service = Service::create(['provider_id' => $serviceProvider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => 'Another service', 'slug' => 'another-service', 'description' => 'A service', 'status' => 'published']);
        $this->actingAs($seeker, 'sanctum')->postJson('/api/favorites/provider/'.$provider->id)->assertCreated();
        $this->actingAs($provider, 'sanctum')->postJson('/api/favorites/service/'.$service->id)->assertCreated();
        $this->actingAs($seeker, 'sanctum')->postJson('/api/favorites/provider/999999')->assertNotFound();
        $this->actingAs($seeker, 'sanctum')->postJson('/api/favorites/service/999999')->assertNotFound();
    }

    public function test_non_published_services_cannot_be_favorited(): void
    {
        $user = User::factory()->create(['role' => 'seeker']); $provider = User::factory()->create(['role' => 'provider']); [$category, $subcategory] = $this->taxonomy('unpublished');
        foreach (['draft', 'paused', 'archived'] as $status) {
            $service = Service::create(['provider_id' => $provider->id, 'category_id' => $category->id, 'subcategory_id' => $subcategory->id, 'title' => ucfirst($status).' service', 'slug' => $status.'-favorite-service', 'description' => 'A service', 'status' => $status]);
            $this->actingAs($user, 'sanctum')->postJson('/api/favorites/service/'.$service->id)->assertNotFound()->assertJson(['message' => 'Service not found']);
        }
        $this->assertDatabaseCount('favorites', 0);
    }

    private function taxonomy(string $suffix = 'favorites'): array
    {
        $category = Category::create(['name' => 'Favorites Category '.$suffix, 'slug' => 'favorites-'.$suffix, 'is_active' => true]);
        return [$category, Subcategory::create(['category_id' => $category->id, 'name' => 'Favorites Subcategory '.$suffix, 'slug' => 'favorites-'.$suffix.'-sub', 'is_active' => true])];
    }
}
