<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Servicio;
use App\Models\Turno;
use App\Models\User;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappTemplateParametrosCloudApiTest extends TestCase
{
    use RefreshDatabase;

    private function crearTurnoDeMuestra(User $user): Turno
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

        $turno = Turno::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => \Illuminate\Support\Carbon::parse('2026-08-20 15:30:00'),
            'duracion_total_minutos' => 60,
            'estado' => 'confirmado',
            'origen' => 'app',
        ]);

        $turno->servicios()->attach($servicio->id);

        return $turno->load(['cliente', 'servicios']);
    }

    public function test_arma_los_parametros_en_orden_para_recordatorio(): void
    {
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'telefono' => '3765000000']);
        $turno = $this->crearTurnoDeMuestra($user);

        $parametros = WhatsappTemplate::parametrosCloudApi('recordatorio', $turno->cliente, $turno, $user);

        $this->assertSame([
            'Martina',
            'Nails Studio',
            '20/08',
            '15:30',
            'Manicura semipermanente',
            '3765000000',
        ], $parametros);
    }

    public function test_arma_los_parametros_en_orden_para_confirmacion(): void
    {
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'telefono' => '3765000000']);
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
            '3765000000',
        ], $parametros);
    }

    public function test_telefono_vacio_devuelve_string_vacio_en_la_ultima_posicion(): void
    {
        // Sin fallback: Meta rechaza el envío completo si un parámetro de
        // plantilla llega vacío. Es intencional — el envío falla y queda
        // registrado como status=failed por el manejo de error que ya
        // existe en CloudApiService::enviarPlantilla().
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'telefono' => null]);
        $turno = $this->crearTurnoDeMuestra($user);

        $parametros = WhatsappTemplate::parametrosCloudApi('confirmacion', $turno->cliente, $turno, $user);

        $this->assertCount(6, $parametros);
        $this->assertSame('', $parametros[5]);
    }

    public function test_nombre_de_plantilla_meta_segun_tipo(): void
    {
        $this->assertSame('recordatorio_turno', WhatsappTemplate::nombrePlantillaMeta('recordatorio'));
        $this->assertSame('confirmacion_turno', WhatsappTemplate::nombrePlantillaMeta('confirmacion'));
    }

    public function test_procesar_plantilla_reemplaza_telefono(): void
    {
        $user = User::factory()->create(['name' => 'Nails Studio', 'is_exempt' => true, 'telefono' => '3765000000']);
        $turno = $this->crearTurnoDeMuestra($user);

        $resultado = WhatsappTemplate::procesarPlantilla(
            'Dudas o consultas: {telefono}',
            $turno->cliente,
            $turno,
            $user,
        );

        $this->assertSame('Dudas o consultas: 3765000000', $resultado);
    }
}
