<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioItem;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PortfolioController extends Controller
{
    public function publicForProvider($providerId)
    {
        $provider = User::where('role', 'provider')->where('moderation_status', 'active')->find($providerId);
        if (!$provider) return response()->json(['message' => 'Provider not found'], 404);
        return response()->json(['items' => $this->publicQuery()->where('provider_id', $provider->id)->get()]);
    }

    public function mine(Request $request)
    {
        return response()->json(['items' => $this->baseQuery()->where('provider_id', $request->user()->id)->orderBy('sort_order')->latest('created_at')->get()]);
    }

    public function show(Request $request, $id)
    {
        $item = $this->baseQuery()->with('provider:id,moderation_status')->find($id);
        if (!$item || $item->provider?->moderation_status === 'suspended' || $item->moderation_status !== 'visible' || ($item->status !== 'published' && $item->provider_id !== $request->user()?->id)) return response()->json(['message' => 'Portfolio item not found'], 404);
        return response()->json(['item' => $item]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());
        $this->validateTaxonomy($validated);
        $item = PortfolioItem::create(array_merge($validated, ['provider_id' => $request->user()->id]));
        return response()->json(['message' => 'Portfolio item created successfully', 'item' => $this->baseQuery()->find($item->id)], 201);
    }

    public function update(Request $request, $id)
    {
        $item = PortfolioItem::find($id);
        if (!$item) return response()->json(['message' => 'Portfolio item not found'], 404);
        $this->assertOwner($request, $item);
        $validated = $request->validate($this->rules(true));
        $this->validateTaxonomy(array_merge($item->only(['category_id', 'subcategory_id']), $validated));
        $item->update($validated);
        return response()->json(['message' => 'Portfolio item updated successfully', 'item' => $this->baseQuery()->find($item->id)]);
    }

    public function destroy(Request $request, $id)
    {
        $item = PortfolioItem::find($id);
        if (!$item) return response()->json(['message' => 'Portfolio item not found'], 404);
        $this->assertOwner($request, $item);
        $item->delete();
        return response()->json(['message' => 'Portfolio item deleted successfully']);
    }

    private function baseQuery()
    {
        return PortfolioItem::with(['category:id,name,slug', 'subcategory:id,category_id,name,slug']);
    }

    private function publicQuery()
    {
        return $this->baseQuery()->select(['id', 'provider_id', 'title', 'description', 'category_id', 'subcategory_id', 'project_url', 'image_url', 'sort_order', 'status', 'moderation_status', 'created_at'])->where('status', 'published')->where('moderation_status', 'visible')->whereHas('provider', fn ($provider) => $provider->where('moderation_status', 'active'))->orderBy('sort_order')->latest('created_at');
    }

    private function rules(bool $sometimes = false): array
    {
        $presence = $sometimes ? ['sometimes'] : ['required'];
        return [
            'title' => [...$presence, 'string', 'max:255', 'regex:/.*\S.*/'],
            'description' => [...$presence, 'string', 'regex:/.*\S.*/'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['sometimes', 'nullable', 'integer', 'exists:subcategories,id'],
            'project_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:draft,published,archived'],
        ];
    }

    private function validateTaxonomy(array $values): void
    {
        if (!empty($values['subcategory_id']) && empty($values['category_id'])) {
            throw ValidationException::withMessages(['category_id' => ['A category is required when a subcategory is selected.']]);
        }
        if (!empty($values['category_id']) && !empty($values['subcategory_id']) && !Subcategory::whereKey($values['subcategory_id'])->where('category_id', $values['category_id'])->exists()) {
            throw ValidationException::withMessages(['subcategory_id' => ['The selected subcategory does not belong to the selected category.']]);
        }
    }

    private function assertOwner(Request $request, PortfolioItem $item): void
    {
        abort_unless($item->provider_id === $request->user()->id, 403, 'You do not own this portfolio item.');
    }
}
