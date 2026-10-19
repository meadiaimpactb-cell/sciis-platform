<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

final class PaymentsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query->with('user')->orderByDesc('id');
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return ['uuid', 'user', 'purpose', 'amount', 'currency', 'status', 'gateway_payment_id', 'paid_at', 'created_at'];
    }

    /** @param Payment $row @return array<int, mixed> */
    public function map($row): array
    {
        return [
            $row->uuid, $row->user?->name, $row->purpose->label(), (float) $row->amount, $row->currency, $row->status->label(),
            $row->gateway_payment_id, $row->paid_at?->toDateTimeString(), $row->created_at?->toDateTimeString(),
        ];
    }
}
