<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\MLM\Models\Invitation;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompleteGoogleAuthAction
{
    public function __construct(
        private readonly RegisterUserAction $registerUser,
    ) {}

    /**
     * @param  array{sub: string, email: string, name: string, email_verified: bool}  $identity
     * @param  array<string, mixed>  $input
     * @return array{user: User, created: bool}
     */
    public function handle(array $identity, array $input): array
    {
        $existing = User::query()->where('google_id', $identity['sub'])->first()
            ?? User::query()->where('email', $identity['email'])->first();

        if ($existing) {
            $this->linkGoogle($existing, $identity['sub']);

            return ['user' => $existing->fresh([
                'roles',
                'store',
                'landingPage',
                'currentNetwork',
                'sponsor.store',
                'sponsor.landingPage',
                'organization',
                'companyMemberships',
            ]), 'created' => false];
        }

        $invitationToken = $input['invitation_token'] ?? null;
        $sponsorId = isset($input['sponsor_id']) ? (int) $input['sponsor_id'] : 0;

        if (filled($invitationToken)) {
            $this->assertInvitationEmail((string) $invitationToken, $identity['email']);
        } elseif ($sponsorId > 0) {
            $this->assertOpenSponsor($sponsorId);
        } else {
            $input = $this->assertLeaderAffiliation($input);
        }

        $user = $this->registerUser->handle([
            'name' => $identity['name'],
            'email' => $identity['email'],
            'password' => Str::password(32),
            'invitation_token' => $invitationToken,
            'sponsor_id' => $sponsorId > 0 ? $sponsorId : null,
            'country' => $input['country'] ?? null,
            'catalog_company_id' => $input['catalog_company_id'] ?? null,
            'catalog_company_name' => $input['catalog_company_name'] ?? null,
            'catalog_rank_id' => $input['catalog_rank_id'] ?? null,
            'catalog_rank_name' => $input['catalog_rank_name'] ?? null,
            'google_id' => $identity['sub'],
            'email_verified_at' => now(),
        ]);

        return ['user' => $user, 'created' => true];
    }

    private function linkGoogle(User $user, string $googleId): void
    {
        if (filled($user->google_id) && $user->google_id !== $googleId) {
            throw ValidationException::withMessages([
                'id_token' => ['Este correo ya está asociado a otra cuenta de Google.'],
            ]);
        }

        $user->forceFill([
            'google_id' => $googleId,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();
    }

    private function assertInvitationEmail(string $plainToken, string $email): void
    {
        $invitation = Invitation::query()->byPlainToken($plainToken)->first();

        if ($invitation === null || ! $invitation->isUsable()) {
            throw ValidationException::withMessages([
                'invitation_token' => ['La invitación no es válida o ya expiró.'],
            ]);
        }

        if (strcasecmp($invitation->email, $email) !== 0) {
            throw ValidationException::withMessages([
                'email' => ['La cuenta de Google no coincide con el correo de la invitación.'],
            ]);
        }
    }

    private function assertOpenSponsor(int $sponsorId): void
    {
        $leader = User::query()->find($sponsorId);

        if ($leader === null || ! $leader->hasRole(config('rexmlm.roles.leader')) || ! $leader->current_network_id) {
            throw ValidationException::withMessages([
                'sponsor_id' => ['El enlace de referido no es válido.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function assertLeaderAffiliation(array $input): array
    {
        $country = strtoupper((string) ($input['country'] ?? ''));
        $companyName = trim((string) ($input['catalog_company_name'] ?? ''));
        $rankName = trim((string) ($input['catalog_rank_name'] ?? ''));

        $errors = [];
        if ($country === '') {
            $errors['country'] = ['Selecciona tu país.'];
        }
        if ($companyName === '') {
            $errors['catalog_company_name'] = ['Escribe el nombre de tu empresa.'];
        }
        if ($rankName === '') {
            $errors['catalog_rank_name'] = ['Escribe tu rango.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $input['country'] = $country;
        $input['catalog_company_id'] = null;
        $input['catalog_rank_id'] = null;
        $input['catalog_company_name'] = $companyName;
        $input['catalog_rank_name'] = $rankName;

        return $input;
    }
}
