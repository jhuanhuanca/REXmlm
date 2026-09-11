<?php

declare(strict_types=1);

namespace App\Modules\Organization\Canonical;

use Illuminate\Support\Str;

final class FieldAliasMap
{
    /**
     * Alias genéricos. Sirven para HGW, DXN, Omnilife, Face Global y cualquier
     * export que use nombres parecidos. Un mapeo a medida va en connection.config.field_map.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        'member_code' => [
            'codigo', 'code', 'member_code', 'memberid', 'member_id',
            'distributor_id', 'distributor_code', 'distribuidor', 'id_distribuidor',
            'codigo_distribuidor', 'dxn_id', 'dxn_code', 'hgw_id', 'external_id',
            'affiliate_id', 'affiliate_code', 'networker_id', 'codigo_afiliado',
        ],
        'email' => ['email', 'correo', 'mail', 'e_mail', 'correo_electronico'],
        'name' => ['nombre', 'name', 'fullname', 'full_name', 'nombre_completo', 'distributor_name'],
        'phone' => ['telefono', 'phone', 'celular', 'whatsapp', 'mobile'],
        'status' => ['status', 'estado', 'activo'],
        'sponsor_code' => [
            'sponsor', 'sponsor_id', 'sponsor_code', 'patrocinador', 'codigo_patrocinador',
            'upline', 'upline_id', 'parent_code', 'sponsorid',
        ],
        'personal_volume' => [
            'pv', 'vp', 'personal_volume', 'personalvolume', 'puntos', 'puntos_personales',
            'volumen_personal', 'ppv',
        ],
        'group_volume' => [
            'gv', 'vg', 'group_volume', 'groupvolume', 'volumen_grupo', 'ngv', 'group',
        ],
        'sales_volume' => ['sv', 'sales_volume', 'salesvolume', 'volumen_ventas'],
        'commission_volume' => ['cv', 'commission_volume', 'commissionvolume', 'volumen_comision'],
        'rank' => ['rango', 'rank', 'title', 'titulo', 'rank_name', 'current_rank'],
        'rank_code' => ['rank_code', 'codigo_rango', 'rank_id'],
        'period' => ['periodo', 'period', 'mes', 'month', 'ciclo', 'cycle'],
        'order_code' => [
            'pedido', 'order', 'order_id', 'order_code', 'factura', 'invoice',
            'nro_pedido', 'numero_pedido', 'invoice_no',
        ],
        'ordered_at' => ['fecha', 'date', 'order_date', 'fecha_pedido', 'paid_at', 'invoice_date'],
        'total' => ['total', 'amount', 'monto', 'importe', 'sales', 'venta'],
        'currency' => ['moneda', 'currency'],
        'sku' => ['sku', 'producto', 'product', 'product_code', 'codigo_producto'],
        'quantity' => ['cantidad', 'qty', 'quantity', 'unidades'],
        'item_pv' => ['item_pv', 'pv_item', 'puntos_item'],
        'customer_name' => ['cliente', 'customer', 'customer_name', 'nombre_cliente'],
        'customer_email' => ['customer_email', 'correo_cliente', 'email_cliente'],
        'customer_code' => ['customer_id', 'codigo_cliente'],
        'commission_amount' => ['comision', 'commission', 'bono', 'bonus', 'importe_bono'],
        'commission_kind' => ['tipo_bono', 'bonus_type', 'kind', 'tipo_comision'],
        'qualifying' => ['qualifying', 'califica', 'calificado', 'activo_mlm'],
    ];

    /**
     * @param  array<string, mixed>  $row  claves ya normalizadas
     * @param  array<string, string>  $fieldMap  lógico → clave del archivo
     */
    public function pick(array $row, string $field, array $fieldMap = []): ?string
    {
        $override = $fieldMap[$field] ?? null;
        if (is_string($override) && $override !== '') {
            $key = $this->normalize($override);
            if (array_key_exists($key, $row) && $this->filled($row[$key])) {
                return trim((string) $row[$key]);
            }
        }

        foreach (self::ALIASES[$field] ?? [] as $alias) {
            if (array_key_exists($alias, $row) && $this->filled($row[$alias])) {
                return trim((string) $row[$alias]);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $fieldMap
     */
    public function number(array $row, string $field, array $fieldMap = []): ?float
    {
        $raw = $this->pick($row, $field, $fieldMap);
        if ($raw === null) {
            return null;
        }

        $raw = str_replace(' ', '', $raw);
        $raw = preg_replace('/[^0-9,.\-]/', '', $raw) ?? $raw;
        if ($raw === '' || $raw === '-' || $raw === '.' || $raw === ',') {
            return null;
        }

        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            if (strrpos($raw, ',') > strrpos($raw, '.')) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } else {
                $raw = str_replace(',', '', $raw);
            }
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    public function normalize(string $header): string
    {
        $ascii = Str::ascii(mb_strtolower(trim($header)));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $ascii), '_');
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $row
     * @return array<string, string>
     */
    public function associate(array $headers, array $row): array
    {
        $assoc = [];
        foreach ($headers as $index => $header) {
            $assoc[$this->normalize((string) $header)] = trim((string) ($row[$index] ?? ''));
        }

        return $assoc;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    public function normalizeKeys(array $row): array
    {
        $assoc = [];
        foreach ($row as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $assoc[$this->normalize((string) $key)] = trim((string) $value);
        }

        return $assoc;
    }

    public function guessUnit(array $row, array $fieldMap = []): string
    {
        if ($this->pick($row, 'personal_volume', $fieldMap) !== null
            && array_key_exists('puntos', $row)
            && ! array_key_exists('pv', $row)
            && ! array_key_exists('vp', $row)
        ) {
            return 'puntos';
        }

        if ($this->number($row, 'personal_volume', $fieldMap) === null
            && $this->number($row, 'sales_volume', $fieldMap) !== null
        ) {
            return 'SV';
        }

        return 'PV';
    }

    public function isTruthy(?string $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = Str::ascii(mb_strtolower(trim($value)));

        return match (true) {
            in_array($normalized, ['1', 'true', 'yes', 'si', 'sí', 'y', 's', 'activo', 'califica'], true) => true,
            in_array($normalized, ['0', 'false', 'no', 'n', 'inactivo'], true) => false,
            default => null,
        };
    }

    private function filled(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }
}
