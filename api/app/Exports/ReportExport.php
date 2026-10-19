<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** RPT-02 — xlsx export of a computed report. */
final class ReportExport implements FromArray, WithHeadings, WithTitle
{
    /** @param array{key: string, title: string, columns: array<int, string>, rows: array<int, array<string, mixed>>} $report */
    public function __construct(private readonly array $report) {}

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        $columns = $this->report['columns'];

        return array_map(fn (array $row): array => array_map(fn (string $c) => $this->scalar($row[$c] ?? null), $columns), $this->report['rows']);
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return array_map(fn (string $c): string => __('reports.columns.'.$c) !== 'reports.columns.'.$c ? __('reports.columns.'.$c) : $c, $this->report['columns']);
    }

    public function title(): string
    {
        return mb_substr($this->report['title'], 0, 31);
    }

    private function scalar(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}
