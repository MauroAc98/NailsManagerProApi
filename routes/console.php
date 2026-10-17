<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('turnos:completar-vencidos')->everyFifteenMinutes();

// withoutOverlapping: una corrida lenta (muchos salones, Meta lento) no se
// pisa con la siguiente. Si una hora se saltea, el latido de EnviarRecordatorios
// la recupera en la corrida que sí corra. Expira a los 50 min para que un
// candado huérfano de una corrida caída no tape la hora siguiente.
Schedule::command('recordatorios:enviar')
    ->hourly()
    ->withoutOverlapping(50)
    ->appendOutputTo(storage_path('logs/recordatorios.log'));

Schedule::command('suscripciones:marcar-vencidas')->daily();

Schedule::command('reservas:expirar-holds')->everyMinute()->withoutOverlapping(10);

Schedule::command('pagos:reconciliar')->everyMinute()->withoutOverlapping(10);

// Los tokens Sanctum vencidos (admin y tenant) no se borran solos: sin esto
// personal_access_tokens crece sin límite.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Códigos de recuperación de contraseña del portal de profesionales: sin esto
// profesional_cuenta_reset_codes conserva las filas para siempre. Solo borra
// las que ya vencieron Y cuya espera de reenvío terminó (ver el comando).
Schedule::command('profesionales:purgar-codigos-reset')->daily();
