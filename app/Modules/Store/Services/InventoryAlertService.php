<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Notifications\InventoryAlertNotification;

class InventoryAlertService
{
    public function notifyLowStock(Product $product): void
    {
        $product->loadMissing('store.user');
        $user = $product->store?->user;

        if (! $user instanceof User) {
            return;
        }

        $key = 'low_stock:'.$product->id;

        if (! $product->isLowStock()) {
            $this->markAlertRead($user, $key);

            return;
        }

        $this->sendOnce($user, $product, 'low_stock', $key);
    }

    public function notifyExpiry(Product $product): void
    {
        $product->loadMissing('store.user');
        $user = $product->store?->user;

        if (! $user instanceof User || $product->expires_at === null) {
            return;
        }

        $days = $product->daysUntilExpiry();

        if ($days === null) {
            return;
        }

        $expires = $product->expires_at->toDateString();

        if ($days < 0) {
            $this->sendOnce($user, $product, 'expired', 'expired:'.$product->id.':'.$expires);

            return;
        }

        $expiringKey = 'expiring:'.$product->id.':'.$expires;

        if ($product->isExpiringSoon()) {
            $this->sendOnce($user, $product, 'expiring', $expiringKey);

            return;
        }

        $this->markAlertRead($user, $expiringKey);
    }

    public function scanStore(Store $store): void
    {
        $store->loadMissing('user');
        $store->products()->each(function (Product $product) use ($store): void {
            $product->setRelation('store', $store);
            $this->notifyLowStock($product);
            $this->notifyExpiry($product);
        });
    }

    public function scanAllStores(): int
    {
        $scanned = 0;

        Store::query()->with('user')->each(function (Store $store) use (&$scanned): void {
            $this->scanStore($store);
            $scanned++;
        });

        return $scanned;
    }

    private function sendOnce(User $user, Product $product, string $kind, string $key): void
    {
        if ($this->hasOpenAlert($user, $key)) {
            return;
        }

        $user->notify(new InventoryAlertNotification($product, $kind, $key));
    }

    private function hasOpenAlert(User $user, string $key): bool
    {
        return $user->unreadNotifications()
            ->get()
            ->contains(fn ($notification): bool => ($notification->data['alert_key'] ?? null) === $key);
    }

    private function markAlertRead(User $user, string $key): void
    {
        $user->unreadNotifications()
            ->get()
            ->filter(fn ($notification): bool => ($notification->data['alert_key'] ?? null) === $key)
            ->each(fn ($notification) => $notification->markAsRead());
    }
}
