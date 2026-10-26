<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InfoRequestStatus;
use App\Enums\MembershipStatus;
use App\Models\InfoRequest;
use App\Models\Nomination;
use App\Models\User;
use App\Notifications\WeeklyDigestNotification;
use App\Services\NotificationService;
use App\Services\OpportunityAlertService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Traits\Localizable;

/**
 * MSG-03 — weekly e-mail digest for approved investors (`notifications.weekly_digest`): new opportunities of the last
 * seven days matching their saved searches or profile, nominations awaiting their reply (+ new this week) and their
 * information requests still awaiting an answer. Skipped for investors who switched the `digest` e-mail off and when
 * there is nothing to report.
 */
class SendInvestorDigestCommand extends Command
{
    use Localizable;

    protected $signature = 'sciis:investor-digest {--dry-run : Only list who would receive a digest}';

    protected $description = 'Send the weekly digest to approved investors (MSG-03)';

    public function handle(SettingsService $settings, OpportunityAlertService $alerts, NotificationService $notifications): int
    {
        if (! $settings->bool('notifications.weekly_digest', true)) {
            $this->info('Weekly digest is off (notifications.weekly_digest).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $since = now()->subDays(7);
        $candidates = $alerts->newlyListed($since);
        $row = fn ($o): array => ['uuid' => $o->uuid, 'name' => $o->name, 'name_en' => $o->name_en];

        $investors = User::query()->investors()->active()
            ->whereHas('membership', fn ($m) => $m->where('status', MembershipStatus::Approved->value)->whereNotNull('terms_accepted_at'))
            ->orderBy('id')
            ->get();

        $sent = 0;
        foreach ($investors as $investor) {
            if (! in_array('mail', $notifications->channelsFor($investor, 'digest'), true)) {
                continue;
            }

            $locale = feature('english') ? $investor->preferredLocale() : 'ar';
            $matches = $this->withLocale($locale, fn (): array => $alerts->matchesFor($investor, $candidates, false));
            $pending = Nomination::query()->with('opportunity')->where('investor_user_id', $investor->id)->whereNull('response')->whereHas('opportunity')->orderByDesc('sent_at')->get();
            $digest = [
                'matches' => array_map(fn (array $m): array => $row($m['opportunity']), $matches),
                'nominations_pending' => $pending->map(fn (Nomination $n): array => $row($n->opportunity))->values()->all(),
                'nominations_new' => Nomination::query()->where('investor_user_id', $investor->id)->where('sent_at', '>=', $since)->count(),
                'info_requests_open' => InfoRequest::query()->where('investor_user_id', $investor->id)
                    ->whereIn('status', [InfoRequestStatus::Sent->value, InfoRequestStatus::Processing->value])->count(),
            ];
            if ($digest['matches'] === [] && $digest['nominations_pending'] === [] && $digest['nominations_new'] === 0 && $digest['info_requests_open'] === 0) {
                continue;
            }

            $this->line(sprintf('digest  %s — %d matches, %d pending nominations, %d open requests', $investor->email, count($digest['matches']), count($digest['nominations_pending']), $digest['info_requests_open']));
            if ($dryRun) {
                continue;
            }

            $notifications->send($investor, new WeeklyDigestNotification($digest));
            $sent++;
        }

        $this->info(sprintf('%s%d digests.', $dryRun ? '[dry-run] ' : '', $sent));

        return self::SUCCESS;
    }
}
