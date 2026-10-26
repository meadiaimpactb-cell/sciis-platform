<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Models\InvestorProfile;
use App\Notifications\ProfileUpdateReminderNotification;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INV-07 — daily: reminds approved investors whose profile was not updated for `investor.profile_reminder_days`
 * days (0 = off), at most once per such period (`investor_profiles.reminder_last_sent_at`). Updating the profile
 * moves `updated_at` (InvestorProfileService touches it), which restarts the clock.
 */
class SendProfileRemindersCommand extends Command
{
    protected $signature = 'sciis:investor-profile-reminders {--dry-run : Only list who would be reminded}';

    protected $description = 'Remind investors whose profile was not updated for a long time (INV-07)';

    public function handle(SettingsService $settings, NotificationService $notifications, AuditLogger $audit): int
    {
        $days = max(0, $settings->int('investor.profile_reminder_days', 180));
        if ($days === 0) {
            $this->info('Profile reminders are off (investor.profile_reminder_days = 0).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $profiles = InvestorProfile::query()
            ->with('user')
            ->where('updated_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNull('reminder_last_sent_at')->orWhere('reminder_last_sent_at', '<=', $cutoff))
            ->whereHas('user', fn ($u) => $u->investors()->active()->whereHas('membership', fn ($m) => $m->where('status', MembershipStatus::Approved->value)->whereNotNull('terms_accepted_at')))
            ->orderBy('id')
            ->get();

        foreach ($profiles as $profile) {
            $this->line(sprintf('remind  %s — profile updated %s', $profile->user->email, $profile->updated_at?->toDateString()));
            if ($dryRun) {
                continue;
            }

            $notifications->send($profile->user, new ProfileUpdateReminderNotification($days, $profile->updated_at));
            // Query-builder update: leaves `updated_at` (the "last profile update") untouched.
            DB::table('investor_profiles')->where('id', $profile->id)->update(['reminder_last_sent_at' => now()]);
            $audit->log('investor_profile.reminder_sent', $profile, null, ['days' => $days], null);
        }

        $this->info(sprintf('%s%d reminders.', $dryRun ? '[dry-run] ' : '', $profiles->count()));

        return self::SUCCESS;
    }
}
