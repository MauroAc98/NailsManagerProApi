<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\PagoSena;
use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/cobros — la pantalla Cobros paginada. Las reglas de estado de pago
 * son las mismas que usa el detalle de un turno en el frontend (lib/cobros.ts):
 * si cambia una, cambia la otra.
 */
class CobrosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profesional $profesional;

    private Cliente $cliente;

    private Servicio $conLista;   // lista $10.000

    private Servicio $conLista2;  // lista $5.000

    private Servicio $sinLista;   // sin precio de lista

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->profesional = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Jefa', 'activo' => true]);
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'apellido' => 'Test', 'telefono' => '3765252395']);
        $this->conLista = $this->servicio('Capping', 10000);
        $this->conLista2 = $this->servicio('Esmaltado', 5000);
        $this->sinLista = $this->servicio('Soft gel', null);
    }

    private function servicio(string $nombre, ?int $precio): Servicio
    {
        return Servicio::create([
            'user_id' => $this->user->id,
            'nombre' => $nombre,
            'duracion_minutos' => 30,
            'precio' => $precio,
            'activo' => true,
        ]);
    }

    /**
     * @param  array{fecha?: string, estado?: string, reserva?: ?int, cliente?: Cliente, servicios?: list<array{0: Servicio, 1: ?float}>}  $o
     */
    private function turno(array $o = []): Turno
    {
        $turno = Turno::create([
            'user_id' => $this->user->id,
            'profesional_id' => $this->profesional->id,
            'cliente_id' => ($o['cliente'] ?? $this->cliente)->id,
            'reserva_web_id' => $o['reserva'] ?? null,
            'fecha_hora' => $o['fecha'] ?? '2026-10-08 10:00:00',
            'duracion_total_minutos' => 60,
            'estado' => $o['estado'] ?? 'confirmado',
            'origen' => ($o['reserva'] ?? null) ? 'web' : 'app',
        ]);
        foreach ($o['servicios'] ?? [[$this->conLista, null]] as [$servicio, $precio]) {
            $turno->servicios()->attach($servicio->id, ['precio' => $precio]);
        }

        return $turno;
    }

    private function reserva(?string $estadoPago, float $monto): ReservaWeb
    {
        $reserva = ReservaWeb::create([
            'user_id' => $this->user->id,
            'profesional_id' => $this->profesional->id,
            'public_token' => ReservaWeb::generarToken(),
            'servicio_ids' => [],
            'estado' => 'confirmed',
            'fecha' => '2026-10-08',
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

    /** @param array<string, mixed> $query */
    private function cobros(array $query = [])
    {
        $query = array_merge(['desde' => '2026-07-01', 'hasta' => '2026-12-31', 'hoy' => '2026-10-08'], $query);

        return $this->actingAs($this->user, 'sanctum')->getJson('/api/cobros?'.http_build_query($query));
    }

    private function ids($response): array
    {
        return collect($response->json('data'))->pluck('id')->all();
    }

    // ── Alcance ──────────────────────────────────────────────────────
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/cobros')->assertUnauthorized();
    }

    public function test_only_lists_turnos_of_the_authenticated_account(): void
    {
        $mio = $this->turno();
        $otro = User::factory()->create(['is_exempt' => true]);
        $profOtro = Profesional::create(['user_id' => $otro->id, 'nombre' => 'Otra', 'activo' => true]);
        $cliOtro = Cliente::create(['user_id' => $otro->id, 'nombre' => 'Zoe', 'telefono' => '3765000000']);
        Turno::create([
            'user_id' => $otro->id, 'profesional_id' => $profOtro->id, 'cliente_id' => $cliOtro->id,
            'fecha_hora' => '2026-10-08 11:00:00', 'duracion_total_minutos' => 60, 'estado' => 'confirmado', 'origen' => 'app',
        ]);

        $this->assertSame([$mio->id], $this->ids($this->cobros()->assertOk()));
    }

    public function test_excludes_cancelled_and_turnos_outside_the_window(): void
    {
        $vivo = $this->turno();
        $this->turno(['estado' => 'cancelado']);
        $this->turno(['fecha' => '2026-06-30 10:00:00']);
        $this->turno(['fecha' => '2027-01-01 10:00:00']);

        $this->assertSame([$vivo->id], $this->ids($this->cobros()->assertOk()));
    }

    // ── Paginación ───────────────────────────────────────────────────
    public function test_paginates_newest_first_and_reports_the_totals(): void
    {
        $t = [];
        foreach (['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'] as $dia) {
            $t[$dia] = $this->turno(['fecha' => "$dia 10:00:00"]);
        }

        $p1 = $this->cobros(['page' => 1, 'per_page' => 2])->assertOk();
        $this->assertSame([$t['2026-10-05']->id, $t['2026-10-04']->id], $this->ids($p1));
        $p1->assertJsonPath('total', 5)->assertJsonPath('last_page', 3)->assertJsonPath('current_page', 1);

        $p3 = $this->cobros(['page' => 3, 'per_page' => 2])->assertOk();
        $this->assertSame([$t['2026-10-01']->id], $this->ids($p3));
    }

    public function test_per_page_is_capped_at_100(): void
    {
        $this->turno();

        $this->cobros(['per_page' => 5000])->assertOk()->assertJsonPath('per_page', 100);
    }

    public function test_page_items_keep_the_turnos_shape(): void
    {
        $this->turno();

        $this->cobros()->assertOk()->assertJsonStructure([
            'data' => [['id', 'fecha_hora', 'estado', 'profesional_id', 'cliente' => ['nombre', 'apellido'], 'servicios' => [['id', 'nombre', 'pivot']], 'sena', 'cobro']],
        ]);
    }

    // ── Estado de pago por fila ──────────────────────────────────────
    public function test_confirmed_without_sena_is_unpaid_and_owes_the_list_price(): void
    {
        $this->turno();

        $this->cobros()->assertOk()
            ->assertJsonPath('data.0.cobro.pago', 'nada')
            ->assertJsonPath('data.0.cobro.finalizado', false)
            ->assertJsonPath('data.0.cobro.falta_fila', 10000)
            ->assertJsonPath('data.0.cobro.lista_completa', true)
            ->assertJsonPath('data.0.cobro.precio_lista', 10000);
    }

    public function test_confirmed_with_a_smaller_approved_sena_paid_only_the_sena(): void
    {
        $reserva = $this->reserva('aprobado', 4000);
        $this->turno(['reserva' => $reserva->id]);

        $this->cobros()->assertOk()
            ->assertJsonPath('data.0.cobro.pago', 'sena')
            ->assertJsonPath('data.0.cobro.sena', 4000)
            ->assertJsonPath('data.0.cobro.falta_fila', 6000);
    }

    public function test_confirmed_whose_sena_covers_the_list_price_is_fully_paid(): void
    {
        $reserva = $this->reserva('aprobado', 10000);
        $this->turno(['reserva' => $reserva->id]);

        $this->cobros()->assertOk()
            ->assertJsonPath('data.0.cobro.pago', 'todo')
            ->assertJsonPath('data.0.cobro.falta_fila', 0);
    }

    public function test_pending_or_rejected_sena_does_not_count_as_paid(): void
    {
        foreach (['pendiente', 'rechazado', 'expirado'] as $estado) {
            $this->turno(['reserva' => $this->reserva($estado, 4000)->id]);
        }

        $filas = $this->cobros()->assertOk()->json('data');
        $this->assertCount(3, $filas);
        foreach ($filas as $fila) {
            $this->assertSame('nada', $fila['cobro']['pago']);
            $this->assertEquals(0, $fila['cobro']['sena']);
        }
    }

    public function test_confirmed_with_a_service_without_list_price_has_unknown_balance(): void
    {
        $this->turno(['servicios' => [[$this->sinLista, null]], 'reserva' => $this->reserva('aprobado', 2000)->id]);

        $this->cobros()->assertOk()
            ->assertJsonPath('data.0.cobro.pago', 'sena')
            ->assertJsonPath('data.0.cobro.precio', null)
            ->assertJsonPath('data.0.cobro.falta_fila', null)
            ->assertJsonPath('data.0.cobro.lista_completa', false);
    }

    public function test_finished_with_every_price_loaded_is_paid_and_sums_the_pivot(): void
    {
        $this->turno(['estado' => 'completado', 'servicios' => [[$this->conLista, 8000], [$this->conLista2, 4000.5]]]);

        $this->cobros()->assertOk()
            ->assertJsonPath('data.0.cobro.pago', 'todo')
            ->assertJsonPath('data.0.cobro.finalizado', true)
            ->assertJsonPath('data.0.cobro.cobrado', 12000.5);
    }

    public function test_finished_with_a_missing_price_needs_the_price_loaded(): void
    {
        $this->turno(['estado' => 'completado', 'servicios' => [[$this->conLista, 8000], [$this->conLista2, null]]]);

        $this->cobros()->assertOk()
            ->assertJsonPath('data.0.cobro.pago', 'sinprecio')
            ->assertJsonPath('data.0.cobro.cobrado', null);
    }

    public function test_finished_charged_zero_is_unpaid(): void
    {
        $this->turno(['estado' => 'completado', 'servicios' => [[$this->conLista, 0]]]);

        $this->cobros()->assertOk()
            ->assertJsonPath('data.0.cobro.pago', 'nada')
            ->assertJsonPath('data.0.cobro.cobrado', 0);
    }

    public function test_turnos_of_the_same_reserva_share_the_sena_and_show_no_balance_per_row(): void
    {
        $reserva = $this->reserva('aprobado', 5000);
        $this->turno(['reserva' => $reserva->id]);
        $this->turno(['reserva' => $reserva->id, 'servicios' => [[$this->conLista2, null]], 'fecha' => '2026-10-08 12:00:00']);

        $filas = $this->cobros()->assertOk()->json('data');
        foreach ($filas as $fila) {
            $this->assertTrue($fila['cobro']['sena_compartida']);
            $this->assertNull($fila['cobro']['falta_fila']);
        }
    }

    // ── Filtros ──────────────────────────────────────────────────────
    public function test_filters_by_payment_state_and_turno_state(): void
    {
        $sena = $this->turno(['reserva' => $this->reserva('aprobado', 4000)->id]);
        $this->turno(['fecha' => '2026-10-09 10:00:00']); // nada
        $pagado = $this->turno(['estado' => 'completado', 'servicios' => [[$this->conLista, 9000]]]);
        $sinPrecio = $this->turno(['estado' => 'completado', 'servicios' => [[$this->conLista, null]]]);

        $this->assertSame([$sena->id], $this->ids($this->cobros(['pago' => 'sena'])));
        $this->assertSame([$sinPrecio->id], $this->ids($this->cobros(['pago' => 'sinprecio'])));
        $this->assertEqualsCanonicalizing([$pagado->id, $sinPrecio->id], $this->ids($this->cobros(['turno' => 'finalizado'])));
        $this->assertSame([$sena->id], $this->ids($this->cobros(['turno' => 'confirmado', 'pago' => 'sena'])));
    }

    public function test_search_ignores_case_and_accents(): void
    {
        $maria = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'María José', 'apellido' => 'Pérez', 'telefono' => '3765111111']);
        $buscada = $this->turno(['cliente' => $maria]);
        $this->turno();

        $this->assertSame([$buscada->id], $this->ids($this->cobros(['buscar' => 'maria jose'])));
        $this->assertSame([$buscada->id], $this->ids($this->cobros(['buscar' => 'PEREZ'])));
    }

    public function test_period_filters_use_the_date_sent_by_the_client(): void
    {
        $hoy = $this->turno(['fecha' => '2026-10-08 10:00:00']);
        $haceCuatro = $this->turno(['fecha' => '2026-10-04 10:00:00']);
        $haceSiete = $this->turno(['fecha' => '2026-10-01 10:00:00']);
        $futuro = $this->turno(['fecha' => '2026-10-20 10:00:00']);
        $viejo = $this->turno(['fecha' => '2026-08-15 10:00:00']);

        $this->assertCount(5, $this->ids($this->cobros(['periodo' => 'todo'])));
        $this->assertSame([$hoy->id], $this->ids($this->cobros(['periodo' => 'hoy'])));
        $this->assertSame([$hoy->id, $haceCuatro->id], $this->ids($this->cobros(['periodo' => '7dias'])));
        $this->assertSame([$futuro->id, $hoy->id, $haceCuatro->id, $haceSiete->id], $this->ids($this->cobros(['periodo' => 'mes'])));
        $this->assertSame([$futuro->id, $hoy->id], $this->ids($this->cobros(['periodo' => 'proximos'])));
        $this->assertNotContains($viejo->id, $this->ids($this->cobros(['periodo' => 'mes'])));
    }

    public function test_rejects_unknown_filter_values(): void
    {
        $this->cobros(['pago' => 'cualquiera'])->assertUnprocessable();
        $this->cobros(['periodo' => 'siempre'])->assertUnprocessable();
        $this->cobros(['turno' => 'raro'])->assertUnprocessable();
        $this->cobros(['hoy' => 'no-es-fecha'])->assertUnprocessable();
    }

    // ── Conteos y resumen ────────────────────────────────────────────
    public function test_counts_ignore_the_payment_filter_but_respect_the_others(): void
    {
        $this->turno(['reserva' => $this->reserva('aprobado', 4000)->id]);                                          // sena, confirmado
        $this->turno(['fecha' => '2026-10-09 10:00:00']);                                                           // nada, confirmado
        $this->turno(['estado' => 'completado', 'servicios' => [[$this->conLista, 9000]]]);                         // todo, finalizado
        $this->turno(['estado' => 'completado', 'servicios' => [[$this->conLista, null]]]);                         // sinprecio, finalizado

        $todos = $this->cobros(['pago' => 'sena'])->assertOk();
        $todos->assertJsonPath('counts', ['todos' => 4, 'sena' => 1, 'todo' => 1, 'nada' => 1, 'sinprecio' => 1]);

        $finalizados = $this->cobros(['turno' => 'finalizado', 'pago' => 'todo'])->assertOk();
        $finalizados->assertJsonPath('counts', ['todos' => 2, 'sena' => 0, 'todo' => 1, 'nada' => 0, 'sinprecio' => 1]);
    }

    public function test_summary_is_computed_over_every_page_not_only_the_one_returned(): void
    {
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $dia) {
            $this->turno(['fecha' => "$dia 10:00:00"]); // cada uno debe $10.000
        }

        $p1 = $this->cobros(['per_page' => 1, 'page' => 1])->assertOk();
        $p3 = $this->cobros(['per_page' => 1, 'page' => 3])->assertOk();

        $this->assertEquals(30000, $p1->json('resumen.falta_cobrar'));
        $this->assertEquals(30000, $p3->json('resumen.falta_cobrar'));
    }

    public function test_already_paid_does_not_count_the_sena_of_a_finished_turno_twice(): void
    {
        // El precio registrado del finalizado ($9.000) ya incluye su seña de $4.000.
        $this->turno(['estado' => 'completado', 'reserva' => $this->reserva('aprobado', 4000)->id, 'servicios' => [[$this->conLista, 9000]]]);
        // Un confirmado con seña: esa seña sí es plata ya cobrada.
        $this->turno(['reserva' => $this->reserva('aprobado', 1000)->id, 'fecha' => '2026-10-09 10:00:00']);

        $resumen = $this->cobros()->assertOk()->json('resumen');

        $this->assertEquals(1000, $resumen['sena_en_pendientes']);
        $this->assertEquals(9000, $resumen['cobrado_finalizados']);
        $this->assertEquals(10000, $resumen['cobrado_total']); // 9.000 + 1.000, no 14.000
        $this->assertEquals(5000, $resumen['sena_cobrada']);
        $this->assertEquals(9000, $resumen['falta_cobrar']); // 10.000 - 1.000
    }

    public function test_summary_counts_finished_turnos_without_price(): void
    {
        $this->turno(['estado' => 'completado', 'servicios' => [[$this->conLista, null], [$this->conLista2, null]]]);

        $this->cobros()->assertOk()
            ->assertJsonPath('resumen.sin_precio_count', 1)
            ->assertJsonPath('resumen.sin_precio_estimado', 15000);
    }

    public function test_bulk_list_only_has_finished_turnos_whose_services_all_have_a_list_price(): void
    {
        $a = $this->turno(['estado' => 'completado', 'servicios' => [[$this->conLista, null], [$this->conLista2, null]]]);
        $this->turno(['estado' => 'completado', 'servicios' => [[$this->sinLista, null]]]); // sin lista: no entra

        $r = $this->cobros()->assertOk();

        $r->assertJsonPath('lista_bulk.count', 1)->assertJsonPath('lista_bulk.total', 15000);
        $this->assertSame($a->id, $r->json('lista_bulk.items.0.turno_id'));
        $this->assertEqualsCanonicalizing(
            [['servicio_id' => $this->conLista->id, 'precio' => 10000], ['servicio_id' => $this->conLista2->id, 'precio' => 5000]],
            $r->json('lista_bulk.items.0.precios'),
        );
    }

    public function test_existing_turnos_endpoint_is_unchanged(): void
    {
        $turno = $this->turno();

        $this->actingAs($this->user, 'sanctum')->getJson('/api/turnos')->assertOk()
            ->assertJsonPath('0.id', $turno->id)
            ->assertJsonMissingPath('0.cobro');
    }
}
