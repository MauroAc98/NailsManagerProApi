<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('turnos:completar-vencidos')->everyFifteenMinutes();

Schedule::command('recordatorios:enviar')
    ->hourly()
    ->appendOutputTo(storage_path('logs/recordatorios.log'));

Schedule::command('whatsapp:reenviar-pendientes')
    ->everyTenMinutes()
    ->appendOutputTo(storage_path('logs/whatsapp_reenvios.log'));