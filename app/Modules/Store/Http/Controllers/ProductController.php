<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Store\Actions\ImportPersonalProductsAction;
use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Http\Requests\ImportProductsRequest;
use App\Modules\Store\Http\Requests\StoreProductRequest;
use App\Modules\Store\Http\Resources\ProductResource;
use App\Modules\Store\Http\Resources\PublicProductResource;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\InventoryAlertService;
use App\Services\Catalog\CompanyCatalogSync;
use App\Shared\Auth\Owned;
use App\Shared\Support\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request, CompanyCatalogSync $sync, InventoryAlertService $alerts): AnonymousResourceCollection|JsonResponse
    {
        $store = $request->user()->store;

        if ($store === null) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        $sync->syncStoreIfStale($store);
        $alerts->scanStore($store);

        $query = $store->products()->with('category')->latest();
        $source = $request->string('source')->toString();

        if (in_array($source, [
            ProductSource::Personal->value,
            ProductSource::Company->value,
            ProductSource::Incentive->value,
        ], true)) {
            $query->where('source', $source);
        }

        $perPage = min(100, max(1, $request->integer('per_page', 50)));
        $page = $query->with(['allocations', 'category', 'incentiveProduct'])->paginate($perPage);
        $page->getCollection()->each(fn (Product $product) => $product->setRelation('store', $store));

        return ProductResource::collection($page);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $store = $request->user()->store;

        if ($store === null) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        $payload = $request->validated();
        $source = ProductSource::tryFrom((string) ($payload['source'] ?? ProductSource::Personal->value))
            ?? ProductSource::Personal;

        if ($source === ProductSource::Company) {
            $source = ProductSource::Personal;
        }

        $payload['source'] = $source;
        $payload['purchase_cost'] = round((float) ($payload['purchase_cost'] ?? 0), 2);
        $payload['incentive_qty'] = max(1, (int) ($payload['incentive_qty'] ?? 1));
        $payload['is_published'] = array_key_exists('is_published', $payload)
            ? (bool) $payload['is_published']
            : $source !== ProductSource::Incentive;
        $payload['fulfillment'] = $payload['fulfillment'] ?? ProductFulfillment::Stock;
        $payload['stock'] = (int) ($payload['stock'] ?? 0);
        $payload['price'] = round((float) ($payload['price'] ?? 0), 2);
        $payload['currency'] = Currencies::normalize($payload['currency'] ?? $store->currency(), $store->currency());

        if ($source === ProductSource::Incentive) {
            $payload['incentive_product_id'] = null;
            $payload['incentive_qty'] = 1;
            $payload['is_published'] = false;
        }

        $this->assertIncentiveCurrency($store, $payload);

        $product = $store->products()->create($payload);
        $product->setRelation('store', $store);
        $product->load(['category', 'incentiveProduct']);

        return (new ProductResource($product->fresh(['store', 'category', 'incentiveProduct'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreProductRequest $request, int $id): ProductResource
    {
        $product = $this->ownedProduct($request, $id);

        $payload = $request->validated();
        if (isset($payload['currency'])) {
            $payload['currency'] = Currencies::normalize($payload['currency'], $product->store?->currency() ?? Currencies::COMMISSION);
        }
        $this->assertIncentiveCurrency($product->store ?? $request->user()->store, $payload, $product);

        if ($product->isCompanyItem()) {
            $product->update(collect($payload)->only([
                'stock',
                'is_published',
                'fulfillment',
                'expires_at',
                'dropship_url',
                'dropship_sku',
                'is_active',
                'purchase_cost',
                'incentive_product_id',
                'incentive_qty',
                'price',
                'currency',
            ])->all());
        } else {
            if ($product->isIncentiveItem()) {
                $payload['source'] = ProductSource::Incentive;
                $payload['incentive_product_id'] = null;
                $payload['is_published'] = false;
            } elseif (($payload['source'] ?? null) === ProductSource::Incentive->value) {
                $payload['incentive_product_id'] = null;
                $payload['is_published'] = false;
            } else {
                unset($payload['source']);
            }

            if (! empty($payload['incentive_product_id']) && (int) $payload['incentive_product_id'] === (int) $product->id) {
                $payload['incentive_product_id'] = null;
            }

            $product->update($payload);
        }

        return new ProductResource($product->fresh(['store', 'category', 'incentiveProduct']));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $product = $this->ownedProduct($request, $id);

        if ($product->isCompanyItem()) {
            $product->forceFill(['is_published' => false])->save();

            return response()->json(['message' => 'Producto de empresa oculto en tu tienda']);
        }

        $product->delete();

        return response()->json(['message' => 'Producto eliminado']);
    }

    public function import(ImportProductsRequest $request, ImportPersonalProductsAction $import): JsonResponse
    {
        $store = $request->user()->store;

        if ($store === null) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            return response()->json(['message' => 'Adjunta un archivo CSV.'], 422);
        }

        $source = ProductSource::tryFrom($request->string('source')->toString());
        if (! in_array($source, [ProductSource::Personal, ProductSource::Incentive], true)) {
            $source = ProductSource::Personal;
        }

        $result = $import->handle($store, $file, $source);
        $imported = collect($result['products'])->each(function (Product $product) use ($store): void {
            $product->setRelation('store', $store);
            $product->load('category');
        });

        return response()->json([
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'data' => ProductResource::collection($imported)->resolve(),
        ], 201);
    }

    public function showPublic(string $storeSlug, string $productSlug): PublicProductResource
    {
        $store = Store::query()
            ->with('user:id,catalog_company_id')
            ->where('slug', $storeSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $product = $store->products()
            ->listedForSale()
            ->with(['category', 'incentiveProduct'])
            ->where('slug', $productSlug)
            ->firstOrFail();
        $product->setRelation('store', $store);

        return new PublicProductResource($product);
    }

    private function ownedProduct(Request $request, int $id): Product
    {
        $ability = $request->isMethod('delete') ? 'delete' : 'update';

        return Owned::find($ability, Product::query()->with('store')->find($id));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertIncentiveCurrency(?Store $store, array $payload, ?Product $product = null): void
    {
        $giftId = $payload['incentive_product_id'] ?? $product?->incentive_product_id;
        if (! $giftId || $store === null) {
            return;
        }

        $gift = Product::query()
            ->where('store_id', $store->id)
            ->where('source', ProductSource::Incentive)
            ->find($giftId);

        if ($gift === null) {
            return;
        }

        $currency = Currencies::normalize(
            $payload['currency'] ?? $product?->currency ?? $store->currency(),
            $store->currency(),
        );

        if (Currencies::normalize($gift->currency, $store->currency()) !== $currency) {
            throw ValidationException::withMessages([
                'incentive_product_id' => ['El incentivo debe estar en la misma moneda que el producto ('.$currency.').'],
            ]);
        }
    }
}
