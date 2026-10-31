<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authenticated_user_can_get_profile_with_categorized_skills_and_reviews_shape(): void
    {
        $user = User::factory()->create(['role' => 'provider']);
        $skill = $this->makeSkill();
        $user->skills()->attach($skill->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/profile');

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.skills.0.id', $skill->id)
            ->assertJsonStructure(['user' => ['skills' => [['category']], 'reviews_received']]);
    }

    public function test_unauthenticated_user_cannot_access_profile(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_authenticated_user_can_update_profile_and_receives_consistent_profile_shape(): void
    {
        $user = User::factory()->create(['role' => 'seeker']);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/profile', [
            'name' => 'Updated Name',
            'professional_title' => 'Community Organizer',
            'bio' => 'A factual profile bio.',
            'phone' => '01700000000',
            'address' => 'Dhaka',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.name', 'Updated Name')
            ->assertJsonPath('user.professional_title', 'Community Organizer')
            ->assertJsonPath('user.skills', [])
            ->assertJsonStructure(['user' => ['skills', 'reviews_received']]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'professional_title' => 'Community Organizer']);
    }

    public function test_email_remains_protected_during_profile_update(): void
    {
        $user = User::factory()->create(['email' => 'original@example.test']);

        $this->actingAs($user, 'sanctum')->putJson('/api/profile', [
            'email' => 'changed@example.test',
            'name' => 'Safe Update',
        ])->assertOk()->assertJsonPath('user.email', 'original@example.test');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'original@example.test']);
        $this->assertDatabaseMissing('users', ['id' => $user->id, 'email' => 'changed@example.test']);
    }

    public function test_provider_can_add_and_remove_skills(): void
    {
        $user = User::factory()->create(['role' => 'provider']);
        $skill = $this->makeSkill();

        $this->actingAs($user, 'sanctum')->postJson('/api/profile/skills', ['skill_id' => $skill->id])
            ->assertOk()->assertJsonPath('skills.0.id', $skill->id);
        $this->assertDatabaseHas('user_skill', ['user_id' => $user->id, 'skill_id' => $skill->id]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/profile/skills/{$skill->id}")->assertOk();
        $this->assertDatabaseMissing('user_skill', ['user_id' => $user->id, 'skill_id' => $skill->id]);
    }

    public function test_seeker_can_add_and_remove_skills(): void
    {
        $user = User::factory()->create(['role' => 'seeker']);
        $skill = $this->makeSkill();

        $this->actingAs($user, 'sanctum')->postJson('/api/profile/skills', ['skill_id' => $skill->id])->assertOk();
        $this->assertDatabaseHas('user_skill', ['user_id' => $user->id, 'skill_id' => $skill->id]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/profile/skills/{$skill->id}")->assertOk();
        $this->assertDatabaseMissing('user_skill', ['user_id' => $user->id, 'skill_id' => $skill->id]);
    }

    public function test_duplicate_skill_attach_does_not_create_duplicate_user_skill_rows(): void
    {
        $user = User::factory()->create(['role' => 'provider']);
        $skill = $this->makeSkill();
        $request = $this->actingAs($user, 'sanctum');

        $request->postJson('/api/profile/skills', ['skill_id' => $skill->id])->assertOk();
        $request->postJson('/api/profile/skills', ['skill_id' => $skill->id])->assertOk();

        $this->assertSame(1, DB::table('user_skill')
            ->where('user_id', $user->id)
            ->where('skill_id', $skill->id)
            ->count());
    }

    private function makeSkill(): Skill
    {
        $category = Category::firstOrCreate(
            ['slug' => 'test-profile-category'],
            ['name' => 'Test Profile Category', 'is_active' => true],
        );

        return Skill::create([
            'name' => 'Test Skill '.Str::lower(Str::random(8)),
            'slug' => 'test-skill-'.Str::lower(Str::random(12)),
            'category_id' => $category->id,
        ]);
    }
}
