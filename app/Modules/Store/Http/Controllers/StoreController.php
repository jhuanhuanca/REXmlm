<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Http\Requests\StorePaymentAssetRequest;
use App\Modules\Store\Http\Requests\UpdateStoreRequest;
use App\Modules\Store\Http\Resources\ProductResource;
use App\Modules\Store\Http\Resources\PublicStoreResource;
use App\Modules\Store\Http\Resources\StoreResource;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Services\ShippingQuoteService;
use App\Shared\Auth\Owned;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoreController extends Controller
{
    public function show(string $slug): PublicStoreResource
    {
        $store = Store::query()
            ->with([
                'user:id,name,country,catalog_company_id,catalog_company_name,catalog_rank_name',
                'user.landingPage',
            ])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $store->load(['products' => fn ($q) => $q->listedForSale()->with(['category', 'incentiveProduct'])]);

        return new PublicStoreResource($store);
    }

    public function myStore(Request $request): StoreResource|JsonResponse
    {
        $store = $request->user()->store()->with(['user.landingPage', 'products'])->first();

        if ($store === null) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        Owned::find('view', $store);

        return new StoreResource($store);
    }

    public function update(UpdateStoreRequest $request): StoreResource
    {
        $store = $request->user()->store;
        Owned::find('update', $store);
        $payload = $request->validated();
        if (isset($payload['settings']) && is_array($payload['settings'])) {
            $payload['settings'] = $this->mergeStoreSettings($store->settings ?? [], $payload['settings']);
        }
        $store->update($payload);

        return new StoreResource($store->fresh('products'));
    }

    public function applyTargetMargin(Request $request): JsonResponse
    {
        $store = $request->user()->store;

        if ($store === null) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        Owned::find('update', $store);

        $store->refresh();
        $margin = $store->targetMarginPercent();

        if ($margin === null || $margin <= 0) {
            return response()->json([
                'message' => 'Define un margen objetivo en ajustes antes de aplicarlo.',
            ], 422);
        }

        $updated = 0;
        $products = $store->products()
            ->where('source', ProductSource::Personal)
            ->with('incentiveProduct')
            ->get();

        foreach ($products as $product) {
            $price = $store->priceForTargetMargin($product->unitSaleCost());
            if ($price === null) {
                continue;
            }

            $product->update(['price' => $price]);
            $updated++;
        }

        $fresh = $store->products()
            ->where('source', ProductSource::Personal)
            ->with(['category', 'incentiveProduct', 'allocations'])
            ->get();
        $fresh->each(fn (Product $product) => $product->setRelation('store', $store));

        return response()->json([
            'message' => $updated === 1
                ? 'Se actualizó el precio de 1 producto.'
                : "Se actualizaron los precios de {$updated} productos.",
            'updated' => $updated,
            'margin' => $margin,
            'data' => ProductResource::collection($fresh)->resolve(),
        ]);
    }

    public function storePaymentAsset(StorePaymentAssetRequest $request): JsonResponse|StoreResource
    {
        $store = $request->user()->store;

        if ($store === null) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        Owned::find('update', $store);

        $kind = $request->string('kind')->toString();
        $file = $request->file('file');
        if ($file === null) {
            return response()->json(['message' => 'Falta el archivo.'], 422);
        }

        $directory = 'store-payments/'.$store->id;
        $extension = strtolower((string) ($file->guessExtension() ?: 'jpg'));

        foreach (['jpg', 'jpeg', 'png', 'webp'] as $old) {
            Storage::disk('public')->delete($directory.'/'.$kind.'.'.$old);
        }

        $path = $file->storeAs($directory, $kind.'.'.$extension, 'public');
        $url = Storage::disk('public')->url($path);
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
        }

        $settings = is_array($store->settings) ? $store->settings : [];
        $payments = is_array($settings['payments'] ?? null) ? $settings['payments'] : [];
        if ($kind === 'qr') {
            $payments['qr_image'] = $url;
            $payments['qr_enabled'] = true;
        } else {
            $payments['binance_image'] = $url;
            $payments['binance_enabled'] = true;
        }
        $settings['payments'] = $payments;
        $store->update(['settings' => $settings]);

        return new StoreResource($store->fresh('products'));
    }

    public function shippingQuote(Request $request, string $slug, ShippingQuoteService $shipping): JsonResponse
    {
        $data = $request->validate([
            'country' => ['nullable', 'string', 'size:2'],
            'department' => ['nullable', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
        ]);

        $store = Store::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $quote = $shipping->quote($store, [
            'country' => isset($data['country']) ? strtoupper($data['country']) : '',
            'department' => $data['department'] ?? '',
            'area' => $data['area'] ?? '',
        ], max(0, (float) ($data['subtotal'] ?? 0)));

        return response()->json(['data' => $quote]);
    }

    public function myShippingQuote(Request $request, ShippingQuoteService $shipping): JsonResponse
    {
        $store = $request->user()?->store;

        if ($store === null) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        $data = $request->validate([
            'country' => ['nullable', 'string', 'size:2'],
            'department' => ['nullable', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
        ]);

        $quote = $shipping->quote($store, [
            'country' => isset($data['country']) ? strtoupper($data['country']) : '',
            'department' => $data['department'] ?? '',
            'area' => $data['area'] ?? '',
        ], max(0, (float) ($data['subtotal'] ?? 0)));

        return response()->json(['data' => $quote]);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeStoreSettings(array $current, array $incoming): array
    {
        $merged = array_replace_recursive($current, $incoming);

        if (array_key_exists('dropshipping', $incoming) && is_array($incoming['dropshipping'])) {
            $currentDrop = is_array($current['dropshipping'] ?? null) ? $current['dropshipping'] : [];
            $merged['dropshipping'] = array_replace($currentDrop, $incoming['dropshipping']);

            if (array_key_exists('zones', $incoming['dropshipping'])) {
                $merged['dropshipping']['zones'] = $incoming['dropshipping']['zones'];
            }
        }

        if (array_key_exists('payments', $incoming) && is_array($incoming['payments'])) {
            $currentPay = is_array($current['payments'] ?? null) ? $current['payments'] : [];
            $merged['payments'] = array_replace($currentPay, $incoming['payments']);
        }

        return $merged;
    }
}
