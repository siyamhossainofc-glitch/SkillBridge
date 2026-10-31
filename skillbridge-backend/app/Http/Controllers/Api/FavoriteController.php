<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function storeProvider(Request $request, $providerId)
    {
        User::where('role', 'provider')->findOrFail($providerId);
        return $this->store($request, 'provider', $providerId);
    }

    public function destroyProvider(Request $request, $providerId)
    {
        return $this->destroy($request, 'provider', $providerId);
    }

    public function storeService(Request $request, $serviceId)
    {
        if (!Service::whereKey($serviceId)->where('status', 'published')->exists()) {
            return response()->json(['message' => 'Service not found'], 404);
        }
        return $this->store($request, 'service', $serviceId);
    }

    public function destroyService(Request $request, $serviceId)
    {
        return $this->destroy($request, 'service', $serviceId);
    }

    public function index(Request $request)
    {
        $records = Favorite::where('user_id', $request->user()->id)->latest()->get();
        $providerIds = $records->where('target_type', 'provider')->pluck('target_id');
        $serviceIds = $records->where('target_type', 'service')->pluck('target_id');
        $providers = User::whereIn('id', $providerIds)->where('role', 'provider')->get(['id', 'name', 'professional_title', 'bio', 'address', 'rating']);
        $services = Service::whereIn('id', $serviceIds)->where('status', 'published')->with(['category:id,name,slug', 'subcategory:id,category_id,name,slug', 'provider:id,name,professional_title'])->get();
        return response()->json(['providers' => $providers->values(), 'services' => $services->values(), 'favorites' => $records->values()]);
    }

    private function store(Request $request, string $type, int $targetId)
    {
        $favorite = Favorite::firstOrCreate(['user_id' => $request->user()->id, 'target_type' => $type, 'target_id' => $targetId]);
        return response()->json(['message' => 'Favorite saved successfully', 'favorited' => true, 'favorite' => $favorite], $favorite->wasRecentlyCreated ? 201 : 200);
    }

    private function destroy(Request $request, string $type, int $targetId)
    {
        Favorite::where('user_id', $request->user()->id)->where('target_type', $type)->where('target_id', $targetId)->delete();
        return response()->json(['message' => 'Favorite removed successfully', 'favorited' => false]);
    }
}
