<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tickets:auto-close')->hourly()->withoutOverlapping();
Schedule::command('tickets:remind-confirmations')->twiceDaily(9, 21);
// Start each day's master list message even before the first ticket changes.
Schedule::command('tickets:telegram-list')->dailyAt('00:01');
Schedule::command('db:backup')->dailyAt('23:30')->withoutOverlapping();
// The bell only shows the latest few; read notices older than a month are just weight.
Schedule::call(fn () => DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays(30))->delete())
    ->daily()->name('prune-read-notifications');
