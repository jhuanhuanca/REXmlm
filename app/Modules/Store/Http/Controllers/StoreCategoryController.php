<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Store\Http\Resources\StoreProductCategoryResource;
use App\Modules\Store\Models\StoreProductCategory;
use App\Shared\Auth\Owned;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class StoreCategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $store = $request->user()?->store;

        if ($store === null) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        $categories = $store->productCategories()
            ->withCount('products')
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        return StoreProductCategoryResource::collection($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $request->user()?->store;

        if ($store === null || $request->user()?->cannot('create', StoreProductCategory::class)) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('store_product_categories', 'name')->where('store_id', $store->id),
            ],
        ]);

        $category = $store->productCategories()->create([
            'name' => trim($data['name']),
            'sort' => (int) $store->productCategories()->max('sort') + 1,
        ]);

        return (new StoreProductCategoryResource($category->loadCount('products')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, int $id): StoreProductCategoryResource|JsonResponse
    {
        $category = Owned::find('update', StoreProductCategory::query()->with('store')->find($id));

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('store_product_categories', 'name')
                    ->where('store_id', $category->store_id)
                    ->ignore($category->id),
            ],
        ]);

        $category->update(['name' => trim($data['name'])]);

        return new StoreProductCategoryResource($category->fresh()->loadCount('products'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $category = Owned::find('delete', StoreProductCategory::query()->with('store')->find($id));
        $category->delete();

        return response()->json(['message' => 'Categoría eliminada. Los productos quedan sin categoría.']);
    }
}
