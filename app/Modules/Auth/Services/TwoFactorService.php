<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    private readonly Google2FA $google2fa;

    public function __construct(?Google2FA $google2fa = null)
    {
        $this->google2fa = $google2fa ?? new Google2FA();
    }

    /**
     * @return array{secret: string, otpauth_url: string}
     */
    public function beginSetup(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        $issuer = (string) config('rexmlm.two_factor.issuer', 'REXmlm');

        return [
            'secret' => $secret,
            'otpauth_url' => $this->google2fa->getQRCodeUrl($issuer, $user->email, $secret),
        ];
    }

    /**
     * @return list<string>
     */
    public function confirmSetup(User $user, string $code): array
    {
        $secret = (string) $user->two_factor_secret;

        if ($secret === '' || ! $this->validTotp($secret, $code)) {
            return [];
        }

        $plainCodes = $this->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn (string $code) => Hash::make($code), $plainCodes),
        ])->save();

        return $plainCodes;
    }

    public function verifyLogin(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        $secret = (string) $user->two_factor_secret;

        if ($this->validTotp($secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    public function validTotp(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $window = (int) config('rexmlm.two_factor.window', 1);

        return (bool) $this->google2fa->verifyKey($secret, $code, $window);
    }

    public function currentOtp(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(4).'-'.Str::random(4)))
            ->all();
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $stored = $user->two_factor_recovery_codes;

        if (! is_array($stored) || $stored === []) {
            return false;
        }

        $normalized = strtoupper(trim($code));
        $remaining = [];
        $matched = false;

        foreach ($stored as $hash) {
            if (! $matched && is_string($hash) && Hash::check($normalized, $hash)) {
                $matched = true;
                continue;
            }
            $remaining[] = $hash;
        }

        if (! $matched) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();

        return true;
    }
}
