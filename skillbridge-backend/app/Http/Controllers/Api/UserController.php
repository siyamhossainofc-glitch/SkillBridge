<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\User;

class UserController extends Controller
{
  public function index()
{
    $users = User::select([
        'id',
        'name',
        'role',
        'professional_title',
        'address',
        'rating',
        'bio',
    ])->where('moderation_status', 'active')->withExists(['verification as is_verified' => fn ($verification) => $verification->where('status', 'verified')])->get();

    return response()->json($users);
}

public function show($id)
{
    $user = User::select([
        'id',
        'name',
        'role',
        'professional_title',
        'address',
        'rating',
        'bio',
    ])->withExists(['verification as is_verified' => fn ($verification) => $verification->where('status', 'verified')])->with([
        'availabilities' => fn ($query) => $query->where('is_active', true)->select(['id', 'provider_id', 'day_of_week', 'start_time', 'end_time', 'is_active']),
        'serviceAreas' => fn ($query) => $query->where('is_active', true)->select(['id', 'provider_id', 'area_name', 'city', 'district', 'postal_code', 'radius_km', 'is_active']),
        'reviewsReceived' => fn ($query) => $query->where('status', 'published')->where('moderation_status', 'visible')->with('reviewer:id,name,role')->select(['id', 'reviewer_id', 'reviewee_id', 'contract_id', 'rating', 'comment', 'status', 'created_at']),
    ])->where('moderation_status', 'active')->find($id);

    if (!$user) {
        return response()->json([
            'message' => 'User not found'
        ], 404);
    }

    $publicReviewQuery = Review::where('reviewee_id', $user->id)->where('status', 'published')->where('moderation_status', 'visible');
    $user->setAttribute('average_rating', ($average = $publicReviewQuery->avg('rating')) === null ? null : round((float) $average, 2));
    $user->setAttribute('review_count', (clone $publicReviewQuery)->count());
    return response()->json($user);
}
}
