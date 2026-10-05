<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\PagoSena;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Turno JSON exposes `sena` (the online deposit paid through Mercado Pago)
 * so the agenda can list who paid only the deposit.
 */
class TurnoSenaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profesional $profesional;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->profesional = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'telefono' => '3765252395']);
    }

    private function turno(?int $reservaId = null, ?int $grupoId = null, string $hora = '10:00'): Turno
    {
        return Turno::create([
            'user_id' => $this->user->id,
            'profesional_id' => $this->profesional->id,
            'cliente_id' => $this->cliente->id,
            'reserva_web_id' => $reservaId,
            'grupo_id' => $grupoId,
            'fecha_hora' => now()->addDay()->format('Y-m-d').' '.$hora.':00',
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => $reservaId ? 'web' : 'app',
        ]);
    }

    private function reserva(?string $estadoPago = null, string $monto = '1250.50'): ReservaWeb
    {
        $reserva = ReservaWeb::create([
            'user_id' => $this->user->id,
            'profesional_id' => $this->profesional->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [],
            'estado' => 'confirmed',
            'fecha' => now()->addDay()->format('Y-m-d'),
            'slot_hora' => '10:00:00',
            'duracion_total_minutos' => 60,
            'nombre_completo' => 'Lucia Gomez',
            'telefono' => '3765252395',
        ]);
        if ($estadoPago !== null) {
            PagoSena::create([
                'reserva_web_id' => $reserva->id,
                'mp_preference_id' => 'pref-'.$reserva->id,
                'monto' => $monto,
                'estado' => $estadoPago,
            ]);
        }

        return $reserva;
    }

    private function api(): self
    {
        return $this->actingAs($this->user, 'sanctum');
    }

    public function test_turno_without_reserva_web_has_null_sena(): void
    {
        $turno = $this->turno();

        $this->api()->getJson('/api/turnos')->assertOk()
            ->assertJsonPath('0.id', $turno->id)
            ->assertJsonPath('0.sena', null)
            ->assertJsonStructure(['0' => ['sena']]);
    }

    public function test_web_turno_with_approved_pago_exposes_monto_and_estado(): void
    {
        $reserva = $this->reserva('aprobado', '1250.50');
        $this->turno($reserva->id);

        $sena = $this->api()->getJson('/api/turnos')->assertOk()->json('0.sena');

        $this->assertSame(['monto', 'estado', 'reserva_web_id'], array_keys($sena));
        $this->assertSame(1250.5, $sena['monto']);
        $this->assertSame('aprobado', $sena['estado']);
        $this->assertSame($reserva->id, $sena['reserva_web_id']);
    }

    public function test_pending_pago_is_exposed_with_raw_estado(): void
    {
        $this->turno($this->reserva('pendiente', '800')->id);

        $this->api()->getJson('/api/turnos')->assertOk()
            ->assertJsonPath('0.sena.estado', 'pendiente')
            ->assertJsonPath('0.sena.monto', 800);
    }

    public function test_reserva_without_pago_has_null_sena(): void
    {
        $this->turno($this->reserva()->id);

        $this->api()->getJson('/api/turnos')->assertOk()->assertJsonPath('0.sena', null);
    }

    public function test_existing_reserva_web_payload_is_unchanged_and_hides_pago(): void
    {
        $this->turno($this->reserva('aprobado')->id);

        $turno = $this->api()->getJson('/api/turnos')->assertOk()->json('0');

        $this->assertArrayHasKey('reserva_web', $turno);
        $this->assertArrayNotHasKey('pago_sena', $turno['reserva_web']);
    }

    public function test_grupo_turnos_share_the_same_reserva_level_sena(): void
    {
        $reserva = $this->reserva('aprobado', '3000');
        $grupo = \App\Models\TurnoGrupo::create(['reserva_web_id' => $reserva->id, 'modo' => 'secuencia']);
        $a = $this->turno($reserva->id, $grupo->id, '10:00');
        $b = $this->turno($reserva->id, $grupo->id, '11:00');

        $lista = collect($this->api()->getJson('/api/turnos')->assertOk()->json());

        $senaA = $lista->firstWhere('id', $a->id)['sena'];
        $senaB = $lista->firstWhere('id', $b->id)['sena'];
        $this->assertSame($senaA, $senaB);
        $this->assertSame(3000, $senaA['monto']);
        $this->assertSame($reserva->id, $senaA['reserva_web_id']);
    }

    public function test_show_includes_sena_without_leaking_reserva_web(): void
    {
        $turno = $this->turno($this->reserva('aprobado', '500')->id);

        $json = $this->api()->getJson("/api/turnos/{$turno->id}")->assertOk()
            ->assertJsonPath('sena.estado', 'aprobado')
            ->assertJsonPath('sena.monto', 500)
            ->json();

        $this->assertArrayNotHasKey('reserva_web', $json);
    }

    public function test_show_without_reserva_has_null_sena(): void
    {
        $turno = $this->turno();

        $this->api()->getJson("/api/turnos/{$turno->id}")->assertOk()->assertJsonPath('sena', null);
    }

    public function test_other_listings_expose_sena(): void
    {
        $reserva = $this->reserva('aprobado', '700');
        $turno = $this->turno($reserva->id);
        $turno->update(['estado' => 'completado', 'fecha_hora' => now()->subDay()]);
        $turno->servicios()->attach(
            \App\Models\Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Esmaltado', 'duracion_minutos' => 60, 'precio' => 1000])->id
        );

        $this->api()->getJson('/api/turnos/pendientes-de-cobro')->assertOk()
            ->assertJsonPath('0.sena.monto', 700);
    }

    public function test_index_query_count_does_not_grow_with_turnos(): void
    {
        $this->turno($this->reserva('aprobado')->id, null, '09:00');
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->api()->getJson('/api/turnos')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $contar(); // warm-up
        $conUno = $contar();

        foreach (['10:00', '11:00', '12:00', '13:00'] as $hora) {
            $this->turno($this->reserva('aprobado')->id, null, $hora);
        }
        $this->turno(null, null, '14:00');

        $this->assertSame($conUno, $contar());
    }

    public function test_other_users_turnos_remain_unreachable(): void
    {
        $turno = $this->turno($this->reserva('aprobado')->id);
        $otro = User::factory()->create(['is_exempt' => true]);

        $this->actingAs($otro, 'sanctum')->getJson("/api/turnos/{$turno->id}")->assertNotFound();
        $this->actingAs($otro, 'sanctum')->getJson('/api/turnos')->assertOk()->assertJsonCount(0);
    }
}
