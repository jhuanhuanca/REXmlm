<?php

declare(strict_types=1);

namespace App\Modules\Commission\Services;

use App\Models\User;
use App\Modules\Commission\Models\Commission;
use App\Modules\Commission\Models\WithdrawalRequest;
use App\Shared\Enums\CommissionStatus;
use App\Shared\Enums\WithdrawalStatus;
use App\Shared\Support\Currencies;

class WithdrawalBalanceService
{
    /**
     * @return array{
     *     pending: float,
     *     reserved: float,
     *     available: float,
     *     minimum: float,
     *     currency: string,
     *     can_request: bool
     * }
     */
    public function forUser(User $user, bool $lock = false): array
    {
        $pendingQuery = Commission::query()
            ->where('referrer_id', $user->id)
            ->where('currency', Currencies::COMMISSION)
            ->where('status', CommissionStatus::Pending);

        $reservedQuery = WithdrawalRequest::query()
            ->where('user_id', $user->id)
            ->where('status', WithdrawalStatus::Requested);

        if ($lock) {
            $pendingQuery->lockForUpdate();
            $reservedQuery->lockForUpdate();
        }

        $pending = round((float) $pendingQuery->sum('amount'), 2);
        $reserved = round((float) $reservedQuery->sum('amount'), 2);
        $available = round(max(0, $pending - $reserved), 2);
        $minimum = round((float) config('rexmlm.withdrawal_minimum', 20), 2);

        return [
            'pending' => $pending,
            'reserved' => $reserved,
            'available' => $available,
            'minimum' => $minimum,
            'currency' => Currencies::COMMISSION,
            'can_request' => $available >= $minimum,
        ];
    }

    public function whatsappFor(User $user): ?string
    {
        $user->loadMissing(['store', 'landingPage']);

        $candidates = [
            data_get($user->store?->settings, 'whatsapp'),
            data_get($user->landingPage?->content, 'whatsapp'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
