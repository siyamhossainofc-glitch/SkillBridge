<?php

namespace Tests\Feature;

use App\Models\ProviderAvailability;
use App\Models\ProviderServiceArea;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AvailabilityServiceAreaFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeker_cannot_manage_availability_or_service_areas(): void
    {
        $seeker = User::factory()->create(['role' => 'seeker']);
        $this->actingAs($seeker, 'sanctum')->getJson('/api/availability')->assertForbidden();
        $this->actingAs($seeker, 'sanctum')->postJson('/api/availability', $this->availabilityPayload())->assertForbidden();
        $this->actingAs($seeker, 'sanctum')->getJson('/api/service-areas')->assertForbidden();
        $this->actingAs($seeker, 'sanctum')->postJson('/api/service-areas', $this->areaPayload())->assertForbidden();
    }

    public function test_provider_can_manage_own_availability_and_duplicate_or_invalid_schedules_are_rejected(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $this->actingAs($provider, 'sanctum')->postJson('/api/availability', $this->availabilityPayload())->assertCreated();
        $this->actingAs($provider, 'sanctum')->postJson('/api/availability', $this->availabilityPayload())->assertUnprocessable();
        $this->actingAs($provider, 'sanctum')->postJson('/api/availability', ['day_of_week' => 7, 'start_time' => '09:00', 'end_time' => '10:00'])->assertUnprocessable();
        $this->actingAs($provider, 'sanctum')->postJson('/api/availability', ['day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '12:00'])->assertUnprocessable();
        $this->actingAs($provider, 'sanctum')->postJson('/api/availability', ['day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '12:00'])->assertUnprocessable();
    }

    public function test_provider_cannot_modify_another_providers_availability(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $other = User::factory()->create(['role' => 'provider']);
        $item = ProviderAvailability::create(array_merge($this->availabilityPayload(), ['provider_id' => $owner->id]));
        $this->actingAs($other, 'sanctum')->putJson('/api/availability/'.$item->id, $this->availabilityPayload())->assertNotFound();
        $this->actingAs($other, 'sanctum')->deleteJson('/api/availability/'.$item->id)->assertNotFound();
    }

    public function test_provider_areas_validate_coordinates_support_multiple_rows_and_enforce_ownership(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $other = User::factory()->create(['role' => 'provider']);
        $this->actingAs($owner, 'sanctum')->postJson('/api/service-areas', $this->areaPayload())->assertCreated();
        $this->actingAs($owner, 'sanctum')->postJson('/api/service-areas', array_merge($this->areaPayload(), ['area_name' => 'Second area']))->assertCreated();
        $this->actingAs($owner, 'sanctum')->postJson('/api/service-areas', array_merge($this->areaPayload(), ['area_name' => ' ', 'latitude' => 91]))->assertUnprocessable()->assertJsonValidationErrors(['area_name', 'latitude']);
        $area = ProviderServiceArea::where('provider_id', $owner->id)->firstOrFail();
        $this->actingAs($other, 'sanctum')->putJson('/api/service-areas/'.$area->id, $this->areaPayload())->assertNotFound();
        $this->actingAs($other, 'sanctum')->deleteJson('/api/service-areas/'.$area->id)->assertNotFound();
    }

    public function test_public_profile_shows_only_active_records_and_never_coordinates(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        ProviderAvailability::create(array_merge($this->availabilityPayload(), ['provider_id' => $provider->id, 'is_active' => true]));
        ProviderAvailability::create(array_merge($this->availabilityPayload(), ['provider_id' => $provider->id, 'day_of_week' => 2, 'is_active' => false]));
        ProviderServiceArea::create(array_merge($this->areaPayload(), ['provider_id' => $provider->id, 'is_active' => true]));
        ProviderServiceArea::create(array_merge($this->areaPayload(), ['provider_id' => $provider->id, 'area_name' => 'Hidden area', 'is_active' => false]));
        $response = $this->getJson('/api/users/'.$provider->id)->assertOk();
        $response->assertJsonCount(1, 'availabilities')->assertJsonCount(1, 'service_areas')->assertJsonMissing(['area_name' => 'Hidden area']);
        $response->assertJsonMissing(['email' => $provider->email])->assertJsonMissing(['phone' => $provider->phone]);
        $this->assertArrayNotHasKey('latitude', $response->json('service_areas.0'));
        $this->assertArrayNotHasKey('longitude', $response->json('service_areas.0'));
        $this->assertArrayNotHasKey('lat', $response->json());
        $this->assertArrayNotHasKey('lng', $response->json());
    }

    private function availabilityPayload(): array
    {
        return ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true];
    }

    private function areaPayload(): array
    {
        return ['area_name' => 'Gulshan', 'city' => 'Dhaka', 'district' => 'Dhaka', 'postal_code' => '1212', 'latitude' => 23.7925, 'longitude' => 90.4078, 'radius_km' => 10, 'is_active' => true];
    }
}
