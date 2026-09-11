<?php

declare(strict_types=1);

namespace App\Modules\MLM\Models;

use App\Models\User;
use App\Shared\Enums\ReferralStatus;
use App\Shared\Enums\TeamCrmStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referral extends Model
{
    protected $fillable = [
        'referrer_id',
        'referred_id',
        'network_id',
        'level',
        'status',
        'crm_stage',
        'notes',
        'follow_up_at',
        'last_contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'status' => ReferralStatus::class,
            'crm_stage' => TeamCrmStage::class,
            'follow_up_at' => 'datetime',
            'last_contacted_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TeamActivity::class);
    }
}
