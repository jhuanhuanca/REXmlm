<?php

declare(strict_types=1);

namespace App\Shared\Exports;

use Illuminate\Http\Response;
use ZipArchive;

class WorkbookExport
{
    /**
     * @var list<array{name: string, headers: list<string>, rows: list<list<scalar|null>>}>
     */
    private array $sheets = [];

    public function __construct(
        private readonly string $title,
        private readonly string $subtitle = '',
    ) {}

    /**
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     */
    public function addSheet(string $name, array $headers, array $rows): self
    {
        $this->sheets[] = [
            'name' => $this->sheetName($name),
            'headers' => $headers,
            'rows' => $rows,
        ];

        return $this;
    }

    public function download(string $basename, string $format): Response
    {
        $format = strtolower($format) === 'pdf' ? 'pdf' : 'xlsx';
        $filename = $basename.'.'.$format;
        $body = $format === 'pdf' ? $this->toPdf() : $this->toXlsx();
        $mime = $format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($body, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function toXlsx(): string
    {
        if ($this->sheets === []) {
            $this->addSheet('Reporte', ['Mensaje'], [['Sin datos']]);
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new \RuntimeException('No se pudo crear el archivo Excel.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo armar el Excel.');
        }

        $sheetCount = count($this->sheets);
        $workbookSheets = '';
        $rels = '';
        $overrides = '';

        foreach ($this->sheets as $index => $sheet) {
            $sheetId = $index + 1;
            $rId = 'rId'.$sheetId;
            $workbookSheets .= '<sheet name="'.$this->xml($sheet['name']).'" sheetId="'.$sheetId.'" r:id="'.$rId.'"/>';
            $rels .= '<Relationship Id="'.$rId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheetId.'.xml"/>';
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$sheetId.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$sheetId.'.xml', $this->worksheetXml($sheet));
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$workbookSheets.'</sheets></workbook>');
        $zip->close();

        $body = file_get_contents($path);
        @unlink($path);

        if ($body === false) {
            throw new \RuntimeException('No se pudo leer el Excel.');
        }

        return $body;
    }

    public function toPdf(): string
    {
        $pdf = new SimpleTablePdf($this->title, $this->subtitle);

        foreach ($this->sheets as $sheet) {
            $pdf->addTable($sheet['name'], $sheet['headers'], $sheet['rows']);
        }

        return $pdf->output();
    }

    /**
     * @param  array{name: string, headers: list<string>, rows: list<list<scalar|null>>}  $sheet
     */
    private function worksheetXml(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $xml .= $this->rowXml(1, $sheet['headers'], true);

        foreach ($sheet['rows'] as $index => $row) {
            $padded = [];
            foreach ($sheet['headers'] as $col => $header) {
                $padded[] = $row[$col] ?? '';
            }
            $xml .= $this->rowXml($index + 2, $padded, false);
        }

        return $xml.'</sheetData></worksheet>';
    }

    /**
     * @param  list<scalar|null>  $cells
     */
    private function rowXml(int $rowNumber, array $cells, bool $header): string
    {
        $xml = '<row r="'.$rowNumber.'">';

        foreach ($cells as $index => $value) {
            $cell = $this->columnLetter($index + 1).$rowNumber;
            if (is_int($value) || is_float($value)) {
                $xml .= '<c r="'.$cell.'" t="n"><v>'.$value.'</v></c>';
                continue;
            }

            $text = $this->xml((string) ($value ?? ''));
            $xml .= '<c r="'.$cell.'" t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
            unset($header);
        }

        return $xml.'</row>';
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    private function sheetName(string $name): string
    {
        $clean = preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $name) ?: 'Hoja';
        $clean = trim($clean);

        return mb_substr($clean !== '' ? $clean : 'Hoja', 0, 31);
    }

    private function xml(string $value): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
