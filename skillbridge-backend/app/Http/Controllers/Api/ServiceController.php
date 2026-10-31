<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\SmartRankingService;
use Illuminate\Pagination\LengthAwarePaginator;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => 'sometimes|nullable|string|max:255',
            'category_id' => 'sometimes|nullable|integer|exists:categories,id',
            'subcategory_id' => 'sometimes|nullable|integer|exists:subcategories,id',
            'price_min' => 'sometimes|nullable|numeric|min:0',
            'price_max' => 'sometimes|nullable|numeric|min:0',
            'sort' => 'sometimes|nullable|in:recent,price_low,price_high,title,smart',
        ]);
        $this->validateCategoryCombination($validated);
        if (isset($validated['price_min'], $validated['price_max']) && $validated['price_min'] > $validated['price_max']) {
            throw ValidationException::withMessages(['price_max' => ['The maximum price must be greater than or equal to the minimum price.']]);
        }
        $query = Service::query()->where('status', 'published')->where('moderation_status', 'visible')->whereHas('provider', fn ($provider) => $provider->where('moderation_status', 'active'))->with([
            'category:id,name,slug', 'subcategory:id,category_id,name,slug',
            'provider' => fn ($provider) => $provider->select(['id', 'name', 'professional_title', 'bio', 'address', 'rating'])->withExists(['verification as is_verified' => fn ($verification) => $verification->where('status', 'verified')]),
        ])->withCount('packages')->latest();
        if (!empty($validated['search'])) {
            $term = trim($validated['search']);
            $query->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%"));
        }
        if (!empty($validated['category_id'])) $query->where('category_id', $validated['category_id']);
        if (!empty($validated['subcategory_id'])) $query->where('subcategory_id', $validated['subcategory_id']);
        // Both bounds must be satisfied by the SAME package row.
        // Two separate whereHas calls would incorrectly allow price_min to
        // match one package and price_max to match a different package,
        // causing false positives (e.g. packages at $50 and $500 would
        // wrongly satisfy a $100–$200 range).
        $priceMin = $validated['price_min'] ?? null;
        $priceMax = $validated['price_max'] ?? null;
        if ($priceMin !== null || $priceMax !== null) {
            $query->whereHas('packages', function ($packages) use ($priceMin, $priceMax) {
                if ($priceMin !== null) {
                    $packages->where('price', '>=', $priceMin);
                }
                if ($priceMax !== null) {
                    $packages->where('price', '<=', $priceMax);
                }
            });
        }
        if (($validated['sort'] ?? 'recent') === 'smart') {
            $ranked = app(SmartRankingService::class)->services($query->limit(1000)->get());
            $page = LengthAwarePaginator::resolveCurrentPage();
            $perPage = 12;
            return response()->json((new LengthAwarePaginator($ranked->forPage($page, $perPage)->values(), $ranked->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]))->withQueryString());
        }

        match ($validated['sort'] ?? 'recent') {
            'price_low' => $query->orderByRaw('(select min(price) from service_packages where service_packages.service_id = services.id) asc')->latest('services.created_at'),
            'price_high' => $query->orderByRaw('(select max(price) from service_packages where service_packages.service_id = services.id) desc')->latest('services.created_at'),
            'title' => $query->orderBy('title'),
            default => $query->latest('services.created_at'),
        };
        return response()->json($query->paginate(12)->withQueryString());
    }

    public function mine(Request $request)
    {
        return response()->json(['services' => Service::where('provider_id', $request->user()->id)->with(['category:id,name,slug', 'subcategory:id,category_id,name,slug'])->withCount('packages')->latest()->get()]);
    }

    public function show(Request $request, $id)
    {
        $service = Service::with([
            'category:id,name,slug', 'subcategory:id,category_id,name,slug',
            'provider:id,name,professional_title,bio,address,rating', 'packages',
        ])->find($id);
        if (!$service || $service->moderation_status !== 'visible' || ($service->status !== 'published' && $service->provider_id !== optional($request->user())->id) || $service->provider?->moderation_status === 'suspended') {
            return response()->json(['message' => 'Service not found'], 404);
        }
        return response()->json(['service' => $service]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->serviceRules());
        $this->validateCategoryCombination($validated);
        $validated['provider_id'] = $request->user()->id;
        $validated['slug'] = $this->uniqueSlug($validated['title']);
        $service = Service::create($validated);
        return response()->json(['message' => 'Service created successfully', 'service' => $service->load(['category:id,name,slug', 'subcategory:id,category_id,name,slug', 'packages'])], 201);
    }

    public function update(Request $request, $id)
    {
        $service = Service::find($id);
        if (!$service) return response()->json(['message' => 'Service not found'], 404);
        $this->assertOwner($request, $service);
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255', 'regex:/.*\S.*/'], 'description' => ['sometimes', 'required', 'string', 'regex:/.*\S.*/'],
            'category_id' => 'sometimes|integer|exists:categories,id', 'subcategory_id' => 'sometimes|integer|exists:subcategories,id',
            'status' => 'sometimes|in:draft,published,paused,archived',
        ]);
        $combined = array_merge($service->only(['category_id', 'subcategory_id']), $validated);
        $this->validateCategoryCombination($combined);
        if (array_key_exists('title', $validated) && $validated['title'] !== $service->title) $validated['slug'] = $this->uniqueSlug($validated['title'], $service->id);
        $service->update($validated);
        return response()->json(['message' => 'Service updated successfully', 'service' => $service->fresh()->load(['category:id,name,slug', 'subcategory:id,category_id,name,slug', 'packages'])]);
    }

    public function destroy(Request $request, $id)
    {
        $service = Service::find($id);
        if (!$service) return response()->json(['message' => 'Service not found'], 404);
        $this->assertOwner($request, $service);
        $service->delete();
        return response()->json(['message' => 'Service deleted successfully']);
    }

    public function storePackage(Request $request, $serviceId)
    {
        $service = Service::findOrFail($serviceId);
        $this->assertOwner($request, $service);
        $package = $service->packages()->create($request->validate($this->packageRules()));
        return response()->json(['message' => 'Package created successfully', 'package' => $package], 201);
    }

    public function updatePackage(Request $request, $serviceId, $packageId)
    {
        $service = Service::findOrFail($serviceId);
        $this->assertOwner($request, $service);
        $package = $service->packages()->whereKey($packageId)->firstOrFail();
        $package->update($request->validate($this->packageRules(true)));
        return response()->json(['message' => 'Package updated successfully', 'package' => $package->fresh()]);
    }

    public function destroyPackage(Request $request, $serviceId, $packageId)
    {
        $service = Service::findOrFail($serviceId);
        $this->assertOwner($request, $service);
        $package = $service->packages()->whereKey($packageId)->firstOrFail();
        $package->delete();
        return response()->json(['message' => 'Package deleted successfully']);
    }

    private function serviceRules(): array
    {
        return ['title' => ['required', 'string', 'max:255', 'regex:/.*\S.*/'], 'description' => ['required', 'string', 'regex:/.*\S.*/'], 'category_id' => 'required|integer|exists:categories,id', 'subcategory_id' => 'required|integer|exists:subcategories,id', 'status' => 'sometimes|in:draft,published,paused,archived'];
    }

    private function packageRules(bool $sometimes = false): array
    {
        $presence = $sometimes ? ['sometimes'] : ['required'];
        return ['name' => [...$presence, 'string', 'max:255', 'regex:/.*\S.*/'], 'description' => [...$presence, 'string', 'regex:/.*\S.*/'], 'price' => [...$presence, 'numeric', 'min:0'], 'delivery_days' => [...$presence, 'integer', 'min:1'], 'revisions' => [...$presence, 'integer', 'min:0'], 'sort_order' => 'sometimes|integer|min:0'];
    }

    private function validateCategoryCombination(array $values): void
    {
        if (!empty($values['category_id']) && !empty($values['subcategory_id']) && !Subcategory::whereKey($values['subcategory_id'])->where('category_id', $values['category_id'])->exists()) {
            throw ValidationException::withMessages(['subcategory_id' => ['The selected subcategory does not belong to the selected category.']]);
        }
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'service'; $slug = $base; $counter = 2;
        while (Service::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) $slug = $base.'-'.$counter++;
        return $slug;
    }

    private function assertOwner(Request $request, Service $service): void
    {
        abort_unless($service->provider_id === $request->user()->id, 403, 'You do not own this service.');
    }
}
