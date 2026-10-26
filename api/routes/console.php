<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// ADM-03 — daily SLA reminders at 08:00 Riyadh time.
Schedule::command('sciis:sla-reminders')->dailyAt('08:00')->timezone('Asia/Riyadh')->withoutOverlapping();

// Journey 11.3 — owner completion reminders + auto-close of long-overdue completions, daily at 08:30 Riyadh time.
Schedule::command('sciis:completion-follow-up')->dailyAt('08:30')->timezone('Asia/Riyadh')->withoutOverlapping();

// PRJ-02 / PRJ-03 — execution-status refresh (+ stalled escalation) and progress-report reminders, daily at 08:45 Riyadh time.
Schedule::command('sciis:follow-up-reminders')->dailyAt('08:45')->timezone('Asia/Riyadh')->withoutOverlapping();

// OPP-07 — new-opportunity alerts (saved searches / profile match), daily at 09:00 Riyadh time.
Schedule::command('sciis:opportunity-alerts')->dailyAt('09:00')->timezone('Asia/Riyadh')->withoutOverlapping();

// INV-07 — investor profile-update reminders, daily at 09:30 Riyadh time.
Schedule::command('sciis:investor-profile-reminders')->dailyAt('09:30')->timezone('Asia/Riyadh')->withoutOverlapping();

// RPT-05 — scheduled custom reports (weekly: Sunday, monthly: the 1st), checked hourly from 07:00 Riyadh time.
Schedule::command('sciis:scheduled-reports')->hourly()->between('07:00', '20:00')->timezone('Asia/Riyadh')->withoutOverlapping();

// MSG-03 — weekly investor digest, Sunday 09:00 Riyadh time (start of the Saudi work week).
Schedule::command('sciis:investor-digest')->weeklyOn(0, '09:00')->timezone('Asia/Riyadh')->withoutOverlapping();

// PRV-04 — weekly anonymisation pass.
Schedule::command('privacy:purge')->weeklyOn(5, '02:00')->timezone('Asia/Riyadh')->withoutOverlapping();

// BKP-01 — daily database backup; weekly with the uploaded files (Friday night, Riyadh time).
Schedule::command('backup:run')->dailyAt('02:30')->timezone('Asia/Riyadh')->withoutOverlapping()->skip(fn (): bool => now('Asia/Riyadh')->isFriday());
Schedule::command('backup:run --files')->weeklyOn(5, '02:30')->timezone('Asia/Riyadh')->withoutOverlapping();

// Shared hosting has no queue daemon: with the database driver, the scheduler drains the queue every minute.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping()
    ->when(fn (): bool => config('queue.default') === 'database');

// Scheduler heartbeat — the admin system status shows a warning when cron stops running.
Schedule::call(fn () => Cache::forever('sciis.scheduler.heartbeat', now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');

// Housekeeping.
// Horizon metrics only when queues actually run on Redis (shared hosting uses the sync/database driver).
Schedule::command('horizon:snapshot')->everyFiveMinutes()->when(fn (): bool => config('queue.default') === 'redis');
Schedule::command('sanctum:prune-expired --hours=24')->daily();
