<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

// TODO(reserva-online slice 3): eliminar este test junto con el guard temporal
// de PublicController::store y config('reservas.creacion_habilitada').
class PublicReservasGuardTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private function payload(int $servicioId): array
    {
        return [
            'nombre_completo' => 'Clienta Web',
            'telefono'        => '+5491155551234',
            'servicio_ids'    => [$servicioId],
            'fecha'           => '2099-06-11',
            'slot_hora'       => '12:00',
        ];
    }

    public function test_con_el_flag_apagado_devuelve_503_y_no_crea_reserva(): void
    {
        config(['reservas.creacion_habilitada' => false]);
        $user = $this->crearSalon();
        $s = $this->crearServicio($user);

        $this->postJson("/api/public/{$user->slug}/reservas", $this->payload($s->id))
            ->assertStatus(503)
            ->assertJsonPath('message', 'La reserva online todavía no está disponible.');

        $this->assertDatabaseCount('reservas_web', 0);
    }

    public function test_por_defecto_el_flag_esta_apagado(): void
    {
        $this->assertFalse(config('reservas.creacion_habilitada'));
    }

    public function test_con_el_flag_encendido_el_flujo_existente_crea_la_reserva(): void
    {
        config(['reservas.creacion_habilitada' => true]);
        $user = $this->crearSalon();
        $s = $this->crearServicio($user);

        $this->postJson("/api/public/{$user->slug}/reservas", $this->payload($s->id))
            ->assertStatus(201);

        $this->assertDatabaseCount('reservas_web', 1);
    }
}
