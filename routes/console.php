<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cadangan jika webhook GitHub belum dipasang: cek script GitHub tiap 10 menit
// (Hostinger: hPanel → Cron Jobs → "php /home/USER/.../artisan schedule:run" tiap menit)
Schedule::command('scripts:sync-github')->everyTenMinutes()->withoutOverlapping();
