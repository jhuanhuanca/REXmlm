<?php

declare(strict_types=1);

namespace App\Modules\Organization\Canonical;

final class JsonRecordExtractor
{
    /**
     * @return list<array<string, mixed>>
     */
    public function collections(mixed $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        $bags = [
            'members' => ['members', 'distributors', 'affiliates', 'networkers', 'distribuidores', 'afiliados'],
            'orders' => ['orders', 'sales', 'ventas', 'pedidos', 'invoices'],
            'commissions' => ['commissions', 'bonuses', 'bonos', 'comisiones'],
            'volumes' => ['volumes', 'volumenes'],
            'customers' => ['customers', 'clientes'],
        ];

        $found = [];
        $this->walk($body, $bags, $found, 0);

        if ($found !== []) {
            return $found;
        }

        if ($this->isList($body) && $body !== [] && is_array($body[array_key_first($body)] ?? null)) {
            return array_values(array_filter($body, 'is_array'));
        }

        $keys = array_keys($body);
        $looksRecord = $keys !== [] && ! $this->isList($body) && ! in_array('ok', $keys, true);
        if ($looksRecord && (isset($body['email']) || isset($body['codigo']) || isset($body['pv']) || isset($body['code']))) {
            return [$body];
        }

        return [];
    }

    /**
     * @param  array<string, list<string>>  $bags
     * @param  list<array<string, mixed>>  $found
     */
    private function walk(array $node, array $bags, array &$found, int $depth): void
    {
        if ($depth > 4) {
            return;
        }

        foreach ($node as $key => $value) {
            $normalized = is_string($key) ? mb_strtolower($key) : (string) $key;
            if (is_array($value) && $this->isList($value)) {
                foreach ($bags as $aliases) {
                    if (in_array($normalized, $aliases, true)) {
                        foreach ($value as $item) {
                            if (is_array($item)) {
                                $found[] = $item;
                            }
                        }
                    }
                }
            }
            if (is_array($value) && ! $this->isList($value)) {
                $this->walk($value, $bags, $found, $depth + 1);
            }
        }
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
