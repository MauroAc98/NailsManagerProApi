<?php

namespace Tests\Feature;

use App\Jobs\EnviarMensajeConfirmacion;
use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\SlotDisponible;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TurnoLoteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profesional $ana;

    private Profesional $bea;

    private Cliente $cliente;

    private Servicio $mani;

    private Servicio $pedi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->ana = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
        $this->bea = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Bea', 'activo' => true]);
        foreach ([$this->ana, $this->bea] as $prof) {
            foreach (['09:00:00', '18:00:00'] as $hora) {
                SlotDisponible::create(['user_id' => $this->user->id, 'profesional_id' => $prof->id, 'hora' => $hora, 'activo' => true]);
            }
        }
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Cli', 'telefono' => '3765252395']);
        $this->mani = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Mani', 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
        $this->pedi = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Pedi', 'duracion_minutos' => 45, 'precio' => 2000, 'activo' => true]);
        foreach ([$this->ana, $this->bea] as $prof) {
            $prof->servicios()->attach([$this->mani->id, $this->pedi->id]);
        }
    }

    private function dia(int $offset): string
    {
        return now()->addDays($offset)->format('Y-m-d');
    }

    private function item(int $offset, string $hora, array $servicios, array $extra = []): array
    {
        return array_merge([
            'fecha_hora' => $this->dia($offset)." {$hora}",
            'servicio_ids' => array_map(fn (Servicio $s) => $s->id, $servicios),
        ], $extra);
    }

    private function lote(array $turnos, ?Cliente $cliente = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user, 'sanctum')->postJson('/api/turnos/lote', [
            'cliente_id' => ($cliente ?? $this->cliente)->id,
            'turnos' => $turnos,
        ]);
    }

    private function turnoExistente(string $fecha, string $hora, Servicio $servicio, ?Profesional $prof = null): Turno
    {
        $turno = Turno::create([
            'user_id' => $this->user->id,
            'profesional_id' => ($prof ?? $this->ana)->id,
            'cliente_id' => $this->cliente->id,
            'fecha_hora' => "{$fecha} {$hora}",
            'duracion_total_minutos' => $servicio->duracion_minutos,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);
        $turno->servicios()->attach($servicio->id);

        return $turno;
    }

    public function test_crea_dos_turnos_en_dias_distintos_con_el_mismo_lote_id(): void
    {
        $response = $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(3, '11:00:00', [$this->pedi], ['notas' => 'segunda']),
        ])->assertCreated();

        $loteId = $response->json('lote_id');
        $this->assertNotEmpty($loteId);
        $response->assertJsonCount(2, 'turnos');

        $turnos = Turno::orderBy('fecha_hora')->get();
        $this->assertCount(2, $turnos);
        foreach ($turnos as $turno) {
            $this->assertSame($loteId, $turno->lote_id);
            $this->assertNull($turno->grupo_id);
            $this->assertNull($turno->reserva_web_id);
            $this->assertSame('confirmado', $turno->estado);
            $this->assertSame('app', $turno->origen);
            $this->assertSame($this->cliente->id, $turno->cliente_id);
            $this->assertSame($this->user->id, $turno->user_id);
        }
        $this->assertSame(30, $turnos[0]->duracion_total_minutos);
        $this->assertSame(45, $turnos[1]->duracion_total_minutos);
        $this->assertSame([$this->mani->id], $turnos[0]->servicios->pluck('id')->all());
        $this->assertSame([$this->pedi->id], $turnos[1]->servicios->pluck('id')->all());
        $this->assertSame('segunda', $turnos[1]->notas);

        // Same decoration as store(): cliente + servicios loaded, sena key present.
        $response->assertJsonPath('turnos.0.cliente.id', $this->cliente->id);
        $response->assertJsonPath('turnos.0.servicios.0.id', $this->mani->id);
        $response->assertJsonPath('turnos.0.lote_id', $loteId);
        $this->assertArrayHasKey('sena', $response->json('turnos.0'));
    }

    public function test_crea_tres_turnos_con_varios_servicios_y_distintas_profesionales(): void
    {
        $response = $this->lote([
            $this->item(1, '10:00:00', [$this->mani, $this->pedi], ['profesional_id' => $this->bea->id]),
            $this->item(2, '10:00:00', [$this->mani]),
            $this->item(4, '12:00:00', [$this->pedi], ['profesional_id' => $this->bea->id]),
        ])->assertCreated();

        $turnos = Turno::orderBy('fecha_hora')->get();
        $this->assertCount(3, $turnos);
        $this->assertSame(1, $turnos->pluck('lote_id')->unique()->count());
        $this->assertSame(75, $turnos[0]->duracion_total_minutos);
        $this->assertEqualsCanonicalizing([$this->mani->id, $this->pedi->id], $turnos[0]->servicios->pluck('id')->all());
        $this->assertSame([$this->bea->id, $this->ana->id, $this->bea->id], $turnos->pluck('profesional_id')->all());
        $response->assertJsonCount(3, 'turnos');
    }

    public function test_si_el_segundo_item_choca_no_se_crea_ninguno(): void
    {
        $this->turnoExistente($this->dia(3), '11:00:00', $this->pedi);

        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(3, '11:15:00', [$this->mani]),
        ])
            ->assertStatus(422)
            ->assertJsonPath('indice', 1);

        $this->assertSame(1, Turno::count());
        $this->assertSame(0, Turno::whereNotNull('lote_id')->count());
    }

    public function test_aplica_el_maximo_de_dos_por_dia_contando_los_existentes_y_los_del_lote(): void
    {
        $this->turnoExistente($this->dia(1), '09:00:00', $this->pedi);

        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(1, '12:00:00', [$this->mani]),
        ])
            ->assertStatus(422)
            ->assertJsonPath('indice', 1)
            ->assertJsonFragment(['message' => 'Turno 2: El cliente ya tiene el máximo de 2 turnos para este día.']);

        $this->assertSame(1, Turno::count());
    }

    public function test_rechaza_dos_items_del_lote_el_mismo_dia_con_mas_de_dos_turnos(): void
    {
        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(1, '12:00:00', [$this->pedi]),
            $this->item(1, '14:00:00', [$this->pedi, $this->mani]),
        ])
            ->assertStatus(422)
            ->assertJsonPath('indice', 2);

        $this->assertSame(0, Turno::count());
    }

    public function test_rechaza_el_mismo_servicio_el_mismo_dia_entre_items_del_lote(): void
    {
        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(1, '12:00:00', [$this->mani]),
        ])
            ->assertStatus(422)
            ->assertJsonPath('indice', 1)
            ->assertJsonFragment(['message' => 'Turno 2: El cliente ya tiene agendado: Mani para este día.']);

        $this->assertSame(0, Turno::count());
    }

    public function test_rechaza_items_de_la_misma_profesional_que_se_solapan(): void
    {
        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(1, '10:15:00', [$this->pedi]),
        ])
            ->assertStatus(422)
            ->assertJsonPath('indice', 1);

        $this->assertSame(0, Turno::count());
    }

    public function test_el_indice_apunta_al_item_original_aunque_se_creen_en_orden_cronologico(): void
    {
        // Item 0 is the later one; item 1 (earlier) is created first and item 0 collides with it.
        $this->lote([
            $this->item(1, '10:20:00', [$this->pedi]),
            $this->item(1, '10:00:00', [$this->mani]),
        ])
            ->assertStatus(422)
            ->assertJsonPath('indice', 0);

        $this->assertSame(0, Turno::count());
    }

    public function test_un_hold_vivo_rechaza_el_lote_conservando_el_code(): void
    {
        ReservaWeb::create([
            'user_id' => $this->user->id,
            'profesional_id' => $this->ana->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [$this->mani->id],
            'fecha' => $this->dia(2),
            'slot_hora' => '10:00:00',
            'duracion_total_minutos' => 60,
            'estado' => 'held',
            'expira_en' => now()->timestamp + 600,
        ]);

        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(2, '10:30:00', [$this->mani]),
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'slot_held')
            ->assertJsonPath('indice', 1);

        $this->assertSame(0, Turno::count());
    }

    public function test_rechaza_servicios_que_la_profesional_no_hace(): void
    {
        $this->bea->servicios()->detach($this->pedi->id);

        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(2, '10:00:00', [$this->pedi], ['profesional_id' => $this->bea->id]),
        ])
            ->assertStatus(422)
            ->assertJsonPath('indice', 1);

        $this->assertSame(0, Turno::count());
    }

    public function test_rechaza_promos_combo(): void
    {
        $promo = Servicio::create([
            'user_id' => $this->user->id, 'nombre' => 'Combo', 'duracion_minutos' => 75,
            'precio' => 2500, 'activo' => true, 'es_promo' => true, 'modo_promo' => 'secuencia',
        ]);
        $promo->componentes()->create(['componente_servicio_id' => $this->mani->id, 'profesional_id' => $this->ana->id, 'orden' => 1]);
        $promo->componentes()->create(['componente_servicio_id' => $this->pedi->id, 'profesional_id' => $this->ana->id, 'orden' => 2]);

        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(2, '10:00:00', [$promo]),
        ])
            ->assertStatus(422)
            ->assertJsonPath('indice', 1);

        $this->assertSame(0, Turno::count());
    }

    public function test_la_clienta_de_otra_cuenta_da_404(): void
    {
        $otro = User::factory()->create(['is_exempt' => true]);
        $ajena = Cliente::create(['user_id' => $otro->id, 'nombre' => 'Ajena', 'telefono' => '3765000000']);

        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(2, '10:00:00', [$this->mani]),
        ], $ajena)->assertNotFound();

        $this->assertSame(0, Turno::count());
    }

    public function test_una_profesional_de_otra_cuenta_da_404(): void
    {
        $otro = User::factory()->create(['is_exempt' => true]);
        $ajena = Profesional::create(['user_id' => $otro->id, 'nombre' => 'Ajena', 'activo' => true]);

        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(2, '10:00:00', [$this->mani], ['profesional_id' => $ajena->id]),
        ])->assertNotFound();

        $this->assertSame(0, Turno::count());
    }

    public function test_un_servicio_de_otra_cuenta_se_rechaza(): void
    {
        $otro = User::factory()->create(['is_exempt' => true]);
        $ajeno = Servicio::create(['user_id' => $otro->id, 'nombre' => 'Ajeno', 'duracion_minutos' => 30, 'precio' => 1, 'activo' => true]);

        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(2, '10:00:00', [$ajeno]),
        ])->assertStatus(422);

        $this->assertSame(0, Turno::count());
    }

    public function test_exige_entre_dos_y_diez_turnos(): void
    {
        $this->lote([$this->item(1, '10:00:00', [$this->mani])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('turnos');

        $once = [];
        for ($i = 1; $i <= 11; $i++) {
            $once[] = $this->item($i, '10:00:00', [$this->mani]);
        }
        $this->lote($once)->assertStatus(422)->assertJsonValidationErrors('turnos');

        $this->assertSame(0, Turno::count());
    }

    public function test_acepta_exactamente_diez_turnos(): void
    {
        $diez = [];
        for ($i = 1; $i <= 10; $i++) {
            $diez[] = $this->item($i, '10:00:00', [$this->mani]);
        }

        $this->lote($diez)->assertCreated()->assertJsonCount(10, 'turnos');
        $this->assertSame(10, Turno::count());
    }

    public function test_valida_los_campos_de_cada_item(): void
    {
        $this->lote([
            ['fecha_hora' => $this->dia(1).' 10:00:00', 'servicio_ids' => []],
            ['servicio_ids' => [$this->mani->id]],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['turnos.0.servicio_ids', 'turnos.1.fecha_hora']);
    }

    public function test_el_lote_dispara_un_solo_mensaje_de_whatsapp_para_el_primer_turno_por_fecha(): void
    {
        Queue::fake();

        // Sent out of order on purpose: the dispatched turno is the earliest one.
        $this->lote([
            $this->item(2, '10:00:00', [$this->mani]),
            $this->item(1, '10:00:00', [$this->mani]),
        ])->assertCreated();

        $primero = Turno::orderBy('fecha_hora')->first();
        Queue::assertPushed(EnviarMensajeConfirmacion::class, 1);
        Queue::assertPushed(EnviarMensajeConfirmacion::class, fn ($job) => $job->turnoId === $primero->id && $job->tipo === 'confirmacion');
    }

    public function test_un_lote_rechazado_no_dispara_mensajes(): void
    {
        Queue::fake();

        $this->lote([
            $this->item(1, '10:00:00', [$this->mani]),
            $this->item(1, '10:00:00', [$this->mani]),
        ])->assertStatus(422);

        Queue::assertNotPushed(EnviarMensajeConfirmacion::class);
    }

    public function test_el_endpoint_simple_sigue_creando_sin_lote_id(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/turnos', [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->mani->id],
            'fecha_hora' => $this->dia(1).' 10:00:00',
        ])->assertCreated();

        $this->assertNull(Turno::first()->lote_id);
    }
}
