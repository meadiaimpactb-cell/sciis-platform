<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Models\OpportunityAlert;
use App\Models\SavedFilter;
use App\Models\User;
use App\Notifications\OpportunityAlertNotification;
use App\Services\NotificationService;
use App\Services\OpportunityAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;

/**
 * OPP-07 — daily: tells each approved investor about newly listed opportunities that match one of their saved
 * searches with alerts on, or their profile when the "matches my profile" switch is on. One notification per investor
 * per run (in-app + e-mail per the `alerts` preference); each opportunity is announced to an investor only once
 * (`opportunity_alerts`). The look-back window lets a missed run catch up without repeating anything.
 */
class SendOpportunityAlertsCommand extends Command
{
    use Localizable;

    protected $signature = 'sciis:opportunity-alerts {--days=7 : Look-back window for newly listed opportunities} {--dry-run : Only list what would be sent}';

    protected $description = 'Alert investors to newly listed opportunities matching their saved searches or profile (OPP-07)';

    public function handle(OpportunityAlertService $alerts, NotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $candidates = $alerts->newlyListed($since);

        if ($candidates->isEmpty()) {
            $this->info('No newly listed opportunities.');

            return self::SUCCESS;
        }

        $investors = User::query()->investors()->active()
            ->whereHas('membership', fn ($m) => $m->where('status', MembershipStatus::Approved->value)->whereNotNull('terms_accepted_at'))
            ->whereIn('id', SavedFilter::query()->where('notify', true)->select('user_id'))
            ->orderBy('id')
            ->get();

        $sent = 0;
        $total = 0;
        foreach ($investors as $investor) {
            $locale = feature('english') ? $investor->preferredLocale() : 'ar';
            $matches = $this->withLocale($locale, fn (): array => $alerts->matchesFor($investor, $candidates));
            if ($matches === []) {
                continue;
            }

            $this->line(sprintf('alert   %s — %d opportunities', $investor->email, count($matches)));
            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($investor, $matches): void {
                $now = now();
                OpportunityAlert::query()->insertOrIgnore(array_map(fn (array $m): array => [
                    'user_id' => $investor->id,
                    'opportunity_id' => $m['opportunity']->id,
                    'saved_filter_id' => $m['saved_filter_id'],
                    'source' => $m['source'],
                    'sent_at' => $now,
                ], $matches));
            });
            $notifications->send($investor, new OpportunityAlertNotification(OpportunityAlertService::items($matches)));
            $sent++;
            $total += count($matches);
        }

        $this->info(sprintf('%s%d investors alerted about %d opportunities.', $dryRun ? '[dry-run] ' : '', $sent, $total));

        return self::SUCCESS;
    }
}
