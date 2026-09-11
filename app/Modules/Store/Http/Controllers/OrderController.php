<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Store\Actions\PlaceOrderAction;
use App\Modules\Store\Actions\RegisterPaymentVoucherAction;
use App\Modules\Store\Http\Requests\PlaceOrderRequest;
use App\Modules\Store\Http\Requests\PlacePosOrderRequest;
use App\Modules\Store\Http\Resources\OrderResource;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Store;
use App\Shared\Auth\Owned;
use App\Shared\Enums\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $store = $request->user()->store;

        if ($store === null) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        $source = $request->string('source')->toString();
        $orders = $store->orders()->with(['items', 'partner:id,name,email']);

        if ($source === 'personal') {
            $orders->whereNull('partner_user_id');
        } elseif ($source === 'team') {
            $orders->whereNotNull('partner_user_id');
        }

        if ($request->filled('partner_user_id')) {
            $orders->where('partner_user_id', $request->integer('partner_user_id'));
        }

        $monthStart = now()->startOfMonth();
        $now = now();
        $salesRows = $store->orders()
            ->where('status', OrderStatus::Paid)
            ->selectRaw('
                UPPER(currency) as currency,
                COALESCE(SUM(CASE WHEN partner_user_id IS NULL THEN total ELSE 0 END), 0) as personal_total,
                COALESCE(SUM(CASE WHEN partner_user_id IS NOT NULL THEN total ELSE 0 END), 0) as team_total,
                COALESCE(SUM(total), 0) as total,
                COALESCE(SUM(CASE WHEN partner_user_id IS NULL AND paid_at BETWEEN ? AND ? THEN total ELSE 0 END), 0) as personal_month,
                COALESCE(SUM(CASE WHEN partner_user_id IS NOT NULL AND paid_at BETWEEN ? AND ? THEN total ELSE 0 END), 0) as team_month,
                COALESCE(SUM(CASE WHEN partner_user_id IS NULL THEN 1 ELSE 0 END), 0) as personal_orders,
                COALESCE(SUM(CASE WHEN partner_user_id IS NOT NULL THEN 1 ELSE 0 END), 0) as team_orders
            ', [$monthStart, $now, $monthStart, $now])
            ->groupByRaw('UPPER(currency)')
            ->get();

        $byCurrency = $salesRows->map(fn ($row) => $this->salesBucket($row))->values()->all();
        $storeCurrency = $store->currency();
        $primary = collect($byCurrency)->firstWhere('currency', $storeCurrency)
            ?? $this->emptySalesBucket($storeCurrency);

        return OrderResource::collection($orders->latest()->paginate(20))
            ->additional([
                'sales' => array_merge($primary, ['by_currency' => $byCurrency]),
            ]);
    }

    public function store(PlaceOrderRequest $request, string $slug, PlaceOrderAction $action, RegisterPaymentVoucherAction $voucher): JsonResponse
    {
        $store = Store::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $payload = $request->safe()->except(['voucher']);
        $order = $action->handle($store, $payload, $request->user('sanctum'));

        if ($request->hasFile('voucher')) {
            $order = $voucher->handle($store, $order, $request->file('voucher'));
        }

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    public function storePos(PlacePosOrderRequest $request, PlaceOrderAction $action): JsonResponse
    {
        $store = $request->user()->store;

        if ($store === null) {
            return response()->json(['message' => 'No tienes tienda'], 404);
        }

        $payload = $request->validated();
        $payload['channel'] = 'pos';
        $payload['mark_paid'] = $request->boolean('mark_paid', true);

        $order = $action->handle($store, $payload, $request->user());

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    public function markPaid(Request $request, int $id): OrderResource
    {
        $order = Owned::find('update', Order::query()->with('store')->find($id));

        if ($order->status !== OrderStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => ['Solo se pueden marcar como pagadas las órdenes pendientes.'],
            ]);
        }

        $order->forceFill([
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ])->save();

        return new OrderResource($order->fresh(['items', 'partner:id,name,email']));
    }

    public function voucher(Request $request, int $id): StreamedResponse
    {
        $order = Owned::find('view', Order::query()->with('store')->find($id));

        $path = $order->privateVoucherPath();

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            abort(404, 'Comprobante no disponible.');
        }

        return Storage::disk('local')->response($path, 'comprobante-'.$order->id, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array{
     *     currency: string,
     *     personal_total: float,
     *     team_total: float,
     *     total: float,
     *     personal_month: float,
     *     team_month: float,
     *     personal_orders: int,
     *     team_orders: int
     * }
     */
    private function salesBucket(object $row): array
    {
        return [
            'currency' => strtoupper((string) ($row->currency ?: 'USD')),
            'personal_total' => round((float) ($row->personal_total ?? 0), 2),
            'team_total' => round((float) ($row->team_total ?? 0), 2),
            'total' => round((float) ($row->total ?? 0), 2),
            'personal_month' => round((float) ($row->personal_month ?? 0), 2),
            'team_month' => round((float) ($row->team_month ?? 0), 2),
            'personal_orders' => (int) ($row->personal_orders ?? 0),
            'team_orders' => (int) ($row->team_orders ?? 0),
        ];
    }

    /**
     * @return array{
     *     currency: string,
     *     personal_total: float,
     *     team_total: float,
     *     total: float,
     *     personal_month: float,
     *     team_month: float,
     *     personal_orders: int,
     *     team_orders: int
     * }
     */
    private function emptySalesBucket(string $currency): array
    {
        return [
            'currency' => $currency,
            'personal_total' => 0.0,
            'team_total' => 0.0,
            'total' => 0.0,
            'personal_month' => 0.0,
            'team_month' => 0.0,
            'personal_orders' => 0,
            'team_orders' => 0,
        ];
    }
}
