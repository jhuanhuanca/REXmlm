<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Landing\Models\LandingPage;
use App\Modules\MLM\Models\Invitation;
use App\Modules\MLM\Models\Network;
use App\Modules\MLM\Models\Referral;
use App\Modules\Commission\Models\WithdrawalRequest;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\UserCompanyMembership;
use App\Modules\Store\Models\Store;
use App\Modules\Subscription\Models\Subscription;
use App\Shared\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'google_id',
        'password',
        'status',
        'sponsor_user_id',
        'current_network_id',
        'country',
        'catalog_company_id',
        'catalog_company_name',
        'catalog_rank_id',
        'catalog_rank_name',
        'organization_id',
        'active_catalog_company_id',
        'failed_login_attempts',
        'locked_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function referralRecord(): HasOne
    {
        return $this->hasOne(Referral::class, 'referred_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sponsor_user_id');
    }

    public function currentNetwork(): BelongsTo
    {
        return $this->belongsTo(Network::class, 'current_network_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function companyMemberships(): HasMany
    {
        return $this->hasMany(UserCompanyMembership::class);
    }

    public function workingCatalogCompanyId(): ?int
    {
        $active = $this->active_catalog_company_id ? (int) $this->active_catalog_company_id : null;

        if ($active && $this->hasCompanyMembership($active)) {
            return $active;
        }

        $primary = $this->relationLoaded('companyMemberships')
            ? $this->companyMemberships->firstWhere('is_primary', true)
            : $this->companyMemberships()->where('is_primary', true)->first();

        if ($primary) {
            return (int) $primary->catalog_company_id;
        }

        return $this->catalog_company_id ? (int) $this->catalog_company_id : null;
    }

    public function workingOrganization(): ?Organization
    {
        $companyId = $this->workingCatalogCompanyId();

        if ($companyId) {
            $match = Organization::query()->where('catalog_company_id', $companyId)->first();

            if ($match) {
                return $match;
            }
        }

        if ($this->relationLoaded('organization')) {
            return $this->organization;
        }

        return $this->organization()->first();
    }

    public function workingOrganizationId(): ?int
    {
        return $this->workingOrganization()?->id;
    }

    public function hasCompanyMembership(int $catalogCompanyId): bool
    {
        if ($this->relationLoaded('companyMemberships')) {
            return $this->companyMemberships->contains(
                fn (UserCompanyMembership $row) => (int) $row->catalog_company_id === $catalogCompanyId,
            );
        }

        return $this->companyMemberships()->where('catalog_company_id', $catalogCompanyId)->exists();
    }

    public function isWorkingPrimaryCompany(): bool
    {
        $working = $this->workingCatalogCompanyId();
        $primary = $this->catalog_company_id ? (int) $this->catalog_company_id : null;

        return $working !== null && $working === $primary;
    }

    public function membershipForCompany(int $catalogCompanyId): ?UserCompanyMembership
    {
        if ($this->relationLoaded('companyMemberships')) {
            return $this->companyMemberships->firstWhere('catalog_company_id', $catalogCompanyId);
        }

        return $this->companyMemberships()->where('catalog_company_id', $catalogCompanyId)->first();
    }

    public function ownedNetwork(): HasOne
    {
        return $this->hasOne(Network::class, 'owner_user_id');
    }

    public function store(): HasOne
    {
        return $this->hasOne(Store::class);
    }

    public function landingPage(): HasOne
    {
        return $this->hasOne(LandingPage::class);
    }

    public function invitationsSent(): HasMany
    {
        return $this->hasMany(Invitation::class, 'leader_id');
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    public function activeCashierSubscription(): ?Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = $this->subscription('default');

        return $subscription?->valid() ? $subscription : null;
    }

    public function activeSubscription(): ?Subscription
    {
        return $this->activeCashierSubscription();
    }

    public function hasPaidPlatformAccess(): bool
    {
        if ((bool) config('billing.offline')) {
            return true;
        }

        if ($this->hasRole('admin')) {
            return true;
        }

        $subscription = $this->subscription('default');

        if ($subscription === null) {
            return false;
        }

        $statusOk = in_array((string) $subscription->stripe_status, ['active', 'trialing'], true);
        $notEnded = $subscription->ends_at === null || $subscription->ends_at->isFuture();

        return $statusOk && $notEnded;
    }

    public function hasActivePlatformAccess(): bool
    {
        if ($this->hasRole('admin')) {
            return true;
        }

        if ($this->hasRole('leader')) {
            return $this->hasPaidPlatformAccess();
        }

        return true;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    public function mustEnrollTwoFactor(): bool
    {
        return $this->hasRole('admin') && ! $this->hasTwoFactorEnabled();
    }

    public function createAuthToken(string $name = 'auth_token', array $abilities = ['*'], ?int $minutes = null): string
    {
        $ttl = $minutes ?? (int) config('sanctum.expiration');
        $expiresAt = $ttl > 0 ? now()->addMinutes($ttl) : null;

        $this->tokens()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        return $this->createToken($name, $abilities, $expiresAt)->plainTextToken;
    }
}
