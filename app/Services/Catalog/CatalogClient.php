<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Modules\Tools\CompanyToolCatalog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CatalogClient
{
    public function getCompanies(): mixed
    {
        return $this->remember('companies', fn () => $this->get('/companies'));
    }

    public function getCompany(int $id): mixed
    {
        return $this->get("/companies/{$id}");
    }

    /**
     * @return list<string>
     */
    public function enabledToolsForCompany(?int $companyId): array
    {
        if (! $companyId) {
            return CompanyToolCatalog::KEYS;
        }

        try {
            $payload = $this->get("/companies/{$companyId}");
        } catch (RuntimeException) {
            return CompanyToolCatalog::KEYS;
        }

        $company = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (! is_array($company)) {
            return CompanyToolCatalog::KEYS;
        }

        $raw = $company['enabled_tools'] ?? null;

        return CompanyToolCatalog::normalize(is_array($raw) ? $raw : null);
    }

    public function forgetCompanyCaches(?int $companyId = null): void
    {
        Cache::forget('catalog:companies');
        Cache::forget('catalog:registration-options');

        if ($companyId && $companyId > 0) {
            Cache::forget('catalog:branding:'.$companyId);
        }
    }

    /**
     * @return array{id: int, name: string, logo: ?string, color_palette: ?array<string, mixed>}|null
     */
    public function brandingFor(?int $companyId): ?array
    {
        if (! $companyId) {
            return null;
        }

        try {
            $payload = $this->remember('branding:'.$companyId, fn () => $this->get("/companies/{$companyId}"));
        } catch (RuntimeException) {
            return null;
        }

        $company = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        if (! is_array($company)) {
            return null;
        }

        return [
            'id' => (int) ($company['id'] ?? $companyId),
            'name' => (string) ($company['name'] ?? ''),
            'logo' => filled($company['logo'] ?? null) ? (string) $company['logo'] : null,
            'color_palette' => is_array($company['color_palette'] ?? null) ? $company['color_palette'] : null,
        ];
    }

    /** @param  array<string, mixed>  $filters */
    public function getProducts(array $filters = []): mixed
    {
        $key = 'products:'.md5((string) json_encode($filters));

        return $this->remember($key, fn () => $this->get('/products', $filters));
    }

    public function getProduct(int $id): mixed
    {
        return $this->get("/products/{$id}");
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function productsForCompany(int $companyId): array
    {
        return $this->listForCompany('company-products:'.$companyId, '/products', [
            'company_id' => $companyId,
            'is_active' => 1,
            'per_page' => 100,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function imcPackagesForCompany(int $companyId): array
    {
        return $this->listForCompany('imc-packages:'.$companyId, "/companies/{$companyId}/imc-packages", [
            'per_page' => 100,
        ], false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function wellnessNeedsForCompany(int $companyId): array
    {
        return $this->listForCompany('wellness-needs:'.$companyId, "/companies/{$companyId}/wellness-needs", [
            'per_page' => 100,
        ], false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function documentsForCompany(int $companyId, ?string $fileType = null): array
    {
        $query = [
            'per_page' => 100,
            'active_only' => 1,
        ];

        if ($fileType) {
            $query['file_type'] = $fileType;
        }

        return $this->listForCompany(
            'documents:'.$companyId.':'.($fileType ?: 'all'),
            "/companies/{$companyId}/documents",
            $query,
            false,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    public function createCompanyDocument(int $companyId, array $body): ?array
    {
        try {
            $forwarded = $this->forward('POST', '/companies/'.$companyId.'/documents', [], $body);
        } catch (RuntimeException) {
            return null;
        }

        $status = (int) ($forwarded['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $payload = $forwarded['body'];
        $row = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        return is_array($row) ? $row : null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function listForCompany(string $cacheKey, string $path, array $query = [], bool $cache = true): array
    {
        try {
            $fetch = fn () => $this->get($path, $query);
            $payload = $cache ? $this->remember($cacheKey, $fetch) : $fetch();
        } catch (RuntimeException) {
            return [];
        }

        $items = $payload['data'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    public function createProduct(array $body): ?array
    {
        $companyId = (int) ($body['company_id'] ?? 0);

        try {
            $payload = $this->post('/products', $body);
        } catch (RuntimeException) {
            return null;
        }

        if ($companyId > 0) {
            Cache::forget('catalog:company-products:'.$companyId);
        }

        $product = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        return is_array($product) ? $product : null;
    }

    public function getCompensationPlans(int $companyId): mixed
    {
        return $this->get("/companies/{$companyId}/compensation-plans");
    }

    public function getTechnicalSheet(int $productId): mixed
    {
        return $this->get("/products/{$productId}/technical-sheet");
    }

    /**
     * @return array<int, string>
     */
    public function companyNamesById(): array
    {
        try {
            $payload = $this->getCompanies();
        } catch (RuntimeException) {
            return [];
        }

        $items = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (! is_array($items)) {
            return [];
        }

        $map = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }

            $id = (int) $item['id'];
            $name = trim((string) ($item['name'] ?? ''));
            if ($id > 0 && $name !== '') {
                $map[$id] = $name;
            }
        }

        return $map;
    }

    public function getRegistrationOptions(): array
    {
        $payload = $this->remember('registration-options', fn () => $this->get('/registration-options'));
        $companies = $payload['data'] ?? $payload;

        return is_array($companies) ? array_values($companies) : [];
    }

    /**
     * @return array{company_id: int, company_name: string, rank_id: int, rank_name: string, plan_name: ?string}|null
     */
    public function findCompanyRank(int $companyId, int $rankId): ?array
    {
        foreach ($this->getRegistrationOptions() as $company) {
            if (! is_array($company) || (int) ($company['id'] ?? 0) !== $companyId) {
                continue;
            }

            foreach ($company['ranks'] ?? [] as $rank) {
                if (! is_array($rank) || (int) ($rank['id'] ?? 0) !== $rankId) {
                    continue;
                }

                return [
                    'company_id' => $companyId,
                    'company_name' => (string) ($company['name'] ?? ''),
                    'rank_id' => $rankId,
                    'rank_name' => (string) ($rank['name'] ?? ''),
                    'plan_name' => isset($rank['plan_name']) ? (string) $rank['plan_name'] : null,
                ];
            }
        }

        return null;
    }

    /**
     * Reenvía una petición al microservicio sin cache (panel admin).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: mixed}
     */
    public function forward(string $method, string $path, array $query = [], array $body = []): array
    {
        $base = rtrim((string) config('services.catalog.url'), '/');
        $token = (string) config('services.catalog.token');

        if ($base === '' || $token === '') {
            throw new RuntimeException('Catalog microservice is not configured.');
        }

        $request = Http::acceptJson()
            ->asJson()
            ->timeout(max(8, (int) config('services.catalog.timeout', 8)))
            ->withHeaders(['X-Service-Token' => $token]);

        $url = $base.'/'.ltrim($path, '/');
        $verb = strtoupper($method);

        $response = match ($verb) {
            'GET' => $request->get($url, $query),
            'POST' => $request->post($url, $body),
            'PUT' => $request->put($url, $body),
            'PATCH' => $request->patch($url, $body),
            'DELETE' => $request->delete($url, $query),
            default => throw new RuntimeException('Método no permitido hacia el catálogo.'),
        };

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? ['message' => $response->body() ?: 'Respuesta vacía del catálogo'],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): mixed
    {
        $base = rtrim((string) config('services.catalog.url'), '/');
        $token = (string) config('services.catalog.token');

        if ($base === '' || $token === '') {
            throw new RuntimeException('Catalog microservice is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.catalog.timeout', 8))
                ->withHeaders(['X-Service-Token' => $token])
                ->get($base.$path, $query)
                ->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Error al consultar el catálogo de empresas/productos.',
                previous: $exception,
            );
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body = []): mixed
    {
        $base = rtrim((string) config('services.catalog.url'), '/');
        $token = (string) config('services.catalog.token');

        if ($base === '' || $token === '') {
            throw new RuntimeException('Catalog microservice is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('services.catalog.timeout', 8))
                ->withHeaders(['X-Service-Token' => $token])
                ->post($base.$path, $body)
                ->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Error al publicar el producto en el catálogo de la empresa.',
                previous: $exception,
            );
        }

        return $response->json();
    }

    private function remember(string $key, callable $callback): mixed
    {
        $ttl = (int) config('services.catalog.cache_ttl', 300);

        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember('catalog:'.$key, $ttl, $callback);
    }
}
