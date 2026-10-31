<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->with([
                'subcategories' => fn ($query) => $query->where('is_active', true)->orderBy('name'),
                'skills' => fn ($query) => $query->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        return response()->json(['categories' => $categories]);
    }
}
