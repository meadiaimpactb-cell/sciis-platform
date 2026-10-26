<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * ADM-07 — approval-authority matrix: every council decision against the roles that can take it, derived from the
 * LIVE role → permission assignments (editing a role in the roles screen changes the matrix immediately).
 *
 * Each decision lists the permissions the API checks for it (`all` = every one required, `any` = one is enough).
 * Report export is additionally restricted to the roles in the `reports.allowed_export_roles` setting.
 */
class ApprovalMatrix
{
    /** @var array<string, array{area: string, all?: array<int, string>, any?: array<int, string>, roles_setting?: string}> */
    public const DECISIONS = [
        'membership_approve' => ['area' => 'membership', 'all' => ['memberships.approve']],
        'membership_reject' => ['area' => 'membership', 'all' => ['memberships.approve']],
        'membership_suspend' => ['area' => 'membership', 'all' => ['memberships.approve']],
        'membership_request_completion' => ['area' => 'membership', 'all' => ['memberships.review']],
        'opportunity_assign_reviewer' => ['area' => 'opportunity', 'any' => ['opportunities.transition', 'evaluations.assign']],
        'opportunity_screening' => ['area' => 'opportunity', 'all' => ['opportunities.transition']],
        'opportunity_completion_request' => ['area' => 'opportunity', 'all' => ['opportunities.transition']],
        'opportunity_document_review' => ['area' => 'opportunity', 'all' => ['opportunities.transition']],
        'opportunity_eligible' => ['area' => 'opportunity', 'all' => ['opportunities.transition']],
        'opportunity_not_eligible' => ['area' => 'opportunity', 'all' => ['opportunities.transition', 'evaluations.approve']],
        'opportunity_close' => ['area' => 'opportunity', 'all' => ['opportunities.transition']],
        'evaluation_assign' => ['area' => 'evaluation', 'all' => ['evaluations.assign']],
        'evaluation_score' => ['area' => 'evaluation', 'all' => ['evaluations.score']],
        'evaluation_decide' => ['area' => 'evaluation', 'all' => ['evaluations.approve']],
        'teaser_approve' => ['area' => 'matching', 'all' => ['teasers.approve']],
        'nomination_send' => ['area' => 'matching', 'all' => ['nominations.manage']],
        'interest_connect' => ['area' => 'matching', 'all' => ['nominations.manage']],
        'meeting_manage' => ['area' => 'matching', 'all' => ['meetings.manage']],
        'agreement_manage' => ['area' => 'execution', 'all' => ['agreements.manage']],
        'agreement_approve' => ['area' => 'execution', 'all' => ['agreements.approve']],
        'impact_verify' => ['area' => 'execution', 'all' => ['impact.verify']],
        'report_export' => ['area' => 'platform', 'all' => ['reports.export'], 'roles_setting' => 'reports.allowed_export_roles'],
        'audit_view' => ['area' => 'platform', 'all' => ['audit.view']],
        'users_manage' => ['area' => 'platform', 'all' => ['users.manage']],
        'settings_manage' => ['area' => 'platform', 'all' => ['settings.manage']],
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  Collection<int, Role>  $roles  with `permissions` loaded
     * @param  array<int, int>  $userCounts  role id → users
     * @return array<int, array<string, mixed>>
     */
    public function rows(Collection $roles, array $userCounts = []): array
    {
        $granted = $roles->mapWithKeys(fn (Role $r): array => [$r->name => $r->permissions->pluck('name')->all()]);

        $rows = [];
        foreach (self::DECISIONS as $key => $rule) {
            $all = $rule['all'] ?? [];
            $any = $rule['any'] ?? [];
            $allowedBySetting = isset($rule['roles_setting']) ? $this->settings->array($rule['roles_setting'], []) : null;

            $holders = [];
            $excluded = [];
            foreach ($roles as $role) {
                $perms = $granted[$role->name] ?? [];
                $holds = array_diff($all, $perms) === [] && ($any === [] || array_intersect($any, $perms) !== []);
                if (! $holds) {
                    continue;
                }
                $entry = ['name' => $role->name, 'users_count' => (int) ($userCounts[$role->id] ?? 0)];
                if ($allowedBySetting !== null && ! in_array($role->name, $allowedBySetting, true)) {
                    $excluded[] = $entry;

                    continue;
                }
                $holders[] = $entry;
            }

            $rows[] = [
                'key' => $key,
                'area' => $rule['area'],
                'permissions' => $all !== [] ? $all : $any,
                'mode' => $all !== [] ? 'all' : 'any',
                'roles' => $holders,
                'roles_setting' => $rule['roles_setting'] ?? null,
                'excluded_roles' => $excluded,
            ];
        }

        return $rows;
    }
}
