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

class WhatsappTemplateParametrosCloudApiTest extends TestCase
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

    public function test_arma_los_parametros_en_orden_para_recordatorio(): void
    {
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'direccion' => 'Av. Siempre Viva 742', 'telefono' => '3765000000']);
        $turno = $this->crearTurnoDeMuestra($user);

        $parametros = WhatsappTemplate::parametrosCloudApi('recordatorio', $turno->cliente, $turno, $user);

        $this->assertSame([
            'Martina',
            'Nails Studio',
            '20/08',
            '15:30',
            'Manicura semipermanente',
            'Av. Siempre Viva 742',
            'Fernanda',
            '+54 9 376 500-0000',
        ], $parametros);
    }

    public function test_arma_los_parametros_en_orden_para_confirmacion(): void
    {
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'direccion' => 'Av. Siempre Viva 742', 'telefono' => '3765000000']);
        $turno = $this->crearTurnoDeMuestra($user);

        $parametros = WhatsappTemplate::parametrosCloudApi('confirmacion', $turno->cliente, $turno, $user);

        // Orden unificado: mismo orden para ambos tipos (antes difería entre
        // recordatorio y confirmacion, ahora las dos plantillas de Meta
        // comparten el mismo layout con la línea de contacto al final).
        $this->assertSame([
            'Martina',
            'Nails Studio',
            '20/08',
            '15:30',
            'Manicura semipermanente',
            'Av. Siempre Viva 742',
            'Fernanda',
            '+54 9 376 500-0000',
        ], $parametros);
    }

    public function test_telefono_vacio_devuelve_string_vacio_en_la_ultima_posicion(): void
    {
        // Sin fallback: Meta rechaza el envío completo si un parámetro de
        // plantilla llega vacío. Es intencional — el envío falla y queda
        // registrado como status=failed por el manejo de error que ya
        // existe en CloudApiService::enviarPlantilla().
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'direccion' => 'Av. Siempre Viva 742', 'telefono' => null]);
        $turno = $this->crearTurnoDeMuestra($user);

        $parametros = WhatsappTemplate::parametrosCloudApi('confirmacion', $turno->cliente, $turno, $user);

        $this->assertCount(8, $parametros);
        $this->assertSame('', $parametros[7]);
    }

    public function test_direccion_vacia_devuelve_string_vacio_en_su_posicion(): void
    {
        // Mismo criterio sin-fallback que telefono. En la práctica está
        // cubierto por el guard de AuthController::updatePerfil() que
        // impide activar los envíos automáticos sin dirección — pero el
        // helper en sí no debe asumir que siempre va a llegar cargada.
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'direccion' => null, 'telefono' => '3765000000']);
        $turno = $this->crearTurnoDeMuestra($user);

        $parametros = WhatsappTemplate::parametrosCloudApi('confirmacion', $turno->cliente, $turno, $user);

        $this->assertCount(8, $parametros);
        $this->assertSame('', $parametros[5]);
    }

    public function test_profesional_sin_relacion_cargada_devuelve_string_vacio_en_su_posicion(): void
    {
        // Mismo criterio sin-fallback. En la práctica está cubierto por el
        // backfill de profesional_id (ver
        // 2026_07_17_100004_backfill_default_profesionales) y por
        // Profesional::resolverParaUsuario() — pero el helper en sí no debe
        // asumir que turno->profesional siempre va a resolver a algo.
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'direccion' => 'Av. Siempre Viva 742', 'telefono' => '3765000000']);
        $turno = $this->crearTurnoDeMuestra($user);
        $turno->profesional_id = null;
        $turno->setRelation('profesional', null);

        $parametros = WhatsappTemplate::parametrosCloudApi('confirmacion', $turno->cliente, $turno, $user);

        $this->assertCount(8, $parametros);
        $this->assertSame('', $parametros[6]);
    }

    public function test_profesional_con_nombre_compuesto_usa_solo_el_primero(): void
    {
        // Pedido explícito: "Evelin Soledad" en la plantilla tiene que
        // sonar cercano ("hablaste con Evelin"), no formal/impersonal
        // ("hablaste con Evelin Soledad").
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'direccion' => 'Av. Siempre Viva 742', 'telefono' => '3765000000']);
        $turno = $this->crearTurnoDeMuestra($user, 'Evelin Soledad');

        $parametros = WhatsappTemplate::parametrosCloudApi('confirmacion', $turno->cliente, $turno, $user);

        $this->assertSame('Evelin', $parametros[6]);
    }

    public function test_telefono_se_normaliza_a_formato_internacional(): void
    {
        // El teléfono va DENTRO del cuerpo de la plantilla Meta; WhatsApp
        // solo lo vuelve tappable si está en formato internacional
        // (+54 9 …). formatearTelefono normaliza distintas formas de cargar
        // el mismo número de Misiones (área 376) al mismo string canónico.
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'direccion' => 'Av. Siempre Viva 742']);
        $turno = $this->crearTurnoDeMuestra($user);

        $entradas = [
            '3765000000',      // nacional sin 9 ni país
            '376 500-0000',    // como lo muestra el front (formatDisplay)
            '+543765000000',   // país sin 9
            '543765000000',    // país sin 9, sin +
            '5493765000000',   // país + 9 (móvil canónico)
        ];

        foreach ($entradas as $entrada) {
            $user->telefono = $entrada;
            $parametros = WhatsappTemplate::parametrosCloudApi('confirmacion', $turno->cliente, $turno, $user);
            $this->assertSame('+54 9 376 500-0000', $parametros[7], "Entrada: {$entrada}");
        }
    }

    public function test_telefono_corto_se_devuelve_sin_formatear(): void
    {
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'direccion' => 'Av. Siempre Viva 742', 'telefono' => '12345']);
        $turno = $this->crearTurnoDeMuestra($user);

        $parametros = WhatsappTemplate::parametrosCloudApi('confirmacion', $turno->cliente, $turno, $user);

        $this->assertSame('12345', $parametros[7]);
    }

    public function test_telefono_sin_largo_valido_se_devuelve_tal_cual(): void
    {
        // Fallback best-effort: si no se puede derivar un número nacional
        // de 10 dígitos, sale el valor original sin tocar (no tappable pero
        // no roto) — mismo criterio sin-fallback que hoy.
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'direccion' => 'Av. Siempre Viva 742', 'telefono' => '1234567']);
        $turno = $this->crearTurnoDeMuestra($user);

        $parametros = WhatsappTemplate::parametrosCloudApi('confirmacion', $turno->cliente, $turno, $user);

        $this->assertSame('1234567', $parametros[7]);
    }

    public function test_nombre_de_plantilla_meta_segun_tipo(): void
    {
        $this->assertSame('recordatorio_turno', WhatsappTemplate::nombrePlantillaMeta('recordatorio'));
        $this->assertSame('confirmacion_turno', WhatsappTemplate::nombrePlantillaMeta('confirmacion'));
    }

    public function test_mensaje_legible_incluye_los_valores_reales(): void
    {
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'direccion' => 'Av. Siempre Viva 742', 'telefono' => '3765000000']);
        $turno = $this->crearTurnoDeMuestra($user);

        $resultado = WhatsappTemplate::mensajeLegible('confirmacion', $turno->cliente, $turno, $user);

        $this->assertStringContainsString('Martina', $resultado);
        $this->assertStringContainsString('Nails Studio', $resultado);
        $this->assertStringContainsString('20/08', $resultado);
        $this->assertStringContainsString('15:30', $resultado);
        $this->assertStringContainsString('Manicura semipermanente', $resultado);
        $this->assertStringContainsString('Av. Siempre Viva 742', $resultado);
        $this->assertStringContainsString('376 500-0000', $resultado);
        $this->assertStringContainsString('hablaste con Fernanda previamente', $resultado);
        $this->assertStringContainsString('mensaje automático, no hace falta responder', $resultado);
    }
}
