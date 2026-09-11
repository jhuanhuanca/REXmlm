<?php

declare(strict_types=1);

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'name',
        'quantity',
        'unit_price',
        'unit_cost',
        'incentive_cost',
        'line_total',
        'line_cost',
        'line_profit',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'incentive_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'line_cost' => 'decimal:2',
            'line_profit' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
