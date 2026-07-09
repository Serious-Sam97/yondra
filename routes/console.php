<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fire due-soon reminders hourly so a card entering its 24h window is caught promptly.
Schedule::command('notifications:due-reminders')->hourly();
