<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Trial vencido vira `past_due` uma vez por dia. De madrugada e não no pico:
// o comando só marca status, mas não há motivo para competir com o almoço.
Schedule::command('trials:expire')->dailyAt('04:30');
