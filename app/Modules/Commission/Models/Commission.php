<?php

declare(strict_types=1);

namespace App\Modules\Commission\Models;

use App\Models\User;
use App\Modules\MLM\Models\Network;
use App\Modules\Subscription\Models\Subscription;
use App\Shared\Enums\CommissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Commission extends Model
{
    use LogsActivity;

    protected $fillable = [
        'referrer_id',
        'referred_id',
        'network_id',
        'subscription_id',
        'source_type',
        'source_id',
        'amount',
        'percentage',
        'currency',
        'status',
        'paid_at',
        'approved_by',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'percentage' => 'decimal:2',
            'status' => CommissionStatus::class,
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount', 'paid_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }
}
