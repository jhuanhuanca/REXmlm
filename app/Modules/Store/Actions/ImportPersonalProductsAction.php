<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Modules\Store\Enums\ProductFulfillment;
use App\Modules\Store\Enums\ProductSource;
use App\Modules\Store\Models\Product;
use App\Modules\Store\Models\Store;
use App\Shared\Support\Currencies;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ImportPersonalProductsAction
{
    public const MAX_DATA_ROWS = 500;

    /**
     * @return array{imported: int, skipped: int, products: list<Product>}
     */
    public function handle(Store $store, UploadedFile $file, ProductSource $source = ProductSource::Personal): array
    {
        $path = $file->getRealPath() ?: '';
        $raw = $path !== '' ? file_get_contents($path) : false;

        if ($raw === false || trim($raw) === '') {
            throw ValidationException::withMessages([
                'file' => ['No se pudo leer el CSV.'],
            ]);
        }

        $raw = $this->stripBom($raw);
        $delimiter = $this->detectDelimiter($raw);
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => ['No se pudo leer el CSV.'],
            ]);
        }

        fwrite($handle, $raw);
        rewind($handle);

        try {
            $header = $this->nextCsvRow($handle, $delimiter);

            while ($header !== null && $this->isSeparatorHint($header)) {
                $header = $this->nextCsvRow($handle, $delimiter);
            }

            if ($header === null || $this->rowEmpty($header)) {
                throw ValidationException::withMessages([
                    'file' => ['El CSV está vacío o no tiene cabecera.'],
                ]);
            }

            $map = $this->headerMap($header);
            $imported = 0;
            $skipped = 0;
            $products = [];
            $dataRows = [];

            while (($row = $this->nextCsvRow($handle, $delimiter)) !== null) {
                if ($this->rowEmpty($row) || $this->isSeparatorHint($row)) {
                    continue;
                }

                $dataRows[] = $row;

                if (count($dataRows) > self::MAX_DATA_ROWS) {
                    throw ValidationException::withMessages([
                        'file' => ['El CSV no puede tener más de '.self::MAX_DATA_ROWS.' filas de productos.'],
                    ]);
                }
            }

            foreach ($dataRows as $row) {
                $payload = $this->rowPayload($store, $map, $row, $source);

                if ($payload === null) {
                    $skipped++;
                    continue;
                }

                $products[] = $store->products()->create($payload);
                $imported++;
            }
        } finally {
            fclose($handle);
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'products' => $products,
        ];
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private function headerMap(array $header): array
    {
        $aliases = [
            'name' => ['name', 'nombre', 'producto'],
            'price' => ['price', 'precio', 'venta', 'precio_venta'],
            'purchase_cost' => ['purchase_cost', 'costo', 'costo_compra', 'coste'],
            'stock' => ['stock', 'existencia'],
            'description' => ['description', 'descripcion', 'descripción'],
            'image' => ['image', 'imagen', 'image_url', 'url'],
            'technical_sheet' => ['technical_sheet', 'ficha', 'ficha_tecnica', 'ficha técnica'],
            'expires_at' => ['expires_at', 'vencimiento', 'caducidad', 'expiry'],
            'is_active' => ['is_active', 'activo', 'active'],
            'is_published' => ['is_published', 'publicado', 'published'],
            'fulfillment' => ['fulfillment', 'envio', 'envío', 'dropship', 'dropshipping'],
            'dropship_url' => ['dropship_url', 'proveedor', 'supplier_url'],
            'dropship_sku' => ['dropship_sku', 'sku', 'sku_proveedor'],
            'currency' => ['currency', 'moneda'],
            'category' => ['category', 'categoria', 'categoría', 'cat'],
        ];

        $map = [];

        foreach ($header as $index => $label) {
            $key = $this->normalize((string) $label);

            foreach ($aliases as $field => $names) {
                if (in_array($key, array_map(fn (string $name) => $this->normalize($name), $names), true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        if (! isset($map['name'], $map['price'])) {
            throw ValidationException::withMessages([
                'file' => ['El CSV debe incluir columnas nombre y precio (name, price).'],
            ]);
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $map
     * @param  list<string|null>  $row
     * @return array<string, mixed>|null
     */
    private function rowPayload(Store $store, array $map, array $row, ProductSource $source): ?array
    {
        $name = trim((string) ($row[$map['name']] ?? ''));

        if ($name === '') {
            return null;
        }

        $price = $this->number($row[$map['price']] ?? 0);
        $fulfillment = $this->fulfillment($this->value($map, $row, 'fulfillment'));
        $stock = (int) max(0, $this->number($this->value($map, $row, 'stock') ?? 0));

        if ($fulfillment === ProductFulfillment::Dropship && $stock === 0) {
            $stock = 0;
        }

        $published = $source === ProductSource::Incentive
            ? false
            : $this->boolean($this->value($map, $row, 'is_published'), true);

        return [
            'source' => $source === ProductSource::Incentive ? ProductSource::Incentive : ProductSource::Personal,
            'name' => $name,
            'description' => $this->nullableString($this->value($map, $row, 'description')),
            'technical_sheet' => $this->nullableString($this->value($map, $row, 'technical_sheet')),
            'price' => $price,
            'purchase_cost' => round($this->number($this->value($map, $row, 'purchase_cost') ?? 0), 2),
            'currency' => Currencies::normalize($this->value($map, $row, 'currency'), $store->currency()),
            'stock' => $stock,
            'image' => $this->nullableString($this->value($map, $row, 'image')),
            'dropship_url' => $this->nullableString($this->value($map, $row, 'dropship_url')),
            'dropship_sku' => $this->nullableString($this->value($map, $row, 'dropship_sku')),
            'is_active' => $this->boolean($this->value($map, $row, 'is_active'), true),
            'is_published' => $published,
            'fulfillment' => $fulfillment,
            'expires_at' => $this->date($this->value($map, $row, 'expires_at')),
            'store_category_id' => $this->categoryId($store, $this->value($map, $row, 'category')),
        ];
    }

    /**
     * @param  array<string, int>  $map
     * @param  list<string|null>  $row
     */
    private function value(array $map, array $row, string $field): mixed
    {
        if (! isset($map[$field])) {
            return null;
        }

        return $row[$map[$field]] ?? null;
    }

    private function fulfillment(mixed $value): ProductFulfillment
    {
        $key = $this->normalize((string) $value);

        if (in_array($key, ['dropship', 'dropshipping', 'drop', 'envio', 'envio directo', 'proveedor'], true)) {
            return ProductFulfillment::Dropship;
        }

        return ProductFulfillment::Stock;
    }

    private function boolean(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $key = $this->normalize((string) $value);

        if (in_array($key, ['0', 'false', 'no', 'inactivo', 'off'], true)) {
            return false;
        }

        return in_array($key, ['1', 'true', 'si', 'sí', 'yes', 'activo', 'on'], true) || $default;
    }

    private function date(mixed $value): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function number(mixed $value): float
    {
        $raw = str_replace([' ', ','], ['', '.'], trim((string) $value));

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    private function nullableString(mixed $value): ?string
    {
        $raw = trim((string) $value);

        return $raw === '' ? null : $raw;
    }

    private function categoryId(Store $store, mixed $value): ?int
    {
        $name = trim((string) $value);

        if ($name === '') {
            return null;
        }

        $existing = $store->productCategories()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        return (int) $store->productCategories()->create([
            'name' => $name,
            'sort' => (int) $store->productCategories()->max('sort') + 1,
        ])->id;
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $value);

        return preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    }

    /**
     * @param  list<string|null>  $row
     */
    private function rowEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stripBom(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }

        return $raw;
    }

    private function detectDelimiter(string $raw): string
    {
        $line = strtok(str_replace(["\r\n", "\r"], "\n", $raw), "\n") ?: '';
        $line = ltrim($line);

        if (preg_match('/^sep=(.)$/i', $line, $match) === 1) {
            return $match[1];
        }

        $counts = [
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];
        arsort($counts);
        $best = (string) array_key_first($counts);

        return ($counts[$best] ?? 0) > 0 ? $best : ',';
    }

    /**
     * @param  resource  $handle
     * @return list<string|null>|null
     */
    private function nextCsvRow($handle, string $delimiter): ?array
    {
        $row = fgetcsv($handle, 0, $delimiter, '"', '\\');

        return $row === false ? null : $row;
    }

    /**
     * @param  list<string|null>  $row
     */
    private function isSeparatorHint(array $row): bool
    {
        $first = strtolower(trim((string) ($row[0] ?? '')));

        return str_starts_with($first, 'sep=');
    }
}
