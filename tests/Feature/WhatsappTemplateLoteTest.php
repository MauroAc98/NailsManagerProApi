<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Batch (lote) WhatsApp templates: confirmacion_lote (6 vars) and
 * reserva_sena_lote (8 vars), plus their _mapa / _v2 name variants.
 * Individual template types are covered by their own tests and must not change.
 */
class WhatsappTemplateLoteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cliente $cliente;

    private Servicio $mani;

    private Servicio $pedi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'name' => 'Studio Uno',
            'is_exempt' => true,
            'telefono' => '+543765111111',
            'direccion' => 'Calle 1',
            'sena_monto' => 2500,
            'whatsapp_sena_titular' => 'Kim',
            'whatsapp_sena_alias' => 'kim.alias',
        ]);
        $this->cliente = Cliente::create(['user_id' => $this->user->id, 'nombre' => 'Marta', 'apellido' => 'Rios', 'telefono' => '+543765252395']);
        $this->mani = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Manicura', 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
        $this->pedi = Servicio::create(['user_id' => $this->user->id, 'nombre' => 'Depilación', 'duracion_minutos' => 30, 'precio' => 1000, 'activo' => true]);
    }

    private function turno(string $profesional, array $servicios, string $cuando, ?string $loteId, string $estado = 'confirmado'): Turno
    {
        $prof = Profesional::firstOrCreate(['user_id' => $this->user->id, 'nombre' => $profesional], ['activo' => true]);
        $t = Turno::create([
            'user_id' => $this->user->id, 'cliente_id' => $this->cliente->id, 'profesional_id' => $prof->id, 'lote_id' => $loteId,
            'fecha_hora' => $cuando, 'duracion_total_minutos' => 30, 'estado' => $estado, 'origen' => 'app',
        ]);
        $t->servicios()->attach(array_map(fn (Servicio $s) => $s->id, $servicios));

        return $t;
    }

    /** @return array{0: Turno, 1: Turno} two turnos created out of date order */
    private function lote(string $segunda = 'Ana'): array
    {
        $id = (string) Str::uuid();
        $tarde = $this->turno($segunda, [$this->pedi], now()->addDays(4)->setTime(10, 0)->format('Y-m-d H:i:s'), $id);
        $temprano = $this->turno('Ana', [$this->mani], now()->addDays(2)->setTime(15, 30)->format('Y-m-d H:i:s'), $id);

        return [$temprano, $tarde];
    }

    private function parametros(Turno $t, string $tipo): array
    {
        return WhatsappTemplate::parametrosCloudApi($tipo, $this->cliente, $t->fresh(['servicios', 'profesional']), $this->user);
    }

    public function test_nombres_de_plantilla_de_lote(): void
    {
        $this->assertSame('confirmacion_lote', WhatsappTemplate::nombrePlantillaMeta('confirmacion_lote'));
        $this->assertSame('confirmacion_lote_mapa', WhatsappTemplate::nombrePlantillaMeta('confirmacion_lote', true));
        $this->assertSame('reserva_lote_sena_v2', WhatsappTemplate::nombrePlantillaMeta('reserva_sena_lote'));
        $this->assertSame('reserva_lote_sena_mapa_v2', WhatsappTemplate::nombrePlantillaMeta('reserva_sena_lote', true));
    }

    public function test_los_nombres_individuales_no_cambian(): void
    {
        $this->assertSame('confirmacion_turno', WhatsappTemplate::nombrePlantillaMeta('confirmacion'));
        $this->assertSame('confirmacion_turno_mapa', WhatsappTemplate::nombrePlantillaMeta('confirmacion', true));
        $this->assertSame('reserva_turno_sena_v2', WhatsappTemplate::nombrePlantillaMeta('reserva_sena'));
        $this->assertSame('reserva_turno_sena_mapa_v2', WhatsappTemplate::nombrePlantillaMeta('reserva_sena', true));
    }

    public function test_parametros_de_confirmacion_lote_son_seis_y_en_orden(): void
    {
        [$temprano] = $this->lote();

        $p = $this->parametros($temprano, 'confirmacion_lote');

        $esperada = now()->addDays(2)->format('d/m').' 15:30 hs: Manicura · '.now()->addDays(4)->format('d/m').' 10:00 hs: Depilación';
        $this->assertCount(6, $p);
        $this->assertSame('Marta', $p[0]);
        $this->assertSame('Studio Uno', $p[1]);
        $this->assertSame($esperada, $p[2]);
        $this->assertSame('Calle 1', $p[3]);
        $this->assertSame('Ana', $p[4]);
        $this->assertSame('+54 9 376 511-1111', $p[5]);
    }

    public function test_la_linea_de_turnos_va_ordenada_por_fecha_y_une_servicios_con_mas(): void
    {
        $id = (string) Str::uuid();
        $b = $this->turno('Ana', [$this->mani, $this->pedi], now()->addDays(5)->setTime(9, 0)->format('Y-m-d H:i:s'), $id);
        $this->turno('Ana', [$this->mani], now()->addDays(3)->setTime(18, 5)->format('Y-m-d H:i:s'), $id);

        $linea = $this->parametros($b, 'confirmacion_lote')[2];

        $this->assertSame(
            now()->addDays(3)->format('d/m').' 18:05 hs: Manicura · '.now()->addDays(5)->format('d/m').' 09:00 hs: Manicura + Depilación',
            $linea,
        );
        $this->assertStringNotContainsString("\n", $linea);
    }

    public function test_parametros_de_sena_lote_son_ocho_y_el_monto_es_la_sena_por_turno(): void
    {
        [$temprano] = $this->lote();

        $p = $this->parametros($temprano, 'reserva_sena_lote');

        $this->assertCount(8, $p);
        $this->assertSame('Marta', $p[0]);
        $this->assertSame('Studio Uno', $p[1]);
        $this->assertSame('Calle 1', $p[3]);
        $this->assertSame('$5.000,00', $p[4]);
        $this->assertSame(WhatsappTemplate::datosCuentaSena($this->user), $p[5]);
        $this->assertSame('Ana', $p[6]);
        $this->assertSame('+54 9 376 511-1111', $p[7]);
    }

    public function test_el_monto_no_cuenta_los_turnos_cancelados(): void
    {
        [$temprano, $tarde] = $this->lote();
        $this->turno('Ana', [$this->mani], now()->addDays(6)->format('Y-m-d H:i:s'), $temprano->lote_id, 'cancelado');

        $p = $this->parametros($tarde, 'reserva_sena_lote');

        $this->assertSame('$5.000,00', $p[4]);
        $this->assertStringNotContainsString(now()->addDays(6)->format('d/m'), $this->parametros($tarde, 'confirmacion_lote')[2]);
    }

    public function test_con_varias_profesionales_distintas_dice_el_equipo(): void
    {
        [$temprano] = $this->lote('Laura');

        $this->assertSame('el equipo', $this->parametros($temprano, 'confirmacion_lote')[4]);
        $this->assertSame('el equipo', $this->parametros($temprano, 'reserva_sena_lote')[6]);
    }

    public function test_mensaje_legible_de_confirmacion_lote(): void
    {
        [$temprano] = $this->lote();
        $linea = $this->parametros($temprano, 'confirmacion_lote')[2];

        $texto = WhatsappTemplate::mensajeLegible('confirmacion_lote', $this->cliente, $temprano->fresh(), $this->user);

        $this->assertSame(
            "Hola Marta, tus turnos en *Studio Uno* quedaron confirmados.\n\n🗓️ {$linea}\n📍 Calle 1\n\n⚠️ No responder a este mensaje: *Ana* no lo recibe.\n\n📲 Para consultas o cambios, avisar al +54 9 376 511-1111 con 24 hs de anticipación.",
            $texto,
        );
    }

    public function test_mensaje_legible_de_sena_lote(): void
    {
        [$temprano] = $this->lote();
        $linea = $this->parametros($temprano, 'reserva_sena_lote')[2];
        $cuenta = WhatsappTemplate::datosCuentaSena($this->user);

        $texto = WhatsappTemplate::mensajeLegible('reserva_sena_lote', $this->cliente, $temprano->fresh(), $this->user);

        $this->assertSame(
            "Hola Marta, tus turnos en *Studio Uno* quedan reservados.\n\n🗓️ {$linea}\n📍 Calle 1\n\n💳 Seña: *\$5.000,00*\n🏦 {$cuenta}\n\n⚠️ No responder a este mensaje: *Ana* no lo recibe.\n📲 Enviar el comprobante al +54 9 376 511-1111. Para cambios, avisar con 24 hs de anticipación.",
            $texto,
        );
    }
}
