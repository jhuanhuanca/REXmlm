<?php

declare(strict_types=1);

namespace App\Modules\MLM\Models;

use App\Models\User;
use App\Shared\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invitation extends Model
{
    protected $fillable = [
        'leader_id',
        'network_id',
        'catalog_company_id',
        'catalog_company_name',
        'email',
        'token_hash',
        'status',
        'expires_at',
        'accepted_at',
        'accepted_user_id',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'catalog_company_id' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function generatePlainToken(): string
    {
        return Str::random(64);
    }

    public function isUsable(): bool
    {
        return $this->status === InvitationStatus::Pending
            && $this->expires_at->isFuture();
    }

    public function scopeByPlainToken($query, string $plainToken)
    {
        return $query->where('token_hash', self::hashToken($plainToken));
    }
}
