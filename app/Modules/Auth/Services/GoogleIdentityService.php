<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GoogleIdentityService
{
    /**
     * @return array{sub: string, email: string, name: string, email_verified: bool}
     */
    public function userFromIdToken(string $idToken): array
    {
        $clientId = (string) config('services.google.client_id');

        if ($clientId === '') {
            throw ValidationException::withMessages([
                'id_token' => ['El acceso con Google no está configurado.'],
            ]);
        }

        $response = Http::timeout(8)
            ->acceptJson()
            ->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'id_token' => ['No se pudo verificar la cuenta de Google.'],
            ]);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'id_token' => ['La respuesta de Google no es válida.'],
            ]);
        }

        $aud = (string) ($payload['aud'] ?? '');
        $sub = (string) ($payload['sub'] ?? '');
        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $verified = $payload['email_verified'] ?? false;

        if ($aud !== $clientId || $sub === '' || $email === '') {
            throw ValidationException::withMessages([
                'id_token' => ['La cuenta de Google no corresponde a esta aplicación.'],
            ]);
        }

        $isVerified = $verified === true || $verified === 'true' || $verified === 1 || $verified === '1';

        if (! $isVerified) {
            throw ValidationException::withMessages([
                'id_token' => ['Google no ha verificado este correo. Usa otro método de acceso.'],
            ]);
        }

        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            $name = strstr($email, '@', true) ?: $email;
        }

        return [
            'sub' => $sub,
            'email' => $email,
            'name' => $name,
            'email_verified' => true,
        ];
    }
}
