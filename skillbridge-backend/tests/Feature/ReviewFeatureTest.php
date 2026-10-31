<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Contract;
use App\Models\JobPost;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReviewFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_review_requires_authentication_and_contract_participant_authorization(): void
    {
        [$contract, $seeker] = $this->contractFixture('completed', true);
        $this->postJson('/api/contracts/'.$contract->id.'/reviews', ['rating' => 5])->assertUnauthorized();
        $outsider = User::factory()->create(['role' => 'seeker']);
        $this->actingAs($outsider, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/reviews', ['rating' => 5])->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/contracts/'.$contract->id.'/reviews')->assertForbidden();
        $this->actingAs($seeker, 'sanctum')->getJson('/api/contracts/'.$contract->id.'/reviews')->assertOk();
    }

    public function test_both_contract_participants_can_review_each_other_once(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture('completed', true);
        $first = $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/reviews', ['rating' => 5, 'comment' => '  Excellent handoff.  '])->assertCreated();
        $first->assertJsonPath('review.reviewer_id', $seeker->id)->assertJsonPath('review.reviewee_id', $provider->id)->assertJsonPath('review.comment', 'Excellent handoff.')->assertJsonPath('review.status', 'published');
        $second = $this->actingAs($provider, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/reviews', ['rating' => 4, 'comment' => 'Good collaboration.'])->assertCreated();
        $second->assertJsonPath('review.reviewer_id', $provider->id)->assertJsonPath('review.reviewee_id', $seeker->id);
        $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/reviews', ['rating' => 3])->assertUnprocessable()->assertJsonValidationErrors(['review']);
        $this->assertSame(2, Review::where('contract_id', $contract->id)->count());
    }

    public function test_review_requires_legitimate_completed_contract(): void
    {
        foreach ([['active', true], ['cancelled', true], ['completed', false]] as [$status, $completedAt]) {
            [$contract, $seeker] = $this->contractFixture($status, $completedAt);
            $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/reviews', ['rating' => 5])->assertUnprocessable()->assertJsonValidationErrors(['contract']);
        }
    }

    public function test_rating_range_and_self_review_are_rejected(): void
    {
        [$contract, $seeker] = $this->contractFixture('completed', true);
        foreach ([0, 6, 'five'] as $rating) {
            $this->actingAs($seeker, 'sanctum')->postJson('/api/contracts/'.$contract->id.'/reviews', ['rating' => $rating])->assertUnprocessable()->assertJsonValidationErrors(['rating']);
        }

        $sameUserContract = $this->contractFixture('completed', true, true);
        $this->actingAs($sameUserContract[1], 'sanctum')->postJson('/api/contracts/'.$sameUserContract[0]->id.'/reviews', ['rating' => 5])->assertUnprocessable()->assertJsonValidationErrors(['contract']);
    }

    public function test_whitespace_only_review_comments_are_rejected(): void
    {
        [$contract, $seeker] = $this->contractFixture('completed', true);

        $this->actingAs($seeker, 'sanctum')
            ->postJson('/api/contracts/'.$contract->id.'/reviews', ['rating' => 5, 'comment' => " \t\n "])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_null_review_comment_is_allowed(): void
    {
        [$contract, $seeker] = $this->contractFixture('completed', true);

        $this->actingAs($seeker, 'sanctum')
            ->postJson('/api/contracts/'.$contract->id.'/reviews', ['rating' => 5, 'comment' => null])
            ->assertCreated()
            ->assertJsonPath('review.comment', null);

        $this->assertDatabaseHas('reviews', ['contract_id' => $contract->id, 'reviewer_id' => $seeker->id, 'comment' => null]);
    }

    public function test_public_provider_reviews_exclude_hidden_reviews_and_calculate_real_aggregate(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture('completed', true);
        Review::create(['contract_id' => $contract->id, 'reviewer_id' => $seeker->id, 'reviewee_id' => $provider->id, 'rating' => 5, 'comment' => 'Published', 'status' => 'published']);
        $secondContract = $this->contractFixture('completed', true);
        Review::create(['contract_id' => $secondContract[0]->id, 'reviewer_id' => $secondContract[1]->id, 'reviewee_id' => $provider->id, 'rating' => 1, 'comment' => 'Hidden', 'status' => 'hidden']);

        $response = $this->getJson('/api/providers/'.$provider->id.'/reviews')->assertOk();
        $response->assertJsonPath('average_rating', 5)->assertJsonPath('review_count', 1)->assertJsonCount(1, 'reviews')->assertJsonMissing(['comment' => 'Hidden'])->assertJsonMissing(['email' => $seeker->email]);
        $this->getJson('/api/users/'.$provider->id.'/reviews')->assertOk()->assertJsonPath('average_rating', 5)->assertJsonPath('review_count', 1);
    }

    public function test_review_detail_is_participant_only_and_reviews_are_immutable(): void
    {
        [$contract, $seeker, $provider] = $this->contractFixture('completed', true);
        $review = Review::create(['contract_id' => $contract->id, 'reviewer_id' => $seeker->id, 'reviewee_id' => $provider->id, 'rating' => 5, 'comment' => 'Immutable', 'status' => 'published']);
        $outsider = User::factory()->create(['role' => 'seeker']);
        $this->actingAs($outsider, 'sanctum')->getJson('/api/reviews/'.$review->id)->assertForbidden();
        $this->actingAs($provider, 'sanctum')->getJson('/api/reviews/'.$review->id)->assertOk()->assertJsonMissing(['email' => $seeker->email])->assertJsonMissing(['phone' => $seeker->phone]);
        $this->actingAs($seeker, 'sanctum')->patchJson('/api/reviews/'.$review->id, ['rating' => 1])->assertMethodNotAllowed();
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'rating' => 5, 'comment' => 'Immutable']);
    }

    private function contractFixture(string $status = 'active', bool $completedAt = false, bool $sameUser = false): array
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        $provider = $sameUser ? $seeker : User::factory()->create(['role' => 'provider']);
        $job = JobPost::create(['user_id' => $seeker->id, 'title' => 'Review job', 'description' => 'Review job', 'category' => 'General', 'budget_min' => 100, 'budget_max' => 1000, 'location' => 'Dhaka', 'status' => $status === 'completed' ? 'completed' : 'in_progress']);
        $bid = Bid::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'amount' => 500, 'message' => 'Accepted bid', 'status' => 'accepted']);
        $contract = Contract::create(['job_post_id' => $job->id, 'provider_id' => $provider->id, 'seeker_id' => $seeker->id, 'bid_id' => $bid->id, 'amount' => 500, 'status' => $status, 'started_at' => in_array($status, ['active', 'completed', 'cancelled'], true) ? now() : null, 'completed_at' => $status === 'completed' && $completedAt ? now() : null]);
        return [$contract, $seeker, $provider];
    }
}
