<?php

declare(strict_types=1);

namespace App\Modules\Commission\Models;

use App\Models\User;
use App\Shared\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class WithdrawalRequest extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'amount',
        'currency',
        'status',
        'collect_all',
        'whatsapp',
        'contact_email',
        'notes',
        'password_confirmed_at',
        'processed_at',
        'paid_at',
        'processed_by',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'collect_all' => 'boolean',
            'status' => WithdrawalStatus::class,
            'password_confirmed_at' => 'datetime',
            'processed_at' => 'datetime',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount', 'paid_at', 'processed_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
