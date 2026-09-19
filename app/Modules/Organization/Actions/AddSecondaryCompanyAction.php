<?php

declare(strict_types=1);

namespace App\Modules\Organization\Actions;

use App\Models\User;
use App\Modules\Organization\Models\UserCompanyMembership;
use App\Modules\Subscription\Services\PaddleCheckoutService;
use App\Modules\Subscription\Services\PaddleClient;
use App\Modules\Subscription\Services\PlanEntitlements;
use App\Services\Catalog\CatalogClient;
use App\Services\Catalog\CompanyCatalogSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddSecondaryCompanyAction
{
    public function __construct(
        private readonly CatalogClient $catalog,
        private readonly SyncUserOrganization $syncOrganization,
        private readonly CompanyCatalogSync $catalogSync,
        private readonly EnsurePrimaryCompanyMembership $ensurePrimary,
        private readonly PaddleClient $paddle,
        private readonly PaddleCheckoutService $paddleCheckout,
    ) {}

    /**
     * @return array{checkout_url?: string, membership?: UserCompanyMembership}
     */
    public function handle(
        User $user,
        int $catalogCompanyId,
        ?int $catalogRankId = null,
        ?string $catalogRankName = null,
        bool $alreadyPaid = false,
        string $paddleSubscriptionId = '',
    ): array {
        $brand = $this->assertCanAdd($user, $catalogCompanyId);
        $price = (float) config('rexmlm.secondary_company.price', 15);
        $currency = strtoupper((string) config('rexmlm.secondary_company.currency', 'USD'));
        $included = PlanEntitlements::extraCompaniesIncluded($user);
        $used = $this->billableExtraCount($user);
        $includedSlot = $used < $included;

        if (! $alreadyPaid && ! $includedSlot && $this->mustCharge($price)) {
            $custom = [
                'kind' => 'secondary_company',
                'catalog_company_id' => (string) $catalogCompanyId,
                'catalog_rank_id' => $catalogRankId ? (string) $catalogRankId : null,
                'catalog_rank_name' => $catalogRankName,
            ];
            $paddlePriceId = trim((string) config('rexmlm.secondary_company.paddle_price_id', ''));
            $url = $paddlePriceId !== ''
                ? $this->paddleCheckout->recurringPriceCheckoutUrl($user, $paddlePriceId, $currency, $custom)
                : $this->paddleCheckout->oneTimeCheckoutUrl(
                    $user,
                    'Empresa extra: '.$brand['name'],
                    $price,
                    $currency,
                    $custom,
                );

            return ['checkout_url' => $url];
        }

        $chargedPrice = ($includedSlot || ($alreadyPaid && $used < $included)) ? 0.0 : $price;

        $membership = DB::transaction(function () use ($user, $catalogCompanyId, $catalogRankId, $catalogRankName, $brand, $chargedPrice, $currency, $paddleSubscriptionId) {
            $membership = UserCompanyMembership::query()->create([
                'user_id' => $user->id,
                'catalog_company_id' => $catalogCompanyId,
                'catalog_company_name' => $brand['name'],
                'catalog_rank_id' => $catalogRankId,
                'catalog_rank_name' => $catalogRankName,
                'is_primary' => false,
                'extra_price' => $chargedPrice,
                'currency' => $currency,
                'billing_status' => 'active',
                'paddle_subscription_id' => $paddleSubscriptionId !== '' ? $paddleSubscriptionId : null,
            ]);

            $this->syncOrganization->handle($user, $catalogCompanyId, (string) $brand['name']);

            if ($user->store) {
                $this->catalogSync->syncStore($user->store);
            }

            $user->forceFill(['active_catalog_company_id' => $catalogCompanyId])->save();

            return $membership->fresh();
        });

        return ['membership' => $membership];
    }

    /**
     * @return array{id: int, name: string, logo: ?string, color_palette: ?array<string, mixed>}
     */
    private function assertCanAdd(User $user, int $catalogCompanyId): array
    {
        if (! $user->hasRole(config('rexmlm.roles.leader'))) {
            throw ValidationException::withMessages([
                'catalog_company_id' => ['Solo un líder puede añadir empresas secundarias.'],
            ]);
        }

        $this->ensurePrimary->handle($user);
        $user->refresh();

        if (! $user->catalog_company_id) {
            throw ValidationException::withMessages([
                'catalog_company_id' => ['Primero elige tu empresa principal al registrarte.'],
            ]);
        }

        if ((int) $user->catalog_company_id === $catalogCompanyId || $user->hasCompanyMembership($catalogCompanyId)) {
            throw ValidationException::withMessages([
                'catalog_company_id' => ['Esa empresa ya está en tu cuenta.'],
            ]);
        }

        $brand = $this->catalog->brandingFor($catalogCompanyId);

        if ($brand === null || ($brand['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                'catalog_company_id' => ['No encontramos esa empresa en el catálogo.'],
            ]);
        }

        return $brand;
    }

    private function mustCharge(float $price): bool
    {
        return $price > 0
            && $this->paddle->configured()
            && ! (bool) config('billing.offline');
    }

    private function billableExtraCount(User $user): int
    {
        return $user->companyMemberships()
            ->where('is_primary', false)
            ->where(function ($query) {
                $query->whereNull('billing_status')
                    ->orWhereNotIn('billing_status', ['canceled']);
            })
            ->count();
    }
}
