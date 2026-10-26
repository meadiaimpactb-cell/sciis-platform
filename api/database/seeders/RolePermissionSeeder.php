<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** SPEC §1.2 — staff roles and fine-grained permissions (guard `api`). */
class RolePermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        'opportunities.view', 'opportunities.transition',
        'evaluations.assign', 'evaluations.score', 'evaluations.approve',
        'memberships.review', 'memberships.approve',
        'nominations.manage', 'teasers.approve', 'meetings.manage',
        'agreements.manage', 'agreements.approve', 'impact.verify',
        'reports.view', 'reports.export',
        'reports.approve', // journey 11.10 — approve & publish periodic reports (super_admin via '*')
        'settings.manage', 'users.manage', 'audit.view',
    ];

    /** @var array<string, array<int, string>> */
    public const ROLES = [
        'super_admin' => ['*'],
        'membership_officer' => ['memberships.review', 'memberships.approve', 'reports.view'],
        'reviewer' => ['opportunities.view', 'opportunities.transition', 'evaluations.assign', 'reports.view'],
        'evaluator' => ['opportunities.view', 'evaluations.score'],
        'matching_officer' => ['opportunities.view', 'nominations.manage', 'teasers.approve', 'meetings.manage', 'reports.view'],
        'agreements_officer' => ['opportunities.view', 'agreements.manage'],
        'impact_officer' => ['opportunities.view', 'impact.verify', 'reports.view'],
        'advisory_member' => ['opportunities.view'],
        'reports_viewer' => ['reports.view', 'reports.export'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'api');
        }

        foreach (self::ROLES as $role => $permissions) {
            $r = Role::findOrCreate($role, 'api');
            $r->syncPermissions($permissions === ['*'] ? self::PERMISSIONS : $permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
