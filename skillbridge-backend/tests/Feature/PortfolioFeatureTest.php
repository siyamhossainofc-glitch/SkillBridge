<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PortfolioItem;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PortfolioFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeker_cannot_create_portfolio_item(): void
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        $this->actingAs($seeker, 'sanctum')->postJson('/api/portfolio', $this->payload())->assertForbidden();
    }

    public function test_provider_can_create_and_view_own_portfolio_items(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $payload = $this->payload();
        $created = $this->actingAs($provider, 'sanctum')->postJson('/api/portfolio', $payload)->assertCreated();
        $created->assertJsonPath('item.provider_id', $provider->id)->assertJsonPath('item.title', 'Case study');
        $this->actingAs($provider, 'sanctum')->getJson('/api/portfolio/my')->assertOk()->assertJsonCount(1, 'items');
    }

    public function test_provider_cannot_edit_or_delete_another_providers_item(): void
    {
        $owner = User::factory()->create(['role' => 'provider']); $other = User::factory()->create(['role' => 'provider']);
        $item = PortfolioItem::create(array_merge($this->payload(), ['provider_id' => $owner->id]));
        $this->actingAs($other, 'sanctum')->putJson('/api/portfolio/'.$item->id, ['title' => 'Changed'])->assertForbidden();
        $this->actingAs($other, 'sanctum')->deleteJson('/api/portfolio/'.$item->id)->assertForbidden();
    }

    public function test_taxonomy_validation_accepts_valid_values_and_rejects_invalid_combinations(): void
    {
        $provider = User::factory()->create(['role' => 'provider']); [$category, $subcategory] = $this->taxonomy('portfolio-one'); [$otherCategory] = $this->taxonomy('portfolio-two');
        $this->actingAs($provider, 'sanctum')->postJson('/api/portfolio', array_merge($this->payload(), ['category_id' => $category->id, 'subcategory_id' => $subcategory->id]))->assertCreated();
        $this->actingAs($provider, 'sanctum')->postJson('/api/portfolio', array_merge($this->payload(), ['category_id' => $otherCategory->id, 'subcategory_id' => $subcategory->id]))->assertUnprocessable()->assertJsonValidationErrors(['subcategory_id']);
        $this->actingAs($provider, 'sanctum')->postJson('/api/portfolio', array_merge($this->payload(), ['category_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
        $this->actingAs($provider, 'sanctum')->postJson('/api/portfolio', array_merge($this->payload(), ['subcategory_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['subcategory_id']);
    }

    public function test_blank_fields_and_invalid_urls_are_rejected(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $this->actingAs($provider, 'sanctum')->postJson('/api/portfolio', ['title' => ' ', 'description' => "\t", 'project_url' => 'not-a-url', 'image_url' => 'also-not-a-url'])->assertUnprocessable()->assertJsonValidationErrors(['title', 'description', 'project_url', 'image_url']);
    }

    public function test_public_endpoint_returns_only_published_items_in_sort_order(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        foreach ([['draft', 1], ['published', 2], ['archived', 0], ['published', 0]] as [$status, $sort]) PortfolioItem::create(array_merge($this->payload(), ['provider_id' => $provider->id, 'title' => $status.' '.$sort, 'sort_order' => $sort, 'status' => $status]));
        $response = $this->getJson('/api/portfolio/providers/'.$provider->id)->assertOk();
        $this->assertCount(2, $response->json('items')); $this->assertSame('published 0', $response->json('items.0.title')); $this->assertSame('published 2', $response->json('items.1.title'));
        $response->assertJsonMissing(['status' => 'draft'])->assertJsonMissing(['status' => 'archived']);
    }

    public function test_provider_can_delete_own_portfolio_item(): void
    {
        $provider = User::factory()->create(['role' => 'provider']); $item = PortfolioItem::create(array_merge($this->payload(), ['provider_id' => $provider->id]));
        $this->actingAs($provider, 'sanctum')->deleteJson('/api/portfolio/'.$item->id)->assertOk();
        $this->assertDatabaseMissing('portfolio_items', ['id' => $item->id]);
    }

    private function taxonomy(string $suffix): array
    {
        $category = Category::create(['name' => 'Portfolio Category '.$suffix, 'slug' => $suffix, 'is_active' => true]);
        return [$category, Subcategory::create(['category_id' => $category->id, 'name' => 'Portfolio Subcategory '.$suffix, 'slug' => $suffix.'-sub', 'is_active' => true])];
    }

    private function payload(): array
    {
        return ['title' => 'Case study', 'description' => 'A published portfolio case study.', 'project_url' => 'https://example.com/project', 'image_url' => 'https://example.com/image.jpg', 'sort_order' => 0, 'status' => 'draft'];
    }
}
