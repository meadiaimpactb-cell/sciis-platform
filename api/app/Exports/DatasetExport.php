<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** RPT-04 / ADM-08 — xlsx of a custom-report (dataset) result: labelled headings, display-ready values. */
final class DatasetExport implements FromArray, WithHeadings, WithTitle
{
    /** @param array{title: string, columns: array<int, array{key: string, label: string}>, rows: array<int, array<string, mixed>>} $result */
    public function __construct(private readonly array $result, private readonly ?string $sheetTitle = null) {}

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        $keys = array_column($this->result['columns'], 'key');

        return array_map(fn (array $row): array => array_map(fn (string $k) => is_array($row[$k] ?? null) ? json_encode($row[$k], JSON_UNESCAPED_UNICODE) : ($row[$k] ?? null), $keys), $this->result['rows']);
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return array_column($this->result['columns'], 'label');
    }

    public function title(): string
    {
        // Excel sheet names: max 31 chars, no []:*?/\ characters.
        return mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $this->sheetTitle ?? $this->result['title']), 0, 31);
    }
}
