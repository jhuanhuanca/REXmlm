<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Landing\Models\LandingPage;
use App\Modules\MLM\Actions\AcceptInvitationAction;
use App\Modules\MLM\Models\Network;
use App\Modules\Store\Models\Store;
use App\Shared\Enums\NetworkStatus;
use App\Shared\Enums\UserStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterUserAction
{
    public function __construct(
        private readonly AcceptInvitationAction $acceptInvitation,
        private readonly \App\Modules\Organization\Actions\SyncUserOrganization $syncOrganization,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, invitation_token?: string|null, country?: string|null, catalog_company_id?: int|null, catalog_company_name?: string|null, catalog_rank_id?: int|null, catalog_rank_name?: string|null, google_id?: string|null, email_verified_at?: mixed}  $data
     */
    public function handle(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $invitationToken = $data['invitation_token'] ?? null;

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'status' => UserStatus::Active,
                'google_id' => $data['google_id'] ?? null,
                'email_verified_at' => $data['email_verified_at'] ?? null,
                'country' => filled($invitationToken) ? null : strtoupper((string) ($data['country'] ?? '')),
                'catalog_company_id' => filled($invitationToken) ? null : ($data['catalog_company_id'] ?? null),
                'catalog_company_name' => filled($invitationToken) ? null : ($data['catalog_company_name'] ?? null),
                'catalog_rank_id' => filled($invitationToken) ? null : ($data['catalog_rank_id'] ?? null),
                'catalog_rank_name' => filled($invitationToken) ? null : ($data['catalog_rank_name'] ?? null),
            ]);

            if (filled($invitationToken)) {
                $this->acceptInvitation->handle($user, (string) $invitationToken);
                $user->assignRole(config('rexmlm.roles.partner'));
            } else {
                $user->assignRole(config('rexmlm.roles.leader'));
                $this->provisionNetwork($user);
            }

            $user->refresh();
            $this->provisionStoreAndLanding($user);

            if ($user->hasRole(config('rexmlm.roles.leader'))) {
                $this->syncOrganization->handle($user);
                app(\App\Modules\Organization\Actions\EnsurePrimaryCompanyMembership::class)->handle($user);
            }

            return $user->load(['roles', 'store', 'landingPage', 'currentNetwork', 'sponsor.store', 'sponsor.landingPage', 'organization', 'companyMemberships']);
        });
    }

    private function provisionNetwork(User $user): void
    {
        $network = Network::create([
            'owner_user_id' => $user->id,
            'name' => $user->name,
            'slug' => $this->uniqueSlug($user->name, $user->id),
            'status' => NetworkStatus::Pending,
        ]);

        $user->forceFill([
            'current_network_id' => $network->id,
        ])->save();
    }

    private function provisionStoreAndLanding(User $user): void
    {
        $slug = $this->uniqueSlug($user->name, $user->id);

        Store::create([
            'user_id' => $user->id,
            'network_id' => $user->current_network_id,
            'name' => $user->name.' Store',
            'slug' => $slug,
            'theme' => 'default',
            'is_active' => true,
        ]);

        LandingPage::create([
            'user_id' => $user->id,
            'network_id' => $user->current_network_id,
            'title' => $user->name,
            'slug' => $slug,
            'template' => 'default',
            'content' => [
                'hero' => [
                    'title' => $user->name,
                    'subtitle' => '',
                    'cta_label' => '',
                    'cta_href' => '',
                ],
                'blocks' => [],
            ],
            'is_published' => false,
        ]);
    }

    private function uniqueSlug(string $name, int $userId): string
    {
        $base = Str::slug($name) ?: 'user';

        return $base.'-'.$userId;
    }
}
