<?php

declare(strict_types=1);

namespace App\Modules\Organization\Canonical;

use App\Models\User;
use App\Modules\Organization\Models\OrganizationCatalogItem;
use App\Modules\Organization\Models\OrganizationCompanyCommission;
use App\Modules\Organization\Models\OrganizationConnection;
use App\Modules\Organization\Models\OrganizationCustomer;
use App\Modules\Organization\Models\OrganizationMember;
use App\Modules\Organization\Models\OrganizationMemberRank;
use App\Modules\Organization\Models\OrganizationOrder;
use App\Modules\Organization\Models\OrganizationOrderItem;
use App\Modules\Organization\Models\OrganizationRankDefinition;
use App\Modules\Organization\Models\OrganizationSponsor;
use App\Modules\Organization\Models\OrganizationSync;
use App\Modules\Organization\Models\OrganizationVolume;
use App\Shared\Enums\ConnectionScope;
use Carbon\Carbon;

class PersistCanonicalData
{
    public function __construct(
        private readonly FieldAliasMap $aliases = new FieldAliasMap,
        private readonly JsonRecordExtractor $json = new JsonRecordExtractor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    public function handle(OrganizationConnection $connection, OrganizationSync $sync, array $payload): array
    {
        $kind = (string) ($payload['kind'] ?? '');
        $fieldMap = $this->fieldMap($connection);
        $periodFallback = $this->fallbackPeriod($connection, $sync);
        $counts = [
            'members' => 0,
            'customers' => 0,
            'sales' => 0,
            'volumes' => 0,
            'commissions' => 0,
            'ranks' => 0,
            'products' => 0,
        ];

        if (in_array($kind, ['spreadsheet', 'json', 'records'], true)) {
            OrganizationCompanyCommission::query()->where('connection_id', $connection->id)->delete();
        }

        if ($kind === 'catalog') {
            $counts['ranks'] = $this->persistRankDefinitions($connection, $payload['ranks'] ?? []);
            $counts['products'] = $this->persistCatalogItems($connection, $payload['products'] ?? []);

            return $counts;
        }

        $rows = $this->rowsFromPayload($payload);
        $pendingSponsors = [];

        foreach ($rows as $raw) {
            $row = $this->aliases->normalizeKeys($raw);
            if ($row === []) {
                continue;
            }

            $member = $this->upsertMember($connection, $row, $fieldMap, $pendingSponsors);
            if ($member) {
                $counts['members']++;
                $this->upsertRank($connection, $member, $row, $fieldMap, $periodFallback);
                if ($this->upsertVolume($connection, $member, $row, $fieldMap, $periodFallback)) {
                    $counts['volumes']++;
                }
            }

            $customer = $this->upsertCustomer($connection, $member, $row, $fieldMap);
            if ($customer) {
                $counts['customers']++;
            }

            if ($this->upsertOrder($connection, $member, $customer, $row, $fieldMap, $periodFallback)) {
                $counts['sales']++;
            }

            if ($this->upsertBonus($connection, $member, $row, $fieldMap, $periodFallback)) {
                $counts['commissions']++;
            }
        }

        $this->resolveGenealogy($connection->organization_id, $pendingSponsors);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function rowsFromPayload(array $payload): array
    {
        $kind = (string) ($payload['kind'] ?? '');

        if ($kind === 'spreadsheet') {
            $headers = array_values($payload['headers'] ?? []);
            $rows = [];
            foreach ($payload['rows'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rows[] = $this->aliases->associate($headers, array_values($row));
            }

            return $rows;
        }

        if ($kind === 'json') {
            return $this->json->collections($payload['body'] ?? []);
        }

        if ($kind === 'records' && is_array($payload['rows'] ?? null)) {
            return array_values(array_filter($payload['rows'], 'is_array'));
        }

        return [];
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>  $fieldMap
     * @param  list<array{member_id: int, sponsor_code: string}>  $pendingSponsors
     */
    private function upsertMember(
        OrganizationConnection $connection,
        array $row,
        array $fieldMap,
        array &$pendingSponsors,
    ): ?OrganizationMember {
        $code = $this->aliases->pick($row, 'member_code', $fieldMap);
        $email = $this->aliases->pick($row, 'email', $fieldMap);
        $name = $this->aliases->pick($row, 'name', $fieldMap);
        $sponsor = $this->aliases->pick($row, 'sponsor_code', $fieldMap);

        if ($code === null && $email === null && ($name === null || $sponsor === null)) {
            return null;
        }

        $email = $email !== null ? mb_strtolower($email) : null;
        $incomingScope = $connection->scope?->value ?? ConnectionScope::Organization->value;

        $member = null;
        if ($code !== null) {
            $member = OrganizationMember::query()
                ->where('organization_id', $connection->organization_id)
                ->where('external_code', $code)
                ->first();
        }
        if (! $member && $email) {
            $member = OrganizationMember::query()
                ->where('organization_id', $connection->organization_id)
                ->where('email', $email)
                ->first();
        }

        $userId = $email
            ? User::query()
                ->where('organization_id', $connection->organization_id)
                ->whereRaw('lower(email) = ?', [$email])
                ->value('id')
            : null;

        $rankName = $this->aliases->pick($row, 'rank', $fieldMap);
        $rankCode = $this->aliases->pick($row, 'rank_code', $fieldMap);
        $values = array_filter([
            'connection_id' => $connection->id,
            'network_id' => $connection->network_id,
            'user_id' => $userId,
            'external_code' => $code,
            'email' => $email,
            'name' => $name,
            'phone' => $this->aliases->pick($row, 'phone', $fieldMap),
            'status' => $this->aliases->pick($row, 'status', $fieldMap),
            'sponsor_code' => $sponsor,
            'rank_code' => $rankCode,
            'rank_name' => $rankName,
            'raw' => $row,
        ], fn ($value) => $value !== null && $value !== '');

        if (! $member) {
            $member = OrganizationMember::query()->create([
                'organization_id' => $connection->organization_id,
                'scope' => $incomingScope,
                ...$values,
            ]);
        } else {
            $protectOfficial = $member->scope === ConnectionScope::Organization->value
                && $incomingScope === ConnectionScope::Network->value;
            if ($protectOfficial) {
                foreach (['name', 'email', 'phone', 'status', 'rank_code', 'rank_name', 'sponsor_code', 'user_id'] as $key) {
                    if (filled($member->{$key})) {
                        unset($values[$key]);
                    }
                }
            } else {
                $values['scope'] = $incomingScope === ConnectionScope::Organization->value
                    ? ConnectionScope::Organization->value
                    : $member->scope;
            }
            if ($values !== []) {
                $member->forceFill($values)->save();
            }
        }

        if ($sponsor !== null && $sponsor !== $member->external_code) {
            $pendingSponsors[] = [
                'member_id' => $member->id,
                'sponsor_code' => $sponsor,
            ];
        }

        return $member;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>  $fieldMap
     */
    private function upsertVolume(
        OrganizationConnection $connection,
        OrganizationMember $member,
        array $row,
        array $fieldMap,
        string $periodFallback,
    ): bool {
        $pv = $this->aliases->number($row, 'personal_volume', $fieldMap);
        $gv = $this->aliases->number($row, 'group_volume', $fieldMap);
        $sv = $this->aliases->number($row, 'sales_volume', $fieldMap);
        $cv = $this->aliases->number($row, 'commission_volume', $fieldMap);

        if ($pv === null && $gv === null && $sv === null && $cv === null) {
            return false;
        }

        $scope = $connection->scope?->value ?? ConnectionScope::Organization->value;
        $period = $this->period($row, $fieldMap, $periodFallback);
        $priority = $scope === ConnectionScope::Organization->value ? 20 : 10;

        OrganizationVolume::query()->updateOrCreate(
            [
                'member_id' => $member->id,
                'period' => $period,
                'scope' => $scope,
            ],
            [
                'organization_id' => $connection->organization_id,
                'connection_id' => $connection->id,
                'network_id' => $connection->network_id,
                'unit' => $this->aliases->guessUnit($row, $fieldMap),
                'personal_volume' => $pv,
                'group_volume' => $gv,
                'sales_volume' => $sv,
                'commission_volume' => $cv,
                'qualifying' => $this->aliases->isTruthy($this->aliases->pick($row, 'qualifying', $fieldMap)),
                'priority' => $priority,
                'raw' => $row,
            ],
        );

        return true;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>  $fieldMap
     */
    private function upsertRank(
        OrganizationConnection $connection,
        OrganizationMember $member,
        array $row,
        array $fieldMap,
        string $periodFallback,
    ): void {
        $name = $this->aliases->pick($row, 'rank', $fieldMap);
        $code = $this->aliases->pick($row, 'rank_code', $fieldMap);
        if ($name === null && $code === null) {
            return;
        }

        $period = $this->period($row, $fieldMap, $periodFallback);

        OrganizationMemberRank::query()->updateOrCreate(
            [
                'member_id' => $member->id,
                'period' => $period,
                'scope' => $connection->scope?->value ?? ConnectionScope::Organization->value,
            ],
            [
                'organization_id' => $connection->organization_id,
                'rank_code' => $code,
                'rank_name' => $name,
                'qualified' => $this->aliases->isTruthy($this->aliases->pick($row, 'qualifying', $fieldMap)),
            ],
        );
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>  $fieldMap
     */
    private function upsertCustomer(
        OrganizationConnection $connection,
        ?OrganizationMember $member,
        array $row,
        array $fieldMap,
    ): ?OrganizationCustomer {
        $name = $this->aliases->pick($row, 'customer_name', $fieldMap);
        $email = $this->aliases->pick($row, 'customer_email', $fieldMap);
        $code = $this->aliases->pick($row, 'customer_code', $fieldMap);
        if ($name === null && $email === null && $code === null) {
            return null;
        }

        $email = $email !== null ? mb_strtolower($email) : null;

        $query = OrganizationCustomer::query()->where('organization_id', $connection->organization_id);
        if ($code) {
            $query->where('external_code', $code);
        } elseif ($email) {
            $query->where('email', $email);
        } else {
            $query->where('name', $name)->where('member_id', $member?->id);
        }

        $customer = $query->first();
        $values = array_filter([
            'member_id' => $member?->id,
            'network_id' => $connection->network_id,
            'external_code' => $code,
            'email' => $email,
            'name' => $name,
            'raw' => $row,
        ], fn ($value) => $value !== null && $value !== '');

        if (! $customer) {
            return OrganizationCustomer::query()->create([
                'organization_id' => $connection->organization_id,
                ...$values,
            ]);
        }

        $customer->forceFill($values)->save();

        return $customer;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>  $fieldMap
     */
    private function upsertOrder(
        OrganizationConnection $connection,
        ?OrganizationMember $member,
        ?OrganizationCustomer $customer,
        array $row,
        array $fieldMap,
        string $periodFallback,
    ): bool {
        $orderCode = $this->aliases->pick($row, 'order_code', $fieldMap);
        $sku = $this->aliases->pick($row, 'sku', $fieldMap);
        $total = $this->aliases->number($row, 'total', $fieldMap);
        $hasVolume = $this->aliases->number($row, 'personal_volume', $fieldMap) !== null
            || $this->aliases->number($row, 'group_volume', $fieldMap) !== null;

        if ($orderCode === null && $sku === null) {
            return false;
        }
        if ($orderCode === null && $hasVolume && $total === null) {
            return false;
        }

        $period = $this->period($row, $fieldMap, $periodFallback);
        $orderedAt = $this->date($this->aliases->pick($row, 'ordered_at', $fieldMap));
        $identity = $orderCode ?: ($sku.'@'.($member?->id ?? '0').'@'.$period);

        $order = OrganizationOrder::query()->updateOrCreate(
            [
                'organization_id' => $connection->organization_id,
                'external_code' => $identity,
            ],
            [
                'connection_id' => $connection->id,
                'network_id' => $connection->network_id,
                'member_id' => $member?->id,
                'customer_id' => $customer?->id,
                'scope' => $connection->scope?->value ?? ConnectionScope::Organization->value,
                'period' => $period,
                'ordered_at' => $orderedAt,
                'total' => $total,
                'currency' => $this->aliases->pick($row, 'currency', $fieldMap),
                'qualifying' => $this->aliases->isTruthy($this->aliases->pick($row, 'qualifying', $fieldMap)),
                'raw' => $row,
            ],
        );

        $itemPv = $this->aliases->number($row, 'item_pv', $fieldMap)
            ?? $this->catalogPv($connection->organization_id, $sku);
        $qty = $this->aliases->number($row, 'quantity', $fieldMap);

        OrganizationOrderItem::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'sku' => $sku ?: $identity,
            ],
            [
                'organization_id' => $connection->organization_id,
                'name' => $this->aliases->pick($row, 'sku', $fieldMap),
                'quantity' => $qty,
                'pv' => $itemPv,
                'amount' => $total,
                'raw' => $row,
            ],
        );

        return true;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>  $fieldMap
     */
    private function upsertBonus(
        OrganizationConnection $connection,
        ?OrganizationMember $member,
        array $row,
        array $fieldMap,
        string $periodFallback,
    ): bool {
        $amount = $this->aliases->number($row, 'commission_amount', $fieldMap);
        $kind = $this->aliases->pick($row, 'commission_kind', $fieldMap);
        if ($amount === null) {
            return false;
        }
        if ($kind === null && $this->aliases->pick($row, 'order_code', $fieldMap) !== null) {
            return false;
        }
        if ($kind === null && $this->aliases->number($row, 'personal_volume', $fieldMap) !== null) {
            return false;
        }

        OrganizationCompanyCommission::query()->create([
            'organization_id' => $connection->organization_id,
            'connection_id' => $connection->id,
            'network_id' => $connection->network_id,
            'member_id' => $member?->id,
            'scope' => $connection->scope?->value ?? ConnectionScope::Organization->value,
            'period' => $this->period($row, $fieldMap, $periodFallback),
            'kind' => $kind ?: 'bonus',
            'amount' => $amount,
            'currency' => $this->aliases->pick($row, 'currency', $fieldMap),
            'source_type' => 'company',
            'raw' => $row,
        ]);

        return true;
    }

    /**
     * @param  list<array{member_id: int, sponsor_code: string}>  $pending
     */
    private function resolveGenealogy(int $organizationId, array $pending): void
    {
        foreach ($pending as $link) {
            $sponsor = OrganizationMember::query()
                ->where('organization_id', $organizationId)
                ->where('external_code', $link['sponsor_code'])
                ->first();
            if (! $sponsor || $sponsor->id === $link['member_id']) {
                continue;
            }

            OrganizationMember::query()->whereKey($link['member_id'])->update([
                'sponsor_member_id' => $sponsor->id,
            ]);

            OrganizationSponsor::query()->updateOrCreate(
                ['member_id' => $link['member_id']],
                [
                    'organization_id' => $organizationId,
                    'sponsor_member_id' => $sponsor->id,
                ],
            );
        }
    }

    /**
     * @param  list<mixed>  $ranks
     */
    private function persistRankDefinitions(OrganizationConnection $connection, array $ranks): int
    {
        $count = 0;
        foreach ($ranks as $index => $rank) {
            if (! is_array($rank)) {
                continue;
            }
            $name = (string) ($rank['name'] ?? $rank['rank_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $catalogId = isset($rank['id']) ? (int) $rank['id'] : null;
            OrganizationRankDefinition::query()->updateOrCreate(
                [
                    'organization_id' => $connection->organization_id,
                    'catalog_rank_id' => $catalogId,
                    'name' => $name,
                ],
                [
                    'code' => (string) ($rank['code'] ?? $rank['slug'] ?? $catalogId ?? ''),
                    'sort_order' => (int) ($rank['sort_order'] ?? $index),
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<mixed>  $products
     */
    private function persistCatalogItems(OrganizationConnection $connection, array $products): int
    {
        $count = 0;
        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }
            $id = isset($product['id']) ? (int) $product['id'] : null;
            $sku = (string) ($product['sku'] ?? $product['code'] ?? $id ?? '');
            OrganizationCatalogItem::query()->updateOrCreate(
                [
                    'organization_id' => $connection->organization_id,
                    'catalog_product_id' => $id,
                ],
                [
                    'sku' => $sku !== '' ? $sku : null,
                    'name' => (string) ($product['name'] ?? ''),
                    'pv' => $product['pv'] ?? $product['compensation_pv'] ?? null,
                    'raw' => $product,
                ],
            );
            $count++;
        }

        return $count;
    }

    private function catalogPv(int $organizationId, ?string $sku): ?float
    {
        if (! $sku) {
            return null;
        }

        $pv = OrganizationCatalogItem::query()
            ->where('organization_id', $organizationId)
            ->where('sku', $sku)
            ->value('pv');

        return $pv !== null ? (float) $pv : null;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>  $fieldMap
     */
    private function period(array $row, array $fieldMap, string $fallback): string
    {
        $raw = $this->aliases->pick($row, 'period', $fieldMap)
            ?? $this->aliases->pick($row, 'ordered_at', $fieldMap);
        if ($raw === null) {
            return $fallback;
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $raw, $match)) {
            return $match[1].'-'.$match[2];
        }
        if (preg_match('/^(\d{2})[\/\-](\d{4})$/', $raw, $match)) {
            return $match[2].'-'.$match[1];
        }
        try {
            return Carbon::parse($raw)->format('Y-m');
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function date(?string $raw): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function fallbackPeriod(OrganizationConnection $connection, OrganizationSync $sync): string
    {
        $configured = (string) (($connection->config ?? [])['period'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $configured)) {
            return $configured;
        }

        return ($sync->started_at ?? now())->format('Y-m');
    }

    /**
     * @return array<string, string>
     */
    private function fieldMap(OrganizationConnection $connection): array
    {
        $map = ($connection->config ?? [])['field_map'] ?? [];

        return is_array($map) ? array_map('strval', $map) : [];
    }
}
