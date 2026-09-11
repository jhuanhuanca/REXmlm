<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Store\Actions\AssignInventoryAction;
use App\Modules\Store\Actions\ReturnInventoryAction;
use App\Modules\Store\Http\Requests\AssignInventoryRequest;
use App\Modules\Store\Http\Requests\ReturnInventoryRequest;
use App\Modules\Store\Http\Resources\InventoryAllocationResource;
use App\Modules\Store\Models\InventoryAllocation;
use App\Shared\Auth\Owned;
use App\Shared\Enums\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryAllocationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $store = $request->user()->store;

        if ($store === null) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        $query = $store->allocations()->with(['product', 'partner:id,name,email'])->latest('updated_at');

        if ($request->filled('partner_user_id')) {
            $query->where('partner_user_id', $request->integer('partner_user_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        $allocations = $query->get();

        $sales = $store->orders()
            ->where('status', OrderStatus::Paid)
            ->whereNotNull('partner_user_id')
            ->selectRaw('partner_user_id, UPPER(currency) as currency, COUNT(*) as orders_count, COALESCE(SUM(total), 0) as sales_total')
            ->groupByRaw('partner_user_id, UPPER(currency)')
            ->get()
            ->groupBy('partner_user_id');

        $saleUsers = User::query()
            ->whereIn('id', $sales->keys()->all())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $team = $allocations
            ->groupBy('partner_user_id')
            ->map(function ($rows, $partnerId) use ($sales) {
                $partner = $rows->first()?->partner;
                $saleRows = $sales->get((int) $partnerId) ?? collect();

                return [
                    'partner_user_id' => (int) $partnerId,
                    'name' => $partner?->name,
                    'email' => $partner?->email,
                    'qty_assigned' => (int) $rows->sum('qty_assigned'),
                    'qty_sold' => (int) $rows->sum('qty_sold'),
                    'qty_remaining' => (int) $rows->sum(fn ($row) => $row->remaining()),
                    'orders_count' => (int) $saleRows->sum('orders_count'),
                    'sales_total' => round((float) $saleRows->sum('sales_total'), 2),
                    'sales_by_currency' => $saleRows->map(fn ($row) => [
                        'currency' => strtoupper((string) $row->currency),
                        'total' => round((float) $row->sales_total, 2),
                        'orders_count' => (int) $row->orders_count,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        foreach ($sales as $partnerId => $saleRows) {
            if ($allocations->contains('partner_user_id', (int) $partnerId)) {
                continue;
            }

            $user = $saleUsers->get((int) $partnerId);
            $team[] = [
                'partner_user_id' => (int) $partnerId,
                'name' => $user?->name,
                'email' => $user?->email,
                'qty_assigned' => 0,
                'qty_sold' => 0,
                'qty_remaining' => 0,
                'orders_count' => (int) $saleRows->sum('orders_count'),
                'sales_total' => round((float) $saleRows->sum('sales_total'), 2),
                'sales_by_currency' => $saleRows->map(fn ($row) => [
                    'currency' => strtoupper((string) $row->currency),
                    'total' => round((float) $row->sales_total, 2),
                    'orders_count' => (int) $row->orders_count,
                ])->values()->all(),
            ];
        }

        return InventoryAllocationResource::collection($allocations)
            ->additional(['team' => $team]);
    }

    public function store(AssignInventoryRequest $request, AssignInventoryAction $action): JsonResponse
    {
        $store = $request->user()->store;
        $allocation = $action->handle($store, $request->validated());

        return (new InventoryAllocationResource($allocation))
            ->response()
            ->setStatusCode(201);
    }

    public function returnStock(
        ReturnInventoryRequest $request,
        int $id,
        ReturnInventoryAction $action,
    ): JsonResponse {
        $store = $request->user()->store;
        $allocation = Owned::find(
            'update',
            InventoryAllocation::query()->with('store')->find($id),
        );

        $next = $action->handle($store, $allocation, $request->validated());

        if ($next === null) {
            return response()->json(['message' => 'Unidades devueltas a tu bodega.']);
        }

        return (new InventoryAllocationResource($next))->response();
    }
}
