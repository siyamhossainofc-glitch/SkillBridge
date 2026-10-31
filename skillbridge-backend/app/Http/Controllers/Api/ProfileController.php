<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProfileCompletionService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

       $user->load(['skills.category:id,name,slug', 'reviewsReceived:id,reviewee_id,rating,comment,created_at']);
        return response()->json([
            'user' => $user,
        ]);

    }

    public function update(Request $request)
{
    $validated = $request->validate([
        'name' => 'sometimes|string|max:255',
        'professional_title' => 'sometimes|nullable|string|max:255',
        'phone' => 'sometimes|nullable|string|max:20',
        'address' => 'sometimes|nullable|string|max:500',
        'lat' => 'sometimes|nullable|numeric',
        'lng' => 'sometimes|nullable|numeric',
        'bio' => 'sometimes|nullable|string|max:1000',
    ]);

    $user = $request->user();

    $user->update($validated);
    $user->load(['skills.category:id,name,slug', 'reviewsReceived:id,reviewee_id,rating,comment,created_at']);

    return response()->json([
        'message' => 'Profile updated successfully',
        'user' => $user,
    ]);
}

    public function completion(Request $request, ProfileCompletionService $completionService)
    {
        return response()->json($completionService->calculate($request->user()));
    }
}
