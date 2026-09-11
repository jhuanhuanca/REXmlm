<?php

declare(strict_types=1);

namespace App\Modules\Store\Notifications;

use App\Modules\Store\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InventoryAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Product $product,
        public string $kind,
        public string $alertKey,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $days = $this->product->daysUntilExpiry();
        $stock = (int) $this->product->stock;
        $expires = $this->product->expires_at?->toDateString();

        return [
            'alert_key' => $this->alertKey,
            'kind' => $this->kind,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'stock' => $stock,
            'expires_at' => $expires,
            'days_until_expiry' => $days,
            'title' => match ($this->kind) {
                'low_stock' => 'Stock bajo',
                'expired' => 'Producto vencido',
                default => 'Producto por vencer',
            },
            'body' => match ($this->kind) {
                'low_stock' => $this->product->name.' tiene '.$stock.' unidad'.($stock === 1 ? '' : 'es').'. Reponer antes de que se agote.',
                'expired' => $this->product->name.' ya venció'.($expires ? ' ('.$expires.')' : '').'.',
                default => $this->product->name.' vence en '.$days.' día'.($days === 1 ? '' : 's').($expires ? ' ('.$expires.')' : '').'.',
            },
        ];
    }
}
