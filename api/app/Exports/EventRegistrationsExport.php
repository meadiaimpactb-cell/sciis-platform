<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\EventRegistration;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** MTG-04 — registrants of an event (admin export). */
final class EventRegistrationsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query->orderBy('id');
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            __('messages.events.export.name'), __('messages.events.export.email'), __('messages.events.export.phone'),
            __('messages.events.export.organization'), __('messages.events.export.status'), __('messages.events.export.member'),
            __('messages.events.export.registered_at'), __('messages.events.export.attended_at'),
        ];
    }

    /** @param EventRegistration $row @return array<int, mixed> */
    public function map($row): array
    {
        return [
            $row->name, $row->email, $row->phone, $row->organization,
            __('messages.events.statuses.'.$row->status),
            $row->user_id !== null ? __('messages.events.export.yes') : __('messages.events.export.no'),
            $row->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            $row->attended_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ];
    }
}
