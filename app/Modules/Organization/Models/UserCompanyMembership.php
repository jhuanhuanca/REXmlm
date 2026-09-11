<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCompanyMembership extends Model
{
    protected $fillable = [
        'user_id',
        'catalog_company_id',
        'catalog_company_name',
        'catalog_rank_id',
        'catalog_rank_name',
        'is_primary',
        'extra_price',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'catalog_company_id' => 'integer',
            'catalog_rank_id' => 'integer',
            'is_primary' => 'boolean',
            'extra_price' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
