<?php

declare(strict_types=1);

namespace App\Shared\Exports;

class SimpleTablePdf
{
    /** @var list<string> */
    private array $objects = [];

    private string $pagesKids = '';

    private int $pageCount = 0;

    public function __construct(
        private readonly string $title,
        private readonly string $subtitle = '',
    ) {}

    /**
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     */
    public function addTable(string $heading, array $headers, array $rows): void
    {
        $pageW = 842.0;
        $pageH = 595.0;
        $margin = 28.0;
        $usable = $pageW - ($margin * 2);
        $colCount = max(1, count($headers));
        $colW = $usable / $colCount;
        $rowH = 14.0;
        $y = $pageH - $margin;
        $commands = [];

        $flush = function () use (&$commands, $pageW, $pageH, &$y, $margin): void {
            $this->addPage($pageW, $pageH, implode("\n", $commands));
            $commands = [];
            $y = $pageH - $margin;
        };

        $write = function (float $x, float $yPos, string $text, int $size = 8) use (&$commands): void {
            $safe = $this->pdfText($text);
            $commands[] = sprintf(
                'BT /F1 %d Tf %.2f %.2f Td (%s) Tj ET',
                $size,
                $x,
                $yPos,
                $safe,
            );
        };

        $line = function (float $x1, float $y1, float $x2, float $y2) use (&$commands): void {
            $commands[] = sprintf('%.2f %.2f m %.2f %.2f l S', $x1, $y1, $x2, $y2);
        };

        $y -= 6;
        $write($margin, $y, $this->title, 12);
        $y -= 14;
        if ($this->subtitle !== '') {
            $write($margin, $y, $this->subtitle, 8);
            $y -= 12;
        }
        $write($margin, $y, $heading.' · '.$this->nowLabel(), 8);
        $y -= 16;

        $drawHeader = function () use (&$y, $headers, $margin, $colW, $rowH, $write, $line, $usable): void {
            $x = $margin;
            foreach ($headers as $header) {
                $write($x + 2, $y - 10, $this->fit((string) $header, $colW), 7);
                $x += $colW;
            }
            $line($margin, $y - $rowH, $margin + $usable, $y - $rowH);
            $y -= $rowH;
        };

        $drawHeader();

        if ($rows === []) {
            $write($margin, $y - 10, 'Sin registros.', 8);
            $flush();

            return;
        }

        foreach ($rows as $row) {
            if ($y < $margin + $rowH + 8) {
                $flush();
                $y -= 10;
                $write($margin, $y, $this->title.' (cont.)', 10);
                $y -= 16;
                $drawHeader();
            }

            $x = $margin;
            for ($i = 0; $i < $colCount; $i++) {
                $write($x + 2, $y - 10, $this->fit((string) ($row[$i] ?? ''), $colW), 7);
                $x += $colW;
            }
            $y -= $rowH;
        }

        $flush();
    }

    public function output(): string
    {
        if ($this->pageCount === 0) {
            $this->addTable('Reporte', ['Mensaje'], [['Sin datos']]);
        }

        $fontId = count($this->objects) + 1;
        $this->objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        $pagesId = count($this->objects) + 1;
        $this->objects[] = sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            trim($this->pagesKids),
            $this->pageCount,
        );

        $catalogId = count($this->objects) + 1;
        $this->objects[] = sprintf('<< /Type /Catalog /Pages %d 0 R >>', $pagesId);

        $body = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($this->objects as $i => $object) {
            $offsets[] = strlen($body);
            $id = $i + 1;
            $patched = str_replace('__FONT__', $fontId.' 0 R', $object);
            $patched = str_replace('__PAGES__', $pagesId.' 0 R', $patched);
            $body .= $id." 0 obj\n".$patched."\nendobj\n";
        }

        $xref = strlen($body);
        $count = count($this->objects) + 1;
        $body .= "xref\n0 {$count}\n";
        $body .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $body .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $body .= "trailer\n<< /Size {$count} /Root {$catalogId} 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $body;
    }

    private function addPage(float $width, float $height, string $content): void
    {
        $this->pageCount++;
        $content = "0.2 w\n".$content;
        $stream = "<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream";
        $this->objects[] = $stream;
        $contentId = count($this->objects);
        $this->objects[] = sprintf(
            '<< /Type /Page /Parent __PAGES__ /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 __FONT__ >> >> /Contents %d 0 R >>',
            $width,
            $height,
            $contentId,
        );
        $pageId = count($this->objects);
        $this->pagesKids .= $pageId.' 0 R ';
    }

    private function pdfText(string $text): string
    {
        $latin = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($latin === false) {
            $latin = $text;
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $latin);
    }

    private function fit(string $text, float $colWidth): string
    {
        $max = max(4, (int) floor($colWidth / 4.6));
        $plain = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (mb_strlen($plain) <= $max) {
            return $plain;
        }

        return mb_substr($plain, 0, $max - 1).'...';
    }

    private function nowLabel(): string
    {
        return now()->timezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i');
    }
}
