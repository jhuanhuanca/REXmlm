<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\MLM\Models\Invitation;
use App\Services\Catalog\CatalogClient;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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

        if (filled($invitationToken)) {
            $this->assertInvitationEmail((string) $invitationToken, $identity['email']);
        } else {
            $input = $this->assertLeaderAffiliation($input);
        }

        $user = $this->registerUser->handle([
            'name' => $identity['name'],
            'email' => $identity['email'],
            'password' => Str::password(32),
            'invitation_token' => $invitationToken,
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

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function assertLeaderAffiliation(array $input): array
    {
        $country = strtoupper((string) ($input['country'] ?? ''));
        $companyId = (int) ($input['catalog_company_id'] ?? 0);
        $rankId = (int) ($input['catalog_rank_id'] ?? 0);

        if ($country === '' || $companyId < 1 || $rankId < 1) {
            throw ValidationException::withMessages([
                'id_token' => ['Para crear una cuenta de líder con Google, elige país, empresa y rango, o usa un enlace de invitación.'],
            ]);
        }

        try {
            $affiliation = app(CatalogClient::class)->findCompanyRank($companyId, $rankId);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'catalog_company_id' => ['No se pudo cargar el catálogo de empresas. Inténtalo de nuevo.'],
            ]);
        }

        if ($affiliation === null) {
            throw ValidationException::withMessages([
                'catalog_rank_id' => ['Elige una empresa y un rango válido de esa empresa.'],
            ]);
        }

        $input['country'] = $country;
        $input['catalog_company_id'] = $affiliation['company_id'];
        $input['catalog_company_name'] = $affiliation['company_name'];
        $input['catalog_rank_id'] = $affiliation['rank_id'];
        $input['catalog_rank_name'] = $affiliation['rank_name'];

        return $input;
    }
}
