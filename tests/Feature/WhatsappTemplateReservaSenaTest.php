<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappTemplateReservaSenaTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurnoDeMuestra(User $user, string $nombreProfesional = 'Fernanda'): Turno
    {
        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Martina',
            'apellido' => 'Diaz',
            'telefono' => '+5493765123456',
        ]);

        $servicio = Servicio::create([
            'user_id' => $user->id,
            'nombre' => 'Manicura semipermanente',
            'duracion_minutos' => 60,
            'precio' => 5000,
            'activo' => true,
        ]);

        $profesional = Profesional::create([
            'user_id' => $user->id,
            'nombre' => $nombreProfesional,
            'activo' => true,
        ]);

        $turno = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'profesional_id' => $profesional->id,
            'fecha_hora' => \Illuminate\Support\Carbon::parse('2026-08-20 15:30:00'),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $turno->servicios()->attach($servicio->id);

        return $turno->load(['cliente', 'servicios', 'profesional']);
    }

    private function userConSena(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Nails Studio',
            'is_exempt' => true,
            'direccion' => 'Av. Siempre Viva 742',
            'telefono' => '3765000000',
            'sena_monto' => 5000,
            'whatsapp_pide_sena' => true,
            'whatsapp_sena_titular' => 'Kimberley Macarena Faustino',
            'whatsapp_sena_entidad' => 'Banco Macro SA',
            'whatsapp_sena_alias' => 'Kim1710',
            'whatsapp_sena_cbu' => '2850001040094993682358',
        ], $overrides));
    }

    // ── datosCuentaSena ──────────────────────────────────────────

    public function test_datos_cuenta_sena_con_todos_los_campos(): void
    {
        $user = $this->userConSena();

        $this->assertSame(
            'Kimberley Macarena Faustino · Banco Macro SA · Alias: Kim1710 · CBU: 2850 0010 4009 4993 6823 58',
            WhatsappTemplate::datosCuentaSena($user),
        );
    }

    public function test_datos_cuenta_sena_solo_titular_y_alias(): void
    {
        $user = $this->userConSena([
            'whatsapp_sena_entidad' => null,
            'whatsapp_sena_cbu' => null,
        ]);

        $this->assertSame(
            'Kimberley Macarena Faustino · Alias: Kim1710',
            WhatsappTemplate::datosCuentaSena($user),
        );
    }

    public function test_datos_cuenta_sena_solo_titular_y_cbu(): void
    {
        $user = $this->userConSena([
            'whatsapp_sena_titular' => 'Titular',
            'whatsapp_sena_entidad' => null,
            'whatsapp_sena_alias' => null,
            'whatsapp_sena_cbu' => '2850001040094993682358',
        ]);

        $this->assertSame(
            'Titular · CBU: 2850 0010 4009 4993 6823 58',
            WhatsappTemplate::datosCuentaSena($user),
        );
    }

    public function test_datos_cuenta_sena_nunca_trae_saltos_tabs_ni_espacios_multiples(): void
    {
        foreach ([
            $this->userConSena(),
            $this->userConSena(['whatsapp_sena_entidad' => null, 'whatsapp_sena_cbu' => null]),
            $this->userConSena(['whatsapp_sena_alias' => null]),
        ] as $user) {
            $salida = WhatsappTemplate::datosCuentaSena($user);

            $this->assertStringNotContainsString("\n", $salida);
            $this->assertStringNotContainsString("\t", $salida);
            $this->assertDoesNotMatchRegularExpression('/ {4,}/', $salida);
        }
    }

    public function test_datos_cuenta_sena_colapsa_espacios_saltos_y_tabs_interiores(): void
    {
        $user = $this->userConSena([
            'whatsapp_sena_titular' => "Kimberley\nFaustino",
            'whatsapp_sena_entidad' => "Banco\tMacro",
            'whatsapp_sena_alias' => 'Kim     1710',
            'whatsapp_sena_cbu' => null,
        ]);

        $salida = WhatsappTemplate::datosCuentaSena($user);

        $this->assertSame('Kimberley Faustino · Banco Macro · Alias: Kim 1710', $salida);
        $this->assertStringNotContainsString("\n", $salida);
        $this->assertStringNotContainsString("\t", $salida);
        $this->assertDoesNotMatchRegularExpression('/ {2,}/', $salida);
    }

    public function test_formatea_cbu_corto_o_con_basura_best_effort(): void
    {
        $user = $this->userConSena([
            'whatsapp_sena_titular' => 'Titular',
            'whatsapp_sena_entidad' => null,
            'whatsapp_sena_alias' => null,
            'whatsapp_sena_cbu' => '285000',
        ]);

        $this->assertSame('Titular · CBU: 2850 00', WhatsappTemplate::datosCuentaSena($user));
    }

    // ── nombrePlantillaMeta ──────────────────────────────────────

    public function test_nombre_de_plantilla_meta_para_reserva_sena(): void
    {
        $this->assertSame('reserva_turno_sena', WhatsappTemplate::nombrePlantillaMeta('reserva_sena'));
    }

    // ── parametrosCloudApi ───────────────────────────────────────

    public function test_parametros_reserva_sena_arma_10_elementos_en_orden(): void
    {
        $user = $this->userConSena();
        $turno = $this->crearTurnoDeMuestra($user);

        $parametros = WhatsappTemplate::parametrosCloudApi('reserva_sena', $turno->cliente, $turno, $user);

        $this->assertSame([
            'Martina',
            'Nails Studio',
            '20/08',
            '15:30',
            'Manicura semipermanente',
            'Av. Siempre Viva 742',
            '$5.000,00',
            'Kimberley Macarena Faustino · Banco Macro SA · Alias: Kim1710 · CBU: 2850 0010 4009 4993 6823 58',
            'Fernanda',
            '+54 9 376 500-0000',
        ], $parametros);
    }

    public function test_parametros_reserva_sena_formatea_monto_con_centavos(): void
    {
        $user = $this->userConSena(['sena_monto' => 3500.50]);
        $turno = $this->crearTurnoDeMuestra($user);

        $parametros = WhatsappTemplate::parametrosCloudApi('reserva_sena', $turno->cliente, $turno, $user);

        $this->assertSame('$3.500,50', $parametros[6]);
    }

    public function test_parametros_confirmacion_siguen_siendo_8_elementos(): void
    {
        $user = $this->userConSena();
        $turno = $this->crearTurnoDeMuestra($user);

        $this->assertCount(8, WhatsappTemplate::parametrosCloudApi('confirmacion', $turno->cliente, $turno, $user));
        $this->assertCount(8, WhatsappTemplate::parametrosCloudApi('recordatorio', $turno->cliente, $turno, $user));
    }

    // ── mensajeLegible ───────────────────────────────────────────

    public function test_mensaje_legible_reserva_sena_refleja_la_plantilla_aprobada(): void
    {
        $user = $this->userConSena();
        $turno = $this->crearTurnoDeMuestra($user);

        $resultado = WhatsappTemplate::mensajeLegible('reserva_sena', $turno->cliente, $turno, $user);

        $esperado = <<<'TXT'
            Hola Martina, tu turno en *Nails Studio* quedó reservado.

            🗓️ 20/08 · 🕒 15:30 hs
            ✨ Manicura semipermanente
            📍 Av. Siempre Viva 742

            Para confirmar tu turno se debe abonar una seña de $5.000,00.

            *Datos para el pago:*
            Kimberley Macarena Faustino · Banco Macro SA · Alias: Kim1710 · CBU: 2850 0010 4009 4993 6823 58

            ⚠️ Desde este número solo se envían avisos. Si respondés a este mensaje, *Fernanda no lo recibe y no puede contestarte.*

            Comunicate al +54 9 376 500-0000 para enviar el comprobante de la seña o por consultas y cambios de turno. Los cambios deben avisarse con al menos 24 hs de anticipación.
            TXT;

        $this->assertSame($esperado, $resultado);
    }
}
