<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Services;

use App\Models\User;
use App\Modules\Subscription\Actions\GrantComplimentarySubscriptionAction;
use App\Modules\Subscription\Models\Plan;

class PlanEntitlements
{
    public const STORE = 'store';
    public const TOOLS = 'tools';
    public const LANDING = 'landing';
    public const TEAM = 'team';
    public const PARTNER_SELL = 'partner_sell';
    public const CLOSING = 'closing';

    /**
     * @return array{
     *     store: bool,
     *     tools: bool,
     *     landing: bool,
     *     team: bool,
     *     partner_sell: bool,
     *     closing: bool,
     *     max_partners: int|null,
     *     extra_companies: int,
     *     support: string
     * }
     */
    public static function catalog(string $slug): array
    {
        return match (self::family($slug)) {
            'basico' => [
                'store' => false,
                'tools' => false,
                'landing' => true,
                'team' => true,
                'partner_sell' => false,
                'closing' => false,
                'max_partners' => 50,
                'extra_companies' => 0,
                'support' => 'standard',
            ],
            'intermedio' => [
                'store' => true,
                'tools' => true,
                'landing' => true,
                'team' => true,
                'partner_sell' => true,
                'closing' => true,
                'max_partners' => 500,
                'extra_companies' => 0,
                'support' => 'standard',
            ],
            'premium' => [
                'store' => true,
                'tools' => true,
                'landing' => true,
                'team' => true,
                'partner_sell' => true,
                'closing' => true,
                'max_partners' => null,
                'extra_companies' => 1,
                'support' => 'priority',
            ],
            default => [
                'store' => true,
                'tools' => true,
                'landing' => true,
                'team' => true,
                'partner_sell' => true,
                'closing' => true,
                'max_partners' => null,
                'extra_companies' => 0,
                'support' => 'standard',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function of(?Plan $plan): array
    {
        $base = self::catalog($plan?->slug ?? '');
        $override = is_array($plan?->features) ? $plan->features : [];

        foreach ($base as $key => $value) {
            if (array_key_exists($key, $override)) {
                $base[$key] = $override[$key];
            }
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forUser(?User $user): array
    {
        if ($user === null) {
            return self::catalog('');
        }

        if ($user->hasRole('admin') || (bool) config('billing.offline')) {
            return array_merge(self::catalog('premium'), [
                'max_partners' => null,
                'extra_companies' => 99,
            ]);
        }

        if ($user->hasRole('partner') && ! $user->hasRole('leader')) {
            return array_merge(self::catalog('basico'), [
                'store' => false,
                'tools' => true,
                'landing' => false,
                'team' => true,
                'partner_sell' => false,
                'closing' => false,
                'max_partners' => 0,
                'extra_companies' => 0,
            ]);
        }

        if (! $user->hasPaidPlatformAccess()) {
            $flags = self::of($user->subscription('default')?->plan);
            foreach (['store', 'tools', 'landing', 'team', 'partner_sell', 'closing'] as $key) {
                $flags[$key] = false;
            }

            return $flags;
        }

        return self::of($user->subscription('default')?->plan);
    }

    public static function allows(?User $user, string $feature): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->hasRole('admin') || (bool) config('billing.offline')) {
            return true;
        }

        if ($user->hasRole('partner') && ! $user->hasRole('leader')) {
            return in_array($feature, [self::TOOLS, self::TEAM], true);
        }

        if (! $user->hasPaidPlatformAccess()) {
            return false;
        }

        $flags = self::of($user->activeSubscription()?->plan);

        return (bool) ($flags[$feature] ?? false);
    }

    public static function maxPartners(?User $user): ?int
    {
        if ($user === null || $user->hasRole('admin') || (bool) config('billing.offline')) {
            return null;
        }

        $max = self::of($user->activeSubscription()?->plan)['max_partners'] ?? null;

        return is_numeric($max) ? (int) $max : null;
    }

    public static function extraCompaniesIncluded(?User $user): int
    {
        if ($user === null || $user->hasRole('admin') || (bool) config('billing.offline')) {
            return 99;
        }

        return (int) (self::of($user->activeSubscription()?->plan)['extra_companies'] ?? 0);
    }

    public static function isComplimentary(?User $user): bool
    {
        $id = (string) ($user?->subscription('default')?->stripe_id ?? '');

        return str_starts_with($id, GrantComplimentarySubscriptionAction::ID_PREFIX);
    }

    private static function family(string $slug): string
    {
        $slug = strtolower($slug);

        if (str_contains($slug, 'premium') || str_contains($slug, 'enterprise')) {
            return 'premium';
        }

        if (str_contains($slug, 'intermedio') || str_contains($slug, 'profesional')) {
            return 'intermedio';
        }

        if (str_contains($slug, 'basico') || str_contains($slug, 'básico') || str_contains($slug, 'basic')) {
            return 'basico';
        }

        return $slug;
    }
}
