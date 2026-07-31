<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Limpeza diária dos PDFs vencidos, na madrugada — a geração é pesada e não
// deve competir com o horário de pico dos pedidos.
Schedule::command('reports:prune')->dailyAt('04:00');
