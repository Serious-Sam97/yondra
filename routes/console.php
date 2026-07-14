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
