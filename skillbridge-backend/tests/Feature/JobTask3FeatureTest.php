<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Category;
use App\Models\JobPost;
use App\Models\Notification;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class JobTask3FeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_job_search_and_all_basic_filters_are_server_side(): void
    {
        [$category, $subcategory] = $this->taxonomy('web-development', 'Web Development', 'frontend', 'Frontend');
        $matching = $this->job('Build a React dashboard', 'Dhaka', 'remote', $category, $subcategory, 5000, 9000);
        $this->job('Write a translation brief', 'Chattogram', 'on-site', $category, null, 1000, 2000);

        $response = $this->getJson('/api/jobs?search=React&category_id='.$category->id.'&subcategory_id='.$subcategory->id.'&work_mode=remote&location=Dhaka&budget_min=7000&budget_max=10000');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.work_mode', 'remote')
            ->assertJsonPath('data.0.category_relation.id', $category->id)
            ->assertJsonPath('data.0.subcategory.id', $subcategory->id);
    }

    public function test_public_job_detail_does_not_expose_seeker_contact_fields(): void
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        $job = JobPost::create([
            'user_id' => $seeker->id,
            'title' => 'Privacy-safe public job',
            'description' => 'A public job',
            'category' => 'General',
            'budget_min' => 100,
            'budget_max' => 200,
            'location' => 'Dhaka',
            'status' => 'open',
        ]);

        $this->getJson('/api/jobs/'.$job->id)
            ->assertOk()
            ->assertJsonPath('job.seeker.id', $seeker->id)
            ->assertJsonMissing(['email' => $seeker->email])
            ->assertJsonMissing(['phone' => $seeker->phone]);
    }

    public function test_invalid_work_mode_filter_is_rejected(): void
    {
        $this->getJson('/api/jobs?work_mode=office')->assertUnprocessable()->assertJsonValidationErrors(['work_mode']);
    }

    public function test_category_filter_returns_only_matching_jobs(): void
    {
        [$category] = $this->taxonomy('category-one', 'Category One', 'sub-one', 'Sub One');
        [$otherCategory] = $this->taxonomy('category-two', 'Category Two', 'sub-two', 'Sub Two');
        $matching = $this->job('Category match', 'Dhaka', 'remote', $category, null, 100, 200);
        $this->job('Category other', 'Dhaka', 'remote', $otherCategory, null, 100, 200);

        $this->getJson('/api/jobs?search=Category%20match&category_id='.$category->id)->assertOk()->assertJsonPath('data.0.id', $matching->id)->assertJsonCount(1, 'data');
    }

    public function test_subcategory_filter_returns_only_matching_jobs(): void
    {
        [$category, $subcategory] = $this->taxonomy('category-three', 'Category Three', 'sub-three', 'Sub Three');
        $otherSubcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Other Sub Three', 'slug' => 'other-sub-three', 'is_active' => true]);
        $matching = $this->job('Subcategory match', 'Dhaka', 'remote', $category, $subcategory, 100, 200);
        $this->job('Subcategory other', 'Dhaka', 'remote', $category, $otherSubcategory, 100, 200);

        $this->getJson('/api/jobs?search=Subcategory%20match&subcategory_id='.$subcategory->id)->assertOk()->assertJsonPath('data.0.id', $matching->id)->assertJsonCount(1, 'data');
    }

    public function test_work_mode_filter_returns_only_matching_jobs(): void
    {
        [$category] = $this->taxonomy('category-four', 'Category Four', 'sub-four', 'Sub Four');
        $matching = $this->job('Remote match', 'Dhaka', 'remote', $category, null, 100, 200);
        $this->job('Hybrid other', 'Dhaka', 'hybrid', $category, null, 100, 200);

        $this->getJson('/api/jobs?search=Remote%20match&work_mode=remote')->assertOk()->assertJsonPath('data.0.id', $matching->id)->assertJsonCount(1, 'data');
    }

    public function test_location_filter_returns_partial_location_matches(): void
    {
        [$category] = $this->taxonomy('category-five', 'Category Five', 'sub-five', 'Sub Five');
        $matching = $this->job('Location match', 'Dhaka North', 'remote', $category, null, 100, 200);
        $this->job('Location other', 'Chattogram', 'remote', $category, null, 100, 200);

        $this->getJson('/api/jobs?search=Location%20match&location=Dhaka')->assertOk()->assertJsonPath('data.0.id', $matching->id)->assertJsonCount(1, 'data');
    }

    public function test_invalid_category_and_subcategory_filter_combination_is_rejected(): void
    {
        [$category] = $this->taxonomy('category-six', 'Category Six', 'sub-six', 'Sub Six');
        [, $otherSubcategory] = $this->taxonomy('category-seven', 'Category Seven', 'sub-seven', 'Sub Seven');

        $this->getJson('/api/jobs?category_id='.$category->id.'&subcategory_id='.$otherSubcategory->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subcategory_id']);
    }

    public function test_unauthorized_user_cannot_update_another_users_job(): void
    {
        $owner = User::factory()->create(['role' => 'seeker']);
        $otherSeeker = User::factory()->create(['role' => 'seeker']);
        $job = JobPost::create(['user_id' => $owner->id, 'title' => 'Owned job', 'description' => 'A job', 'category' => 'General', 'budget_min' => 100, 'budget_max' => 200, 'location' => 'Dhaka', 'status' => 'open']);

        $this->actingAs($otherSeeker, 'sanctum')->putJson('/api/jobs/'.$job->id, ['title' => 'Tampered title'])
            ->assertForbidden();
        $this->assertDatabaseHas('job_posts', ['id' => $job->id, 'title' => 'Owned job']);
    }

    public function test_job_create_and_update_accept_work_mode_without_breaking_taxonomy(): void
    {
        [$category, $subcategory] = $this->taxonomy('design', 'Design', 'ui', 'UI Design');
        $seeker = User::factory()->create(['role' => 'seeker']);
        $request = $this->actingAs($seeker, 'sanctum');

        $created = $request->postJson('/api/jobs', [
            'title' => 'Design a landing page',
            'description' => 'Create a responsive landing page.',
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'budget_min' => 3000,
            'budget_max' => 6000,
            'location' => 'Dhaka',
            'work_mode' => 'hybrid',
        ]);

        $created->assertCreated()->assertJsonPath('job.work_mode', 'hybrid');
        $jobId = $created->json('job.id');
        $request->putJson('/api/jobs/'.$jobId, ['work_mode' => 'on-site'])->assertOk()->assertJsonPath('job.work_mode', 'on-site');
        $this->assertDatabaseHas('job_posts', ['id' => $jobId, 'work_mode' => 'on-site', 'category_id' => $category->id, 'subcategory_id' => $subcategory->id]);
    }

    public function test_changing_category_without_subcategory_clears_stale_subcategory(): void
    {
        [$firstCategory, $firstSubcategory] = $this->taxonomy('first', 'First', 'one', 'One');
        [$secondCategory] = $this->taxonomy('second', 'Second', 'two', 'Two');
        $seeker = User::factory()->create(['role' => 'seeker']);
        $job = $this->job('Change taxonomy', 'Dhaka', 'remote', $firstCategory, $firstSubcategory, 100, 200, $seeker);

        $this->actingAs($seeker, 'sanctum')->putJson('/api/jobs/'.$job->id, ['category_id' => $secondCategory->id])
            ->assertOk()
            ->assertJsonPath('job.category_id', $secondCategory->id)
            ->assertJsonPath('job.subcategory_id', null);
    }

    public function test_existing_job_bid_and_accept_flow_remains_available(): void
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        $provider = User::factory()->create(['role' => 'provider']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Existing flow', 'description' => 'A job', 'category' => 'General', 'budget_min' => 100, 'budget_max' => 200, 'location' => 'Dhaka', 'status' => 'open']);

        $bid = $this->actingAs($provider, 'sanctum')->postJson('/api/jobs/'.$job->id.'/bids', ['amount' => 150, 'message' => 'I can do this.'])->assertCreated()->json('bid');
        $this->assertSame('pending', $bid['status']);

        $this->actingAs($seeker, 'sanctum')->postJson('/api/bids/'.$bid['id'].'/accept')->assertCreated()->assertJsonStructure(['contract' => ['id', 'job_post_id', 'bid_id']]);
        $this->assertDatabaseHas('bids', ['id' => $bid['id'], 'status' => 'accepted']);
        $this->assertDatabaseHas('job_posts', ['id' => $job->id, 'status' => 'in_progress']);
    }

    public function test_bid_notifications_are_created_once_with_safe_links(): void
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        $provider = User::factory()->create(['role' => 'provider']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Notify job', 'description' => 'A job', 'category' => 'General', 'budget_min' => 100, 'budget_max' => 200, 'location' => 'Dhaka', 'status' => 'open']);

        $bidResponse = $this->actingAs($provider, 'sanctum')
            ->postJson('/api/jobs/'.$job->id.'/bids', ['amount' => 150])
            ->assertCreated();
        $bidId = $bidResponse->json('bid.id');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $seeker->id,
            'type' => 'bid.received',
        ]);
        $this->assertSame(1, Notification::where('user_id', $seeker->id)->where('type', 'bid.received')->count());

        $this->actingAs($seeker, 'sanctum')->postJson('/api/bids/'.$bidId.'/accept')->assertCreated();

        $accepted = Notification::where('user_id', $provider->id)->where('type', 'bid.accepted')->first();
        $this->assertNotNull($accepted);
        $this->assertSame('/jobs/'.$job->id, $accepted->data['link']);
    }

    private function taxonomy(string $categorySlug, string $categoryName, string $subcategorySlug, string $subcategoryName): array
    {
        $category = Category::create(['name' => $categoryName, 'slug' => $categorySlug, 'is_active' => true]);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => $subcategoryName, 'slug' => $subcategorySlug, 'is_active' => true]);
        return [$category, $subcategory];
    }

    private function job(string $title, string $location, string $workMode, Category $category, ?Subcategory $subcategory, int $min, int $max, ?User $owner = null): JobPost
    {
        $owner ??= User::factory()->create(['role' => 'seeker']);
        return JobPost::create(['user_id' => $owner->id, 'title' => $title, 'description' => $title, 'category' => $category->name, 'category_id' => $category->id, 'subcategory_id' => $subcategory?->id, 'budget_min' => $min, 'budget_max' => $max, 'location' => $location, 'work_mode' => $workMode, 'status' => 'open']);
    }
}
