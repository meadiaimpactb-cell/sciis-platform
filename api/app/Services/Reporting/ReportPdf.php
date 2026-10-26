<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * RPT-06 — report PDFs on the SCII identity (navy #24306C, teal #58B4A8, logo) with mPDF: RTL layout, Arabic
 * shaping through DejaVu Sans' OpenType tables, Latin digits only (numbers are formatted with number_format and
 * dates as Y-m-d; nothing is localised to Arabic-Indic digits).
 *
 * A document is a title, a meta strip (period, filters, generated at…), an optional summary paragraph and
 * sections — each a table and optionally a bar list (`bars`) drawn with plain table cells.
 */
final class ReportPdf
{
    private const NAVY = '#24306C';

    private const TEAL = '#58B4A8';

    /**
     * @param  array<int, array{label: string, value: string}>  $meta
     * @param  array<int, array{heading?: string|null, columns: array<int, string>, rows: array<int, array<int, mixed>>, bars?: array<int, array{label: string, value: float|int}>, unit?: string|null, note?: string|null}>  $sections
     */
    public function render(string $title, array $meta, array $sections, ?string $summary = null, bool $internal = true): string
    {
        $rtl = app()->getLocale() !== 'en';
        $wide = collect($sections)->max(fn (array $s): int => count($s['columns'])) > 6;

        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $wide ? 'A4-L' : 'A4',
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => false,
            'margin_top' => 30,
            'margin_bottom' => 18,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_header' => 8,
            'margin_footer' => 8,
            'tempDir' => $tempDir,
        ]);
        // The `directionality` config key is reset by the constructor — set it explicitly (also mirrors the margins).
        $mpdf->SetDirectionality($rtl ? 'rtl' : 'ltr');
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor((string) setting('platform.name_en', 'Saudi Council for Impact Investors'));
        $mpdf->SetCreator('SCIIS');

        $mpdf->SetHTMLHeader($this->header($title));
        $mpdf->SetHTMLFooter($this->footer($internal));
        $mpdf->WriteHTML($this->css(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($this->body($title, $meta, $sections, $summary, $rtl), \Mpdf\HTMLParserMode::HTML_BODY);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** Latin-digit number for the PDF (integers without decimals, others with up to 2). */
    public static function number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (! is_numeric($value)) {
            return (string) $value;
        }
        $n = (float) $value;

        return floor($n) === $n ? number_format($n, 0, '.', ',') : rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
    }

    private function css(): string
    {
        $navy = self::NAVY;
        $teal = self::TEAL;

        return <<<CSS
            body { font-family: dejavusans; font-size: 9pt; color: #1F2937; }
            h1 { color: {$navy}; font-size: 16pt; margin: 0 0 2mm 0; }
            h2 { color: {$navy}; font-size: 11.5pt; margin: 6mm 0 1mm 0; padding-bottom: 1mm; border-bottom: 0.5mm solid {$teal}; }
            .meta { width: 100%; border-collapse: collapse; margin: 1mm 0 3mm 0; }
            .meta td { font-size: 8pt; color: #4B5563; padding: 0.8mm 0; }
            .meta .k { color: #6B7280; width: 32mm; }
            .summary { background-color: #F1F7F6; border: 0.3mm solid #D7E9E6; padding: 3mm; margin: 2mm 0 2mm 0; font-size: 9pt; line-height: 1.6; }
            table.data { width: 100%; border-collapse: collapse; margin-top: 2mm; }
            table.data th { background-color: {$navy}; color: #FFFFFF; font-weight: bold; padding: 1.8mm 2mm; font-size: 8pt; }
            table.data td { padding: 1.5mm 2mm; border-bottom: 0.2mm solid #E5E7EB; font-size: 8pt; vertical-align: top; }
            table.data tr.alt td { background-color: #F7FAFA; }
            td.num { font-family: dejavusans; white-space: nowrap; }
            table.bars { width: 100%; border-collapse: collapse; margin-top: 2mm; }
            table.bars td { padding: 0.9mm 1mm; font-size: 8pt; }
            .note { color: #6B7280; font-size: 7.5pt; margin-top: 1.5mm; }
            .empty { color: #6B7280; font-size: 8.5pt; padding: 2mm 0; }
            CSS;
    }

    private function header(string $title): string
    {
        $logo = resource_path('brand/logo.png');
        $img = is_file($logo) ? '<img src="'.e($logo).'" style="height: 13mm;" />' : '<b style="color: '.self::NAVY.'">SCII</b>';
        $name = e((string) setting('platform.name_ar', 'المجلس السعودي للمستثمرين الاجتماعيين'));

        return '<table width="100%" style="border-bottom: 0.6mm solid '.self::TEAL.'; padding-bottom: 2mm;"><tr>'
            .'<td style="width: 50%;">'.$img.'</td>'
            .'<td style="width: 50%; text-align: '.(app()->getLocale() === 'en' ? 'right' : 'left').'; font-size: 8pt; color: '.self::NAVY.';">'.$name.'<br/><span style="color: #6B7280;">'.e($title).'</span></td>'
            .'</tr></table>';
    }

    private function footer(bool $internal): string
    {
        $stamp = e(now()->setTimezone('Asia/Riyadh')->format('Y-m-d H:i'));
        $class = $internal ? e(__('reporting.pdf.internal')) : e(__('reporting.pdf.public'));

        return '<table width="100%" style="border-top: 0.3mm solid #E5E7EB; font-size: 7.5pt; color: #6B7280;"><tr>'
            .'<td style="width: 40%;">'.$class.'</td>'
            .'<td style="width: 30%; text-align: center;">'.e(__('reporting.pdf.generated')).' '.$stamp.'</td>'
            .'<td style="width: 30%; text-align: '.(app()->getLocale() === 'en' ? 'right' : 'left').';">{PAGENO} / {nbpg}</td>'
            .'</tr></table>';
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $meta
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function body(string $title, array $meta, array $sections, ?string $summary, bool $rtl): string
    {
        $align = $rtl ? 'right' : 'left';
        $html = '<h1>'.e($title).'</h1>';

        if ($meta !== []) {
            $html .= '<table class="meta">';
            foreach ($meta as $m) {
                $html .= '<tr><td class="k">'.e($m['label']).'</td><td>'.e($m['value']).'</td></tr>';
            }
            $html .= '</table>';
        }

        if (filled($summary)) {
            $html .= '<div class="summary">'.nl2br(e((string) $summary)).'</div>';
        }

        foreach ($sections as $section) {
            if (filled($section['heading'] ?? null)) {
                $html .= '<h2>'.e((string) $section['heading']).'</h2>';
            }

            $bars = $section['bars'] ?? [];
            if ($bars !== []) {
                $max = max(1, ...array_map(fn (array $b): float => (float) $b['value'], $bars));
                $unit = (string) ($section['unit'] ?? '');
                $html .= '<table class="bars">';
                foreach ($bars as $b) {
                    $pct = max(1, (int) round(((float) $b['value'] / $max) * 100));
                    $html .= '<tr><td style="width: 30%; text-align: '.$align.';">'.e((string) $b['label']).'</td>'
                        .'<td style="width: 58%;"><table width="100%" style="border-collapse: collapse;"><tr>'
                        .'<td style="width: '.$pct.'%; background-color: '.self::TEAL.'; height: 3.2mm;"></td>'
                        .($pct < 100 ? '<td style="width: '.(100 - $pct).'%; background-color: #EEF2F6; height: 3.2mm;"></td>' : '')
                        .'</tr></table></td>'
                        .'<td class="num" style="width: 12%; text-align: center;">'.e(self::number($b['value'])).($unit !== '' ? ' '.e($unit) : '').'</td></tr>';
                }
                $html .= '</table>';
            }

            if ($section['columns'] !== []) {
                if ($section['rows'] === []) {
                    $html .= '<p class="empty">'.e(__('reporting.pdf.no_rows')).'</p>';
                } else {
                    $html .= '<table class="data"><thead><tr>';
                    foreach ($section['columns'] as $c) {
                        $html .= '<th style="text-align: '.$align.';">'.e($c).'</th>';
                    }
                    $html .= '</tr></thead><tbody>';
                    foreach (array_values($section['rows']) as $i => $row) {
                        $html .= '<tr'.($i % 2 === 1 ? ' class="alt"' : '').'>';
                        foreach ($row as $cell) {
                            $numeric = is_int($cell) || is_float($cell);
                            $html .= '<td'.($numeric ? ' class="num"' : '').' style="text-align: '.($numeric ? 'center' : $align).';">'.e($numeric ? self::number($cell) : (string) ($cell ?? '—')).'</td>';
                        }
                        $html .= '</tr>';
                    }
                    $html .= '</tbody></table>';
                }
            }

            if (filled($section['note'] ?? null)) {
                $html .= '<p class="note">'.e((string) $section['note']).'</p>';
            }
        }

        return $html;
    }
}
