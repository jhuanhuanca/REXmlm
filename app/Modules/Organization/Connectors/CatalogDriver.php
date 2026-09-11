<?php

declare(strict_types=1);

namespace App\Modules\Organization\Connectors;

use App\Services\Catalog\CatalogClient;
use App\Modules\Organization\Models\OrganizationConnection;
use App\Modules\Organization\Models\OrganizationSync;
use RuntimeException;

class CatalogDriver implements ConnectionDriverContract
{
    public function key(): string
    {
        return 'catalog';
    }

    public function sync(OrganizationConnection $connection, OrganizationSync $sync): SyncOutcome
    {
        $organization = $connection->organization;
        $companyId = (int) ($organization?->catalog_company_id ?? 0);

        if ($companyId < 1) {
            return new SyncOutcome(false, 'error', [], [
                ['level' => 'error', 'message' => 'La organización no tiene empresa de catálogo vinculada.'],
            ], 'Vincula catalog_company_id (serv_producmlm) antes de sincronizar.');
        }

        $client = app(CatalogClient::class);
        $logs = [];
        $records = [
            'catalog' => 0,
            'products' => 0,
            'ranks' => 0,
            'compensation_plans' => 0,
        ];

        $products = [];
        $ranksPayload = [];

        try {
            $branding = $client->brandingFor($companyId);
            if ($branding) {
                $records['catalog'] = 1;
                $logs[] = ['level' => 'info', 'message' => 'Empresa de catálogo: '.($branding['name'] ?: '#'.$companyId)];
            }

            $products = $client->productsForCompany($companyId);
            $records['products'] = count($products);
            $logs[] = ['level' => 'info', 'message' => 'Productos activos: '.$records['products']];

            foreach ($client->getRegistrationOptions() as $company) {
                if (! is_array($company) || (int) ($company['id'] ?? 0) !== $companyId) {
                    continue;
                }
                $ranksPayload = is_array($company['ranks'] ?? null) ? $company['ranks'] : [];
            }
            $records['ranks'] = count($ranksPayload);
            $logs[] = ['level' => 'info', 'message' => 'Rangos: '.$records['ranks']];

            try {
                $plans = $client->getCompensationPlans($companyId);
                $items = is_array($plans['data'] ?? null) ? $plans['data'] : (is_array($plans) ? $plans : []);
                $records['compensation_plans'] = count($items);
            } catch (RuntimeException) {
                $logs[] = ['level' => 'warning', 'message' => 'No se pudieron leer los planes de compensación.'];
            }
        } catch (RuntimeException $exception) {
            return new SyncOutcome(false, 'error', $records, [
                ['level' => 'error', 'message' => $exception->getMessage()],
            ], 'No se pudo hablar con serv_producmlm.');
        }

        $ok = $records['catalog'] > 0 || $records['products'] > 0;

        return new SyncOutcome(
            $ok,
            $ok ? 'connected' : 'error',
            $records,
            $logs,
            $ok ? 'Catálogo sincronizado desde serv_producmlm.' : 'El catálogo no devolvió datos.',
            [
                'kind' => 'catalog',
                'ranks' => $ranksPayload,
                'products' => $products,
            ],
        );
    }
}
