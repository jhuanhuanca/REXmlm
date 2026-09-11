<?php

declare(strict_types=1);

namespace App\Modules\Store\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAllocation extends Model
{
    protected $fillable = [
        'store_id',
        'product_id',
        'partner_user_id',
        'qty_assigned',
        'qty_sold',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'qty_assigned' => 'integer',
            'qty_sold' => 'integer',
        ];
    }

    public function remaining(): int
    {
        return max(0, $this->qty_assigned - $this->qty_sold);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }
};
