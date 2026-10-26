<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/** AUD-03 — xlsx export of the (filtered) audit log. Chunked through FromQuery so large logs stream. */
final class AuditLogExport implements FromQuery, WithHeadings, WithMapping, WithTitle
{
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query->with('user');
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['uuid', 'created_at', 'user_id', 'user_name', 'action', 'auditable_type', 'auditable_id', 'before', 'after', 'ip', 'request_id'];
    }

    /** @param AuditLog $log @return array<int, mixed> */
    public function map($log): array
    {
        return [
            $log->uuid,
            $log->created_at?->toIso8601String(),
            $log->user_id,
            $log->user?->name,
            $log->action,
            $log->auditable_type,
            $log->auditable_id,
            $log->before !== null ? json_encode($log->before, JSON_UNESCAPED_UNICODE) : null,
            $log->after !== null ? json_encode($log->after, JSON_UNESCAPED_UNICODE) : null,
            $log->ip,
            $log->request_id,
        ];
    }

    public function title(): string
    {
        return 'audit_logs';
    }
}
