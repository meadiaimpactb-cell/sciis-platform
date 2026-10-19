<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PRV-04 — retention: anonymise (never hard-delete) personal data past the retention windows in settings:
 *   privacy.retention_days.rejected  → rejected memberships' users
 *   privacy.retention_days.withdrawn → withdrawn / not_eligible opportunities (confidential text fields)
 *   privacy.retention_days.inactive  → users without login for N days and no active relations
 * Audit logs are never touched (AUD-02).
 */
class PurgeRetentionCommand extends Command
{
    protected $signature = 'privacy:purge {--dry-run : Report without changing data}';

    protected $description = 'Anonymise personal data past the retention windows (PRV-04)';

    public function handle(SettingsService $settings, AuditLogger $audit): int
    {
        $dry = (bool) $this->option('dry-run');
        $rejectedDays = $settings->int('privacy.retention_days.rejected', 365);
        $withdrawnDays = $settings->int('privacy.retention_days.withdrawn', 365);
        $inactiveDays = $settings->int('privacy.retention_days.inactive', 730);

        $counts = ['rejected_users' => 0, 'withdrawn_opportunities' => 0, 'inactive_users' => 0];

        // 1. Rejected memberships.
        $rejected = User::query()->investors()->whereNull('anonymized_at')
            ->whereHas('membership', fn ($m) => $m->where('status', MembershipStatus::Rejected->value)->where('reviewed_at', '<', now()->subDays($rejectedDays)))
            ->get();
        foreach ($rejected as $user) {
            $counts['rejected_users']++;
            if (! $dry) {
                $this->anonymiseUser($user, $audit, 'rejected');
            }
        }

        // 2. Withdrawn / not eligible opportunities.
        $withdrawn = Opportunity::query()->whereNull('anonymized_at')
            ->whereIn('status', [OpportunityStatus::Withdrawn->value, OpportunityStatus::NotEligible->value])
            ->where('updated_at', '<', now()->subDays($withdrawnDays))
            ->get();
        foreach ($withdrawn as $opportunity) {
            $counts['withdrawn_opportunities']++;
            if (! $dry) {
                $this->anonymiseOpportunity($opportunity, $audit);
            }
        }

        // 3. Inactive users with no live relations.
        $inactive = User::query()->whereNull('anonymized_at')->where('type', '!=', 'staff')
            ->where(fn ($q) => $q->where('last_login_at', '<', now()->subDays($inactiveDays))->orWhere(fn ($w) => $w->whereNull('last_login_at')->where('created_at', '<', now()->subDays($inactiveDays))))
            ->whereDoesntHave('opportunities', fn ($o) => $o->whereNotIn('status', [OpportunityStatus::Withdrawn->value, OpportunityStatus::Closed->value, OpportunityStatus::NotEligible->value, OpportunityStatus::Draft->value]))
            ->whereDoesntHave('interests', fn ($i) => $i->open())
            ->get();
        foreach ($inactive as $user) {
            $counts['inactive_users']++;
            if (! $dry) {
                $this->anonymiseUser($user, $audit, 'inactive');
            }
        }

        $this->table(array_keys($counts), [array_values($counts)]);
        if (! $dry) {
            $audit->log('privacy.purge', null, null, $counts);
        }

        return self::SUCCESS;
    }

    private function anonymiseUser(User $user, AuditLogger $audit, string $reason): void
    {
        DB::transaction(function () use ($user, $audit, $reason): void {
            $token = Str::lower(Str::random(12));
            $before = ['email' => hash('sha256', $user->email), 'phone' => $user->phone ? hash('sha256', $user->phone) : null];

            $user->forceFill([
                'name' => __('messages.privacy.anonymized_user'),
                'email' => "anon-{$token}@anonymized.invalid",
                'phone' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'status' => 'suspended',
                'anonymized_at' => now(),
            ])->save();

            $user->tokens()->delete();
            $user->deviceTokens()->delete();
            $user->investorProfile()->update([
                'linkedin_url' => null, 'employer' => null, 'experience_desc' => null, 'prior_investments_desc' => null,
                'social_investments_desc' => null, 'mentoring_areas' => null, 'conflicts_disclosure' => null, 'accredited_declaration' => null,
            ]);

            $audit->log('privacy.user_anonymized', $user, $before, ['reason' => $reason]);
        });
    }

    private function anonymiseOpportunity(Opportunity $opportunity, AuditLogger $audit): void
    {
        DB::transaction(function () use ($opportunity, $audit): void {
            $opportunity->forceFill([
                'use_of_funds' => null,
                'prior_funding_desc' => null,
                'legal_disputes_desc' => null,
                'operational_risks' => null,
                'council_relationship_desc' => null,
                'funding_amount' => null,
                'anonymized_at' => now(),
            ])->save();

            $opportunity->documents()->delete(); // soft delete; storage lifecycle policy removes blobs
            $opportunity->internalNotes()->delete();

            $audit->log('privacy.opportunity_anonymized', $opportunity, null, ['status' => $opportunity->status->value]);
        });
    }
}
