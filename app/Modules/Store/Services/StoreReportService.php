<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Shared\Exports\WorkbookExport;
use Illuminate\Http\Response;

class StoreReportService
{
    public function inventory(User $user, string $format): Response
    {
        $store = $this->storeOf($user);
        $products = $store->products()
            ->with(['category', 'incentiveProduct', 'allocations.partner:id,name,email'])
            ->orderBy('source')
            ->orderBy('name')
            ->get();
        $products->each(fn (Product $product) => $product->setRelation('store', $store));

        $productRows = $products->map(function (Product $product) {
            $source = $product->source?->value ?? 'personal';

            return [
                $product->id,
                $this->sourceLabel($source),
                $product->name,
                $product->category?->name ?? '',
                $product->slug,
                round((float) $product->price, 2),
                round((float) $product->purchase_cost, 2),
                round((float) $product->incentiveUnitCost(), 2),
                round((float) $product->unitSaleCost(), 2),
                round((float) $product->unitProfit(), 2),
                $product->marginPercent() ?? '',
                $product->incentiveProduct?->name ?? '',
                $product->incentiveProduct ? max(1, (int) $product->incentive_qty) : '',
                (int) $product->stock,
                (int) $product->allocations->sum(fn ($row) => $row->remaining()),
                $product->fulfillment?->value ?? 'stock',
                $product->is_active ? 'Sí' : 'No',
                $product->is_published ? 'Sí' : 'No',
                $product->expires_at?->toDateString() ?? '',
                $product->isExpired() ? 'Vencido' : ($product->isLowStock() ? 'Stock bajo' : ($product->isExpiringSoon() ? 'Por vencer' : 'OK')),
                $product->currency,
                $product->dropship_sku ?? '',
                $product->dropship_url ?? '',
            ];
        })->all();

        $allocationRows = [];
        foreach ($products as $product) {
            foreach ($product->allocations as $row) {
                $allocationRows[] = [
                    $product->name,
                    $row->partner?->name ?? '',
                    $row->partner?->email ?? '',
                    (int) $row->qty_assigned,
                    (int) $row->qty_sold,
                    $row->remaining(),
                    $row->notes ?? '',
                    optional($row->created_at)?->toDateTimeString() ?? '',
                ];
            }
        }

        $export = new WorkbookExport(
            'Inventario · '.$store->name,
            'Todos los productos de la tienda (personal, incentivos y empresa)',
        );
        $export->addSheet('Productos', [
            'ID',
            'Origen',
            'Nombre',
            'Categoría',
            'Slug',
            'Precio',
            'Costo compra',
            'Costo incentivo',
            'Costo venta',
            'Utilidad unidad',
            'Margen %',
            'Incentivo',
            'Cant. incentivo',
            'Stock bodega',
            'En equipo',
            'Entrega',
            'Activo',
            'Publicado',
            'Vencimiento',
            'Alerta',
            'Moneda',
            'SKU proveedor',
            'URL proveedor',
        ], $productRows);
        $export->addSheet('Asignaciones', [
            'Producto',
            'Miembro',
            'Correo',
            'Asignado',
            'Vendido',
            'Pendiente',
            'Notas',
            'Fecha',
        ], $allocationRows);

        return $export->download('inventario_'.$store->slug.'_'.now()->format('Y-m-d'), $format);
    }

    public function sales(User $user, string $format): Response
    {
        $store = $this->storeOf($user);
        $orders = $store->orders()
            ->with(['items', 'partner:id,name,email'])
            ->latest()
            ->get();

        $orderRows = [];
        $itemRows = [];
        $paid = 0.0;
        $pending = 0.0;

        foreach ($orders as $order) {
            $status = $this->orderStatus($order);
            $total = round((float) $order->total, 2);
            if ($status === 'Pagada') {
                $paid += $total;
            } elseif ($status === 'Pendiente') {
                $pending += $total;
            }

            $orderRows[] = [
                $order->id,
                $status,
                $order->channel === 'pos' ? 'Venta directa' : 'Tienda pública',
                $order->delivery === 'pickup' ? 'Retiro' : 'Envío',
                $this->paymentLabel($order->payment_method),
                $order->customer_name,
                $order->customer_email,
                $order->customer_phone ?? '',
                $order->partner?->name ?? 'Personal',
                $order->partner?->email ?? '',
                round((float) $order->shipping_fee, 2),
                $order->shipping_country ?? '',
                $order->shipping_department ?? '',
                $order->shipping_area ?? '',
                $order->shipping_address ?? '',
                $total,
                $order->currency,
                optional($order->paid_at)?->toDateTimeString() ?? '',
                optional($order->created_at)?->toDateTimeString() ?? '',
            ];

            foreach ($order->items as $item) {
                $itemRows[] = [
                    $order->id,
                    $status,
                    $order->customer_name,
                    $order->partner?->name ?? 'Personal',
                    $item->name,
                    (int) $item->quantity,
                    round((float) $item->unit_price, 2),
                    round((float) $item->unit_cost, 2),
                    round((float) $item->incentive_cost, 2),
                    round((float) $item->line_total, 2),
                    round((float) $item->line_cost, 2),
                    round((float) $item->line_profit, 2),
                    $order->currency,
                    optional($order->created_at)?->toDateString() ?? '',
                ];
            }
        }

        $export = new WorkbookExport(
            'Ventas · '.$store->name,
            sprintf('Pagadas %s · pendientes %s · %d órdenes', number_format($paid, 2, '.', ''), number_format($pending, 2, '.', ''), $orders->count()),
        );
        $export->addSheet('Pedidos', [
            'ID',
            'Estado',
            'Canal',
            'Entrega',
            'Pago',
            'Cliente',
            'Correo cliente',
            'Teléfono',
            'Socio',
            'Correo socio',
            'Envío',
            'País',
            'Departamento',
            'Zona',
            'Dirección',
            'Total',
            'Moneda',
            'Pagado',
            'Creado',
        ], $orderRows);
        $export->addSheet('Lineas', [
            'Pedido',
            'Estado',
            'Cliente',
            'Socio',
            'Producto',
            'Cantidad',
            'Precio unit.',
            'Costo unit.',
            'Costo incentivo',
            'Total línea',
            'Costo línea',
            'Utilidad línea',
            'Moneda',
            'Fecha',
        ], $itemRows);

        return $export->download('ventas_'.$store->slug.'_'.now()->format('Y-m-d'), $format);
    }

    private function storeOf(User $user): Store
    {
        $store = $user->store;
        abort_if($store === null, 404, 'No tienes tienda');

        return $store;
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            ProductSource::Company->value => 'Empresa',
            ProductSource::Incentive->value => 'Incentivo',
            default => 'Personal',
        };
    }

    private function orderStatus(Order $order): string
    {
        $value = is_object($order->status) ? $order->status->value : (string) $order->status;

        return match ($value) {
            'paid' => 'Pagada',
            'cancelled' => 'Cancelada',
            'refunded' => 'Reembolsada',
            default => 'Pendiente',
        };
    }

    private function paymentLabel(?string $method): string
    {
        return match ($method) {
            'qr' => 'QR',
            'qr_binance' => 'Binance',
            'deposit' => 'Depósito',
            'transfer' => 'Transferencia',
            default => $method ?: '',
        };
    }
}
