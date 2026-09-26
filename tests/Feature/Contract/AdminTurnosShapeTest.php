<?php

namespace Tests\Feature\Contract;

use App\Models\WhatsappMensaje;

/**
 * Characterization pins for the admin turnos endpoints. They must stay green
 * across the combo-multi-profesional change: only new optional keys may appear.
 */
class AdminTurnosShapeTest extends AdminContractTestCase
{
    private function turnoPayload(string $fechaHora = '2099-06-10 10:00:00'): array
    {
        return [
            'cliente_id' => $this->cliente->id,
            'servicio_ids' => [$this->servicio->id],
            'fecha_hora' => $fechaHora,
            'notas' => 'nota',
        ];
    }

    private function conMensajeDeConfirmacion(int $turnoId): void
    {
        WhatsappMensaje::create([
            'user_id' => $this->user->id, 'turno_id' => $turnoId, 'numero' => '3765252395',
            'provider' => 'cloud_api', 'mensaje' => 'm', 'tipo' => 'confirmacion',
            'message_id' => 'wamid.1', 'status' => 'delivered',
        ]);
    }

    public function test_index_shape(): void
    {
        $turno = $this->crearTurno();
        $this->conMensajeDeConfirmacion($turno->id);

        $json = $this->admin()->getJson('/api/turnos')->assertOk()->json();

        $this->assertContractShape(['*' => $this->turnoShape([
            'estado_visual' => 'string',
            'confirmacion_whatsapp_status' => 'string',
            'reserva_web' => 'array|null',
        ])], $json);
        $this->assertArrayNotHasKey('whatsapp_mensajes', $json[0], 'internal WhatsApp data must stay hidden');
    }

    public function test_show_shape(): void
    {
        $turno = $this->crearTurno();
        $this->conMensajeDeConfirmacion($turno->id);

        $json = $this->admin()->getJson("/api/turnos/{$turno->id}")->assertOk()->json();

        $this->assertContractShape($this->turnoShape([
            'estado_visual' => 'string',
            'confirmacion_whatsapp_status' => 'string',
        ]), $json);
        $this->assertArrayNotHasKey('whatsapp_mensajes', $json);
    }

    public function test_store_shape(): void
    {
        $json = $this->admin()->postJson('/api/turnos', $this->turnoPayload())->assertCreated()->json();

        // A freshly created Turno has no motivo_cancelacion / cancelado_en yet.
        $this->assertContractShape(array_merge(self::TURNO_CORE, [
            'cliente' => self::CLIENTE,
            'servicios' => $this->serviciosDeTurnoShape(),
        ]), $json);
    }

    public function test_store_con_profesional_id_explicito_mantiene_el_mismo_shape(): void
    {
        $json = $this->admin()
            ->postJson('/api/turnos', $this->turnoPayload() + ['profesional_id' => $this->ana->id])
            ->assertCreated()
            ->json();

        $this->assertContractShape(self::TURNO_CORE, $json);
        $this->assertSame($this->ana->id, $json['profesional_id']);
    }

    public function test_update_shape(): void
    {
        $turno = $this->crearTurno();

        $json = $this->admin()
            ->putJson("/api/turnos/{$turno->id}", $this->turnoPayload('2099-06-10 11:00:00'))
            ->assertOk()
            ->json();

        $this->assertContractShape($this->turnoShape(), $json);
    }

    public function test_destroy_shape(): void
    {
        $turno = $this->crearTurno();

        $json = $this->admin()
            ->deleteJson("/api/turnos/{$turno->id}", ['motivo_cancelacion' => 'no puede venir'])
            ->assertOk()
            ->json();

        $this->assertContractShape(['message' => 'string'], $json);
        $this->assertSame('cancelado', $turno->fresh()->estado);
    }

    public function test_completar_shape(): void
    {
        $turno = $this->crearTurno();

        $json = $this->admin()
            ->patchJson("/api/turnos/{$turno->id}/completar", [
                'servicios' => [['servicio_id' => $this->servicio->id, 'precio' => 1500]],
            ])
            ->assertOk()
            ->json();

        $this->assertContractShape($this->turnoShape(), $json);
        $this->assertSame('completado', $json['estado']);
    }

    public function test_precios_shape(): void
    {
        $turno = $this->crearTurno('completado');

        $json = $this->admin()
            ->patchJson("/api/turnos/{$turno->id}/precios", [
                'servicios' => [['servicio_id' => $this->servicio->id, 'precio' => 1800]],
            ])
            ->assertOk()
            ->json();

        $this->assertContractShape($this->turnoShape(), $json);
        $this->assertEquals(1800, $json['servicios'][0]['pivot']['precio']);
    }
}
