<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('portal:sync-entra-users')
    ->hourly()
    ->when(fn () => (bool) config('services.entra_sync.enabled'))
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('portal:prewarm-client-dashboards')
    ->everyTenMinutes()
    ->withoutOverlapping(5)
    ->onOneServer();
