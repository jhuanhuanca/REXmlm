<?php

declare(strict_types=1);

namespace App\Modules\Tools\Services;

use App\Models\User;
use App\Modules\Store\Models\Product;
use App\Modules\Subscription\Services\PlanEntitlements;
use App\Modules\Tools\CompanyToolCatalog;
use App\Services\Catalog\CatalogClient;
use App\Services\Catalog\CatalogProductAvailability;
use App\Services\Catalog\CompanyBranding;

class CompanyToolsService
{
    public function __construct(
        private readonly CatalogClient $catalog,
    ) {}

    /**
     * @return array{company: array<string, mixed>|null, tools: list<string>}
     */
    public function available(User $user): array
    {
        $brand = CompanyBranding::forUser($user);
        $companyId = (int) ($brand['id'] ?? 0);

        $tools = $this->catalog->enabledToolsForCompany($companyId > 0 ? $companyId : null);
        $tools = array_values(array_filter(
            $tools,
            static fn (string $key): bool => $key !== 'whatsapp_chatbot',
        ));
        if (PlanEntitlements::allows($user, PlanEntitlements::WHATSAPP_CHATBOT)
            || $this->planLooksPremium($user)) {
            $tools[] = 'whatsapp_chatbot';
        }

        return [
            'company' => $brand,
            'tools' => $tools,
        ];
    }

    public function allows(User $user, string $key): bool
    {
        $brand = CompanyBranding::forUser($user);
        $companyId = (int) ($brand['id'] ?? 0);

        return in_array($key, $this->catalog->enabledToolsForCompany($companyId > 0 ? $companyId : null), true);
    }

    /**
     * @return array{company: array<string, mixed>|null, data: list<array<string, mixed>>}
     */
    public function imcPackages(User $user): array
    {
        $brand = CompanyBranding::forUser($user);
        $companyId = (int) ($brand['id'] ?? 0);

        if ($companyId <= 0) {
            return ['company' => $brand, 'data' => []];
        }

        $mapped = [];

        foreach ($this->catalog->imcPackagesForCompany($companyId) as $row) {
            $pack = $this->mapPackage($row, $user->country, 'goal');

            if ($pack !== null) {
                $mapped[] = $pack;
            }
        }

        return ['company' => $brand, 'data' => $mapped];
    }

    /**
     * @return array{company: array<string, mixed>|null, data: list<array<string, mixed>>}
     */
    public function wellnessNeeds(User $user): array
    {
        $brand = CompanyBranding::forUser($user);
        $companyId = (int) ($brand['id'] ?? 0);

        if ($companyId <= 0) {
            return ['company' => $brand, 'data' => []];
        }

        $mapped = [];

        foreach ($this->catalog->wellnessNeedsForCompany($companyId) as $row) {
            $need = $this->mapPackage($row, $user->country, null);

            if ($need !== null) {
                $mapped[] = $need;
            }
        }

        return ['company' => $brand, 'data' => $mapped];
    }

    /**
     * @return array{company: array<string, mixed>|null, data: list<array<string, mixed>>}
     */
    public function documents(User $user, ?string $fileType = null): array
    {
        $brand = CompanyBranding::forUser($user);
        $companyId = (int) ($brand['id'] ?? 0);

        if ($companyId <= 0) {
            return ['company' => $brand, 'data' => []];
        }

        $type = match ($fileType) {
            'flyer', 'image' => 'image',
            'pdf' => 'pdf',
            'video' => 'video',
            'audio' => 'audio',
            default => null,
        };

        $mapped = [];

        $enabled = $this->catalog->enabledToolsForCompany($companyId);

        foreach ($this->catalog->documentsForCompany($companyId, $type) as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $url = trim((string) ($row['url'] ?? $row['file_path'] ?? ''));

            if ($title === '' || $url === '') {
                continue;
            }

            $file = (string) ($row['file_type'] ?? '');

            if ($file === 'voucher') {
                continue;
            }

            $toolKey = CompanyToolCatalog::documentKey($file);
            if ($toolKey !== null && ! in_array($toolKey, $enabled, true)) {
                continue;
            }

            $mapped[] = [
                'id' => $row['id'] ?? null,
                'title' => $title,
                'description' => $row['description'] ?? null,
                'url' => $url,
                'file_type' => $file,
                'kind' => $row['kind'] ?? ($file === 'image' ? 'flyer' : $file),
                'player' => $row['player'] ?? null,
                'embed_url' => $row['embed_url'] ?? null,
                'thumbnail' => $row['thumbnail'] ?? null,
                'filename' => $row['original_name'] ?? null,
            ];
        }

        return ['company' => $brand, 'data' => $mapped];
    }

    /**
     * @return array{company: array<string, mixed>|null, data: list<array<string, mixed>>}
     */
    public function catalogProducts(User $user): array
    {
        $brand = CompanyBranding::forUser($user);
        $store = $user->store;

        if ($store === null) {
            return ['company' => $brand, 'data' => []];
        }

        $mapped = [];
        $products = $store->products()
            ->with('category')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        foreach ($products as $product) {
            /** @var Product $product */
            $name = trim((string) $product->name);
            $image = trim((string) ($product->image ?? ''));

            if ($name === '' || $image === '') {
                continue;
            }

            $mapped[] = [
                'id' => $product->id,
                'name' => $name,
                'image' => $image,
                'category' => $product->category?->name,
                'stock' => (int) $product->stock,
                'catalog_company_id' => $product->catalog_company_id ? (int) $product->catalog_company_id : null,
            ];
        }

        return ['company' => $brand, 'data' => $mapped];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapPackage(array $row, ?string $country, ?string $goalKey): ?array
    {
        $products = $this->mapItems(is_array($row['items'] ?? null) ? $row['items'] : [], $country);

        $pack = [
            'id' => $row['id'] ?? null,
            'title' => (string) ($row['name'] ?? $row['title'] ?? ''),
            'focus' => (string) ($row['description'] ?? ''),
            'image' => $row['image'] ?? null,
            'products' => $products,
        ];

        if ($goalKey !== null) {
            $pack['goal'] = (string) ($row[$goalKey] ?? '');
        }

        return $pack['title'] === '' ? null : $pack;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array{name: string, usage: string, product_id: int|null, image: ?string}>
     */
    private function mapItems(array $items, ?string $country): array
    {
        $products = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $product = is_array($item['product'] ?? null) ? $item['product'] : [];

            if ($product !== [] && ! CatalogProductAvailability::matches($product, $country)) {
                continue;
            }

            if ($product !== [] && array_key_exists('is_active', $product) && ! $product['is_active']) {
                continue;
            }

            $name = trim((string) ($product['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $usage = trim((string) ($item['notes'] ?? $product['description'] ?? $product['technical_sheet'] ?? ''));

            $products[] = [
                'name' => $name,
                'usage' => $usage,
                'product_id' => isset($product['id']) ? (int) $product['id'] : null,
                'image' => isset($product['image']) ? (string) $product['image'] : null,
            ];
        }

        return $products;
    }

    private function planLooksPremium(User $user): bool
    {
        $plan = $user->subscription('default')?->plan;
        $hay = strtolower(trim((string) ($plan?->slug ?? '').' '.(string) ($plan?->name ?? '')));

        return str_contains($hay, 'premium') || str_contains($hay, 'enterprise');
    }
}
