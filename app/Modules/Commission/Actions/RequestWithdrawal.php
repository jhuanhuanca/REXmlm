<?php

declare(strict_types=1);

namespace App\Modules\Commission\Actions;

use App\Models\User;
use App\Modules\Commission\Models\WithdrawalRequest;
use App\Modules\Commission\Services\WithdrawalBalanceService;
use App\Shared\Enums\WithdrawalStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class RequestWithdrawal
{
    public function __construct(private WithdrawalBalanceService $balance) {}

    /**
     * @param  array{amount?: mixed, collect_all?: mixed, whatsapp?: mixed, password: string}  $data
     */
    public function execute(User $user, array $data): WithdrawalRequest
    {
        if (! Hash::check((string) $data['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['La contraseña del sistema no es correcta.'],
            ]);
        }

        return DB::transaction(function () use ($user, $data) {
            $snapshot = $this->balance->forUser($user, true);
            $minimum = $snapshot['minimum'];

            if ($snapshot['available'] < $minimum) {
                throw ValidationException::withMessages([
                    'amount' => ['Solo puedes solicitar un retiro si tus ganancias disponibles son de al menos '.$this->money($minimum).'.'],
                ]);
            }

            $collectAll = filter_var($data['collect_all'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $amount = $collectAll
                ? $snapshot['available']
                : round((float) ($data['amount'] ?? 0), 2);

            if ($amount < $minimum) {
                throw ValidationException::withMessages([
                    'amount' => ['El monto mínimo de retiro es '.$this->money($minimum).'.'],
                ]);
            }

            if ($amount > $snapshot['available']) {
                throw ValidationException::withMessages([
                    'amount' => ['El monto supera tus ganancias disponibles ('.$this->money($snapshot['available']).').'],
                ]);
            }

            $whatsapp = isset($data['whatsapp']) ? trim((string) $data['whatsapp']) : '';
            if ($whatsapp === '') {
                $whatsapp = $this->balance->whatsappFor($user) ?? '';
            }

            return WithdrawalRequest::query()->create([
                'user_id' => $user->id,
                'amount' => $amount,
                'currency' => $snapshot['currency'],
                'status' => WithdrawalStatus::Requested,
                'collect_all' => $collectAll,
                'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
                'contact_email' => $user->email,
                'password_confirmed_at' => now(),
                'meta' => [
                    'available_at_request' => $snapshot['available'],
                    'pending_at_request' => $snapshot['pending'],
                ],
            ]);
        });
    }

    private function money(float $amount): string
    {
        return '$'.number_format($amount, 2).' USD';
    }
}
