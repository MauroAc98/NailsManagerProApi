<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\TurnoGrupo;
use App\Models\User;
use App\Models\WhatsappMensaje;
use App\Services\CloudApiService;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * The confirmation job holds an atomic Cache::lock per sending unit (lote,
 * grupo or single turno + tipo) from the dedup check until the
 * WhatsappMensaje row exists, so two concurrent jobs of the same unit can never
 * both send. Nothing goes out to the network: Http::fake().
 */
class EnviarMensajeConfirmacionLockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cliente $cliente;

    private Servicio $mani;

    private Profesional $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(CloudApiService::CACHE_KEY_SALUD);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.K1']]], 200)]);
        $this->user = User::factory()->create([
            'is_exempt' => true, 'telefono' => '+543765111111', 'confirmacion_automatica' => true,
        ]);
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Marta', 'apellido' => 'Rios', 'telefono' => '+543765252395']);
        $this->mani = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
        $this->ana = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
    }

    private function turno(int $dias, ?string $loteId = null, ?int $grupoId = null): Turno
    {
        $t = Turno::create([
            'user_id' => $this->user->id, 'cliente_id' => $this->cliente->id, 'profesional_id' => $this->ana->id,
            'lote_id' => $loteId, 'grupo_id' => $grupoId,
            'fecha_hora' => now()->addDays($dias)->setTime(15, 30)->format('Y-m-d H:i:s'),
            'duracion_total_minutos' => 30, 'estado' => 'confirmado', 'origen' => 'app',
        ]);
        $t->servicios()->attach($this->mani->id);

        return $t;
    }

    private function correr(Turno $t, bool $porTurno = false): void
    {
        (new EnviarMensajeConfirmacion($t->id, 'confirmacion', $porTurno))->handle(app(CloudApiService::class));
    }

    private function claveLote(string $loteId): string
    {
        return "wa-confirmacion:lote:{$loteId}:confirmacion";
    }

    private function claveGrupo(int $grupoId): string
    {
        return "wa-confirmacion:grupo:{$grupoId}:confirmacion";
    }

    private function claveTurno(Turno $t): string
    {
        return "wa-confirmacion:turno:{$t->id}:confirmacion";
    }

    private function tomar(string $clave): \Illuminate\Contracts\Cache\Lock
    {
        $lock = Cache::lock($clave, 60);
        $this->assertTrue($lock->get(), 'the test could not take the lock');

        return $lock;
    }

    public function test_con_el_lock_de_la_unidad_tomado_no_envia_ni_registra_y_se_libera_para_reintento(): void
    {
        $lote = (string) Str::uuid();
        $a = $this->turno(2, $lote);
        $this->turno(4, $lote);
        $this->tomar($this->claveLote($lote));

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('release')->once()->with(15);
        $job = new EnviarMensajeConfirmacion($a->id);
        $job->setJob($queueJob);

        $job->handle(app(CloudApiService::class));

        Http::assertNothingSent();
        $this->assertSame(0, WhatsappMensaje::count());
    }

    public function test_sin_job_de_cola_y_con_el_lock_tomado_retorna_sin_enviar(): void
    {
        $a = $this->turno(2);
        $this->tomar($this->claveTurno($a));

        $this->correr($a);

        Http::assertNothingSent();
        $this->assertSame(0, WhatsappMensaje::count());
    }

    public function test_al_soltar_el_lock_envia_una_sola_vez_y_una_segunda_ejecucion_no_duplica(): void
    {
        $lote = (string) Str::uuid();
        $a = $this->turno(2, $lote);
        $this->turno(4, $lote);
        $lock = $this->tomar($this->claveLote($lote));
        $this->correr($a);
        Http::assertNothingSent();

        $lock->release();
        $this->correr($a);
        $this->correr($a);

        Http::assertSentCount(1);
        $this->assertSame(1, WhatsappMensaje::count());
    }

    public function test_el_lock_se_libera_si_el_envio_lanza_excepcion(): void
    {
        $a = $this->turno(2);
        Http::fake(['graph.facebook.com/*' => fn () => throw new ConnectionException('boom')]);

        try {
            $this->correr($a);
            $this->fail('the exception should propagate');
        } catch (ConnectionException) {
            // expected
        }

        $lock = Cache::lock($this->claveTurno($a), 60);
        $this->assertTrue($lock->get(), 'the lock was left taken after the failure');
        $lock->release();
        $this->assertSame(0, WhatsappMensaje::count());
    }

    public function test_el_lock_se_libera_tras_un_envio_exitoso(): void
    {
        $a = $this->turno(2);

        $this->correr($a);

        $lock = Cache::lock($this->claveTurno($a), 60);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_dos_turnos_del_mismo_lote_comparten_lock_y_lotes_distintos_no(): void
    {
        $lote = (string) Str::uuid();
        $a = $this->turno(2, $lote);
        $b = $this->turno(4, $lote);
        $this->tomar($this->claveLote($lote));

        $this->correr($b);
        Http::assertNothingSent();
        $this->assertSame(0, WhatsappMensaje::count());

        $otroLote = (string) Str::uuid();
        $c = $this->turno(6, $otroLote);
        $this->turno(8, $otroLote);
        $this->correr($c);

        Http::assertSentCount(1);
        $this->assertSame($c->id, WhatsappMensaje::first()->turno_id);
    }

    public function test_jobs_por_turno_de_un_lote_no_se_bloquean_entre_si(): void
    {
        $lote = (string) Str::uuid();
        $a = $this->turno(2, $lote);
        $b = $this->turno(4, $lote);
        $this->tomar($this->claveTurno($a));

        $this->correr($a, true);
        Http::assertNothingSent();

        $this->correr($b, true);

        Http::assertSentCount(1);
        $this->assertSame($b->id, WhatsappMensaje::first()->turno_id);
    }

    public function test_dos_tramos_del_mismo_grupo_comparten_lock(): void
    {
        $grupo = TurnoGrupo::create(['modo' => 'secuencia']);
        $this->turno(2, null, $grupo->id);
        $b = $this->turno(2, null, $grupo->id);
        $this->tomar($this->claveGrupo($grupo->id));

        $this->correr($b);

        Http::assertNothingSent();
        $this->assertSame(0, WhatsappMensaje::count());
    }
}
