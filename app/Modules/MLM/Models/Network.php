<?php

declare(strict_types=1);

namespace App\Modules\MLM\Models;

use App\Models\User;
use App\Shared\Enums\NetworkStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Network extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_user_id',
        'name',
        'slug',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => NetworkStatus::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'current_network_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
