<?php

declare(strict_types=1);

namespace App\Modules\Store\Models;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Shared\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'store_id',
        'network_id',
        'partner_user_id',
        'channel',
        'delivery',
        'payment_method',
        'payment_voucher_url',
        'payment_voucher_document_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'status',
        'total',
        'shipping_fee',
        'shipping_country',
        'shipping_department',
        'shipping_area',
        'shipping_address',
        'shipping_zone',
        'shipping_eta_days',
        'currency',
        'payment_reference',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function hasPaymentVoucher(): bool
    {
        return filled($this->payment_voucher_url);
    }

    public function privateVoucherPath(): ?string
    {
        $value = trim((string) $this->payment_voucher_url);

        if ($value === '' || str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return null;
        }

        return $value;
    }
}
