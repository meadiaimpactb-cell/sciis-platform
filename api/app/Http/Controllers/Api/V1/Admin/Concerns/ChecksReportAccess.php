<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use App\Services\SettingsService;
use Illuminate\Http\Request;

/**
 * RPT-02/06 — one rule for every report download (KPI xlsx/PDF, custom reports, schedules): `reports.export`
 * plus a role listed in the `reports.allowed_export_roles` setting.
 */
trait ChecksReportAccess
{
    protected function assertCan(Request $request, string $permission): void
    {
        if (! $request->user()->can($permission)) {
            abort(403, __('messages.errors.forbidden'));
        }
    }

    protected function canExport(Request $request): bool
    {
        $user = $request->user();
        $allowed = app(SettingsService::class)->array('reports.allowed_export_roles', ['super_admin', 'reports_viewer']);

        return $user->can('reports.export') && $user->hasAnyRole($allowed);
    }

    protected function assertCanExport(Request $request): void
    {
        $this->assertCan($request, 'reports.export');
        if (! $this->canExport($request)) {
            abort(403, __('messages.reports.export_role'));
        }
    }
}
