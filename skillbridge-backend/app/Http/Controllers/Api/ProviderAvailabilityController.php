<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProviderAvailability;
use Illuminate\Http\Request;

class ProviderAvailabilityController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['availability' => ProviderAvailability::where('provider_id', $request->user()->id)->orderBy('day_of_week')->orderBy('start_time')->get()]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        $this->rejectDuplicate($request, $validated);
        $availability = ProviderAvailability::create(array_merge($validated, ['provider_id' => $request->user()->id]));
        return response()->json(['message' => 'Availability added successfully', 'availability' => $availability], 201);
    }

    public function update(Request $request, $id)
    {
        $availability = ProviderAvailability::where('provider_id', $request->user()->id)->find($id);
        if (!$availability) return response()->json(['message' => 'Availability not found'], 404);
        $validated = $this->validated($request);
        $this->rejectDuplicate($request, $validated, $availability->id);
        $availability->update($validated);
        return response()->json(['message' => 'Availability updated successfully', 'availability' => $availability->fresh()]);
    }

    public function destroy(Request $request, $id)
    {
        $availability = ProviderAvailability::where('provider_id', $request->user()->id)->find($id);
        if (!$availability) return response()->json(['message' => 'Availability not found'], 404);
        $availability->delete();
        return response()->json(['message' => 'Availability deleted successfully']);
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($validated['end_time'] <= $validated['start_time']) {
            abort(response()->json(['message' => 'The end time must be after the start time.', 'errors' => ['end_time' => ['The end time must be after the start time.']]], 422));
        }
        $validated['is_active'] = $validated['is_active'] ?? true;
        return $validated;
    }

    private function rejectDuplicate(Request $request, array $validated, ?int $ignoreId = null): void
    {
        $query = ProviderAvailability::where('provider_id', $request->user()->id)
            ->where('day_of_week', $validated['day_of_week'])
            ->where('start_time', $validated['start_time'])
            ->where('end_time', $validated['end_time']);
        if ($ignoreId) $query->where('id', '!=', $ignoreId);
        if ($query->exists()) {
            abort(response()->json(['message' => 'That schedule already exists.', 'errors' => ['day_of_week' => ['That schedule already exists.']]], 422));
        }
    }
}
