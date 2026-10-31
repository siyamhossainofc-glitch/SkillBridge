<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProviderServiceArea;
use Illuminate\Http\Request;

class ProviderServiceAreaController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['service_areas' => ProviderServiceArea::where('provider_id', $request->user()->id)->latest()->get()]);
    }

    public function store(Request $request)
    {
        $area = ProviderServiceArea::create(array_merge($this->validated($request), ['provider_id' => $request->user()->id]));
        return response()->json(['message' => 'Service area added successfully', 'service_area' => $area], 201);
    }

    public function update(Request $request, $id)
    {
        $area = ProviderServiceArea::where('provider_id', $request->user()->id)->find($id);
        if (!$area) return response()->json(['message' => 'Service area not found'], 404);
        $area->update($this->validated($request));
        return response()->json(['message' => 'Service area updated successfully', 'service_area' => $area->fresh()]);
    }

    public function destroy(Request $request, $id)
    {
        $area = ProviderServiceArea::where('provider_id', $request->user()->id)->find($id);
        if (!$area) return response()->json(['message' => 'Service area not found'], 404);
        $area->delete();
        return response()->json(['message' => 'Service area deleted successfully']);
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'area_name' => ['required', 'string', 'max:255', 'regex:/\\S/'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'district' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (trim($validated['area_name']) === '') {
            abort(response()->json(['message' => 'The area name cannot be blank.', 'errors' => ['area_name' => ['The area name cannot be blank.']]], 422));
        }
        $validated['area_name'] = trim($validated['area_name']);
        $validated['is_active'] = $validated['is_active'] ?? true;
        return $validated;
    }
}
