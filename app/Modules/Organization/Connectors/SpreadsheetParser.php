<?php

declare(strict_types=1);

namespace App\Modules\Organization\Connectors;

use InvalidArgumentException;

class SpreadsheetParser
{
    /**
     * @return array{headers: list<string>, rows: list<list<string>>, counts: array<string, int>}
     */
    public function parse(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $matrix = match ($ext) {
            'xlsx' => $this->xlsx($path),
            'csv', 'txt' => $this->delimited($path, null),
            'tsv' => $this->delimited($path, "\t"),
            default => throw new InvalidArgumentException('Formato no soportado. Usa CSV, TSV o XLSX.'),
        };

        $headers = array_map(fn ($value) => trim((string) $value), $matrix[0] ?? []);
        $rows = array_values(array_filter(
            array_slice($matrix, 1),
            fn (array $row) => implode('', $row) !== '',
        ));

        return [
            'headers' => $headers,
            'rows' => $rows,
            'counts' => $this->classify($headers, count($rows)),
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    public function classify(array $headers, int $rowCount): array
    {
        $blob = mb_strtolower(\Illuminate\Support\Str::ascii(implode(' ', $headers)));
        $counts = [
            'rows' => $rowCount,
            'members' => 0,
            'sales' => 0,
            'volumes' => 0,
            'commissions' => 0,
        ];

        if ($rowCount < 1) {
            return $counts;
        }

        $looksMembers = str_contains($blob, 'email') || str_contains($blob, 'correo') || str_contains($blob, 'sponsor') || str_contains($blob, 'afiliad') || str_contains($blob, 'codigo') || str_contains($blob, 'member');
        $looksSales = str_contains($blob, 'pedido') || str_contains($blob, 'order') || str_contains($blob, 'factura') || str_contains($blob, 'sku');
        $looksVolume = str_contains($blob, 'pv') || str_contains($blob, 'gv') || str_contains($blob, 'puntos') || str_contains($blob, 'volumen');
        $looksCommission = str_contains($blob, 'comision') || str_contains($blob, 'commission') || str_contains($blob, 'bono');

        if ($looksMembers || (! $looksSales && ! $looksCommission && ! $looksVolume)) {
            $counts['members'] = $rowCount;
        }
        if ($looksSales) {
            $counts['sales'] = $rowCount;
        }
        if ($looksVolume) {
            $counts['volumes'] = $rowCount;
        }
        if ($looksCommission) {
            $counts['commissions'] = $rowCount;
        }

        return $counts;
    }

    /**
     * @return list<list<string>>
     */
    private function delimited(string $path, ?string $delimiter): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException('No se pudo leer el archivo.');
        }

        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);

            return [];
        }

        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
        $delimiter ??= $this->guessDelimiter($first);
        rewind($handle);

        $rows = [];
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = array_map(fn ($cell) => trim((string) $cell), $data);
        }
        fclose($handle);

        return $rows;
    }

    private function guessDelimiter(string $line): string
    {
        $candidates = [',' => 0, ';' => 0, "\t" => 0];
        foreach (array_keys($candidates) as $delimiter) {
            $candidates[$delimiter] = substr_count($line, $delimiter);
        }
        arsort($candidates);
        $best = array_key_first($candidates);

        return ($candidates[$best] ?? 0) > 0 ? $best : ',';
    }

    /**
     * @return list<list<string>>
     */
    private function xlsx(string $path): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('No se pudo abrir el XLSX.');
        }

        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            preg_match_all('/<t(?: xml:space="preserve")?>([^<]*)<\/t>/u', $sharedXml, $matches);
            $shared = $matches[1] ?? [];
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (! is_string($sheet) || $sheet === '') {
            throw new InvalidArgumentException('El Excel no tiene hoja 1 legible.');
        }

        $rows = [];
        preg_match_all('/<c\\b([^>]*)>(?:<v>([^<]*)<\\/v>)?/u', $sheet, $cells, PREG_SET_ORDER);
        $currentRow = 0;
        $buffer = [];

        foreach ($cells as $cell) {
            $attrs = $cell[1] ?? '';
            $raw = $cell[2] ?? '';
            if (! preg_match('/r="([A-Z]+)(\\d+)"/', $attrs, $ref)) {
                continue;
            }
            $rowNum = (int) $ref[2];
            $col = $this->columnIndex($ref[1]);
            if ($rowNum !== $currentRow) {
                if ($buffer !== []) {
                    $rows[] = $this->padRow($buffer);
                }
                $buffer = [];
                $currentRow = $rowNum;
            }

            $value = $raw;
            if (str_contains($attrs, 't="s"') && isset($shared[(int) $raw])) {
                $value = html_entity_decode($shared[(int) $raw], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $buffer[$col] = $value;
        }

        if ($buffer !== []) {
            $rows[] = $this->padRow($buffer);
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $buffer
     * @return list<string>
     */
    private function padRow(array $buffer): array
    {
        $max = $buffer === [] ? 0 : max(array_keys($buffer));
        $row = [];
        for ($i = 0; $i <= $max; $i++) {
            $row[] = (string) ($buffer[$i] ?? '');
        }

        return $row;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        $upper = strtoupper($letters);
        $length = strlen($upper);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($upper[$i]) - 64);
        }

        return max(0, $index - 1);
    }
}
