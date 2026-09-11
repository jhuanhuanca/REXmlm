<?php

declare(strict_types=1);

namespace App\Modules\MLM\Models;

use App\Models\User;
use App\Shared\Enums\TeamActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamActivity extends Model
{
    protected $fillable = [
        'leader_id',
        'referred_id',
        'referral_id',
        'type',
        'body',
        'due_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TeamActivityType::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }
}
