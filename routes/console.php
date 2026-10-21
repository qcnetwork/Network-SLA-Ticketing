<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tickets:auto-close')->hourly()->withoutOverlapping();
Schedule::command('tickets:remind-confirmations')->twiceDaily(9, 21);
// Start each day's master list message even before the first ticket changes.
Schedule::command('tickets:telegram-list')->dailyAt('00:01');
Schedule::command('db:backup')->dailyAt('23:30')->withoutOverlapping();
