<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// ADM-03 — daily SLA reminders at 08:00 Riyadh time.
Schedule::command('sciis:sla-reminders')->dailyAt('08:00')->timezone('Asia/Riyadh')->withoutOverlapping();

// PRV-04 — weekly anonymisation pass.
Schedule::command('privacy:purge')->weeklyOn(5, '02:00')->timezone('Asia/Riyadh')->withoutOverlapping();

// Housekeeping.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
