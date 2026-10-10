<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fire due-soon reminders hourly so a card entering its 24h window is caught promptly.
Schedule::command('notifications:due-reminders')->hourly();

// Expired Sanctum tokens are rejected at auth time but linger in the table — sweep daily.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Re-engage idle WhatsApp leads and drop out the unresponsive ones, once a day.
Schedule::command('whatsapp:reengage')->daily();

// Vortex MK-V: his life goes on while you're away (needs, sleep, agenda, the Rewinder).
Schedule::command('vortex:life-tick')->hourly();

// Q-01/Q-09 · the weekly Void Report (email / Slack), opt-in only
Schedule::command('vortex:outside-weekly')->weeklyOn(1, '09:00');

// Q-03 · due reminders and 03:13 pages as real push (opt-in, ≤ 1 a day)
Schedule::command('vortex:push-tick')->everyMinute()->withoutOverlapping();
