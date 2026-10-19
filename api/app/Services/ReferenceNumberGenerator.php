<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Opportunity;
use Illuminate\Support\Facades\DB;

/**
 * ENT-03 — reference numbers `A-YYYY-NNNN`, sequential per year, allocated under a DB lock
 * so concurrent submissions never collide.
 */
class ReferenceNumberGenerator
{
    public function next(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = sprintf('A-%d-', $year);

        return DB::transaction(function () use ($prefix): string {
            $query = Opportunity::withTrashed()
                ->where('reference_no', 'like', $prefix.'%')
                ->orderByDesc('reference_no');

            if (DB::getDriverName() === 'pgsql') {
                $query->lockForUpdate();
            }

            $last = $query->value('reference_no');
            $seq = $last ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

            return sprintf('%s%04d', $prefix, $seq);
        });
    }

    /** Reference numbers for contact messages: C-YYYY-NNNNNN. */
    public function nextContact(): string
    {
        $prefix = sprintf('C-%s-', now()->format('Y'));

        return DB::transaction(function () use ($prefix): string {
            $query = DB::table('contact_messages')->where('reference_no', 'like', $prefix.'%')->orderByDesc('reference_no');
            if (DB::getDriverName() === 'pgsql') {
                $query->lockForUpdate();
            }
            $last = $query->value('reference_no');
            $seq = $last ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

            return sprintf('%s%06d', $prefix, $seq);
        });
    }
}
