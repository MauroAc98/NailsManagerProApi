<?php

namespace Tests\Feature;

use App\Models\ReservaReputacion;
use App\Models\ReservaWeb;
use App\Services\Reservas\DisponibilidadService;
use App\Services\Reservas\ExpirarHoldsService;
use App\Services\Reservas\ReputacionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class ExpirarHoldsTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private const AHORA = 1_800_000_000;

    private function hold(array $attrs = []): ReservaWeb
    {
        $user = $this->crearSalon();
        $prof = $this->crearProfesional($user);

        return ReservaWeb::create(array_merge([
            'user_id' => $user->id,
            'profesional_id' => $prof->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [1],
            'fecha' => '2099-06-11',
            'slot_hora' => '10:00:00',
            'duracion_total_minutos' => 60,
            'estado' => 'held',
            'expira_en' => self::AHORA - 10,
        ], $attrs));
    }

    public function test_marca_vencidos_held_y_pending_payment_y_deja_los_vivos(): void
    {
        $vencido = $this->hold();
        $vencidoPago = $this->hold(['estado' => 'pending_payment', 'expira_en' => self::AHORA]);
        $vivo = $this->hold(['expira_en' => self::AHORA + 1]);
        $confirmado = $this->hold(['estado' => 'confirmed', 'expira_en' => self::AHORA - 500]);

        $n = (new ExpirarHoldsService(new ReputacionService()))->expirarVencidos(self::AHORA);

        $this->assertSame(2, $n);
        $this->assertSame('expired', $vencido->fresh()->estado);
        $this->assertSame('vencido', $vencido->fresh()->motivo_cierre);
        $this->assertSame('expired', $vencidoPago->fresh()->estado);
        $this->assertSame('held', $vivo->fresh()->estado);
        $this->assertSame('confirmed', $confirmado->fresh()->estado);
    }

    public function test_es_idempotente_y_no_duplica_la_reputacion(): void
    {
        $rep = new ReputacionService();
        $this->hold(['device_hash' => $rep->hashDevice('d1'), 'telefono' => '+5493765252395']);
        $svc = new ExpirarHoldsService($rep);

        $this->assertSame(1, $svc->expirarVencidos(self::AHORA));
        $this->assertSame(0, $svc->expirarVencidos(self::AHORA + 60));

        $this->assertSame(1, ReservaReputacion::where('kind', 'device')->value('expirados_sin_pago'));
        $this->assertSame(1, ReservaReputacion::where('kind', 'phone')->value('expirados_sin_pago'));
    }

    public function test_aplica_reputacion_de_dispositivo_y_telefono_con_cooldown(): void
    {
        $rep = new ReputacionService();
        $r = $this->hold(['device_hash' => $rep->hashDevice('d1'), 'telefono' => '+5493765252395']);

        (new ExpirarHoldsService($rep))->expirarVencidos(self::AHORA);

        $this->assertSame(1800, $rep->cooldownRestante($r->user_id, $rep->hashTelefono('+5493765252395'), self::AHORA));
        $this->assertSame(2, ReservaReputacion::count());
    }

    public function test_sin_datos_solo_penaliza_al_dispositivo_y_sin_dispositivo_no_penaliza(): void
    {
        $rep = new ReputacionService();
        $this->hold(['device_hash' => $rep->hashDevice('d1')]);
        $this->hold(['slot_hora' => '11:00:00']);

        (new ExpirarHoldsService($rep))->expirarVencidos(self::AHORA);

        $this->assertSame(1, ReservaReputacion::count());
        $this->assertSame('device', ReservaReputacion::value('kind'));
    }

    public function test_un_hold_vencido_nunca_bloquea_aunque_el_job_no_haya_corrido(): void
    {
        $r = $this->hold(['expira_en' => self::AHORA - 1]);
        $this->assertSame('held', $r->estado);

        $libre = (new DisponibilidadService())->estaLibre($r->profesional_id, '2099-06-11', '10:00', 60, Carbon::createFromTimestamp(self::AHORA));

        $this->assertTrue($libre);
    }

    public function test_el_comando_expira_y_esta_agendado_cada_minuto_sin_solaparse(): void
    {
        $this->hold(['expira_en' => now()->timestamp - 5]);

        $this->artisan('reservas:expirar-holds')->assertSuccessful();

        $this->assertSame('expired', ReservaWeb::first()->estado);

        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'reservas:expirar-holds'));
        $this->assertNotNull($evento, 'falta la entrada en el scheduler');
        $this->assertSame('* * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
    }

    public function test_expirar_por_dispositivo_solo_toca_los_de_ese_dispositivo(): void
    {
        $rep = new ReputacionService();
        $mio = $this->hold(['device_hash' => $rep->hashDevice('d1')]);
        $otro = $this->hold(['device_hash' => $rep->hashDevice('d2'), 'slot_hora' => '12:00:00']);

        (new ExpirarHoldsService($rep))->expirarVencidos(self::AHORA, $rep->hashDevice('d1'));

        $this->assertSame('expired', $mio->fresh()->estado);
        $this->assertSame('held', $otro->fresh()->estado);
    }
}
