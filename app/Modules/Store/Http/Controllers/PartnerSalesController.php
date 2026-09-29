<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Store\Actions\PlaceOrderAction;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Http\Requests\PlacePartnerPosOrderRequest;
use App\Modules\Store\Http\Resources\OrderResource;
use App\Modules\Store\Http\Resources\PartnerProductResource;
use App\Modules\Store\Http\Resources\StoreResource;
use App\Modules\Store\Models\InventoryAllocation;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Services\StoreSellerGrantService;
use App\Shared\Enums\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PartnerSalesController extends Controller
{
    public function __construct(
        private readonly StoreSellerGrantService $grants,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $store = $this->grants->storeFor($request->user());

        if ($store === null) {
            return response()->json([
                'message' => 'Tu líder aún no te asignó inventario ni te autorizó a vender.',
            ], 403);
        }

        $store->load('user');
        $products = $this->catalogFor($store, $request->user());

        return response()->json([
            'data' => [
                'store' => (new StoreResource($store))->resolve(),
                'leader_name' => $store->user?->name,
                'products' => PartnerProductResource::collection($products)->resolve(),
            ],
        ]);
    }

    public function orders(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $store = $this->grants->storeFor($request->user());

        if ($store === null) {
            return response()->json([
                'message' => 'Tu líder aún no te asignó inventario ni te autorizó a vender.',
            ], 403);
        }

        $orders = $store->orders()
            ->with('items')
            ->where('partner_user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        $monthStart = now()->startOfMonth();
        $paid = $store->orders()
            ->where('partner_user_id', $request->user()->id)
            ->where('status', OrderStatus::Paid);

        return OrderResource::collection($orders)->additional([
            'sales' => [
                'currency' => $store->currency(),
                'total' => round((float) (clone $paid)->sum('total'), 2),
                'month' => round((float) (clone $paid)->whereBetween('paid_at', [$monthStart, now()])->sum('total'), 2),
                'orders_count' => (int) (clone $paid)->count(),
            ],
        ]);
    }

    public function store(PlacePartnerPosOrderRequest $request, PlaceOrderAction $action): JsonResponse
    {
        $store = $this->grants->storeFor($request->user());

        if ($store === null) {
            return response()->json([
                'message' => 'Tu líder aún no te asignó inventario ni te autorizó a vender.',
            ], 403);
        }

        $payload = $request->validated();
        $ids = collect($payload['items'])->pluck('product_id')->unique()->values();
        $allowed = $store->products()
            ->where('source', ProductSource::Personal)
            ->whereIn('id', $ids)
            ->count();

        if ($allowed !== $ids->count()) {
            throw ValidationException::withMessages([
                'items' => ['Solo puedes vender productos del inventario personal de tu líder.'],
            ]);
        }

        $payload['channel'] = 'pos';
        $payload['mark_paid'] = $request->boolean('mark_paid', true);

        $order = $action->handle($store, $payload, $request->user());

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function catalogFor($store, $partner)
    {
        $canWarehouse = $this->grants->allows($partner, $store);
        $lots = InventoryAllocation::query()
            ->where('store_id', $store->id)
            ->where('partner_user_id', $partner->id)
            ->with(['product.category'])
            ->get()
            ->keyBy('product_id');

        $catalog = collect();

        foreach ($lots as $lot) {
            $product = $lot->product;
            if ($product === null || $product->source !== ProductSource::Personal || ! $product->is_active) {
                continue;
            }

            $hand = $lot->remaining();
            $warehouse = $canWarehouse && $product->tracksInventory() ? (int) $product->stock : 0;
            $sellable = $product->isDropship() ? max($hand, 1) : $hand + $warehouse;

            if ($sellable <= 0) {
                continue;
            }

            $product->setRelation('store', $store);
            $product->setAttribute('partner_stock', $sellable);
            $product->setAttribute('assigned_remaining', $hand);
            $product->setAttribute('from_assignment', $hand > 0);
            $catalog->put($product->id, $product);
        }

        if ($canWarehouse) {
            $store->products()
                ->where('source', ProductSource::Personal)
                ->where('is_active', true)
                ->with('category')
                ->latest()
                ->get()
                ->each(function (Product $product) use ($store, $catalog) {
                    if ($catalog->has($product->id)) {
                        return;
                    }
                    if (! $product->isDropship() && (int) $product->stock <= 0) {
                        return;
                    }
                    $product->setRelation('store', $store);
                    $product->setAttribute('partner_stock', $product->isDropship() ? max(1, (int) $product->stock) : (int) $product->stock);
                    $product->setAttribute('assigned_remaining', 0);
                    $product->setAttribute('from_assignment', false);
                    $catalog->put($product->id, $product);
                });
        }

        return $catalog->values();
    }
}
