<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteWhatsappOptOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_salon_puede_reactivar_a_una_clienta_que_dio_de_baja(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $cliente = Cliente::create([
            'user_id' => $user->id,
            'nombre' => 'Sofía',
            'telefono' => '3765252395',
            'whatsapp_opt_out' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/clientes/{$cliente->id}", ['whatsapp_opt_out' => false])
            ->assertOk();

        $this->assertFalse($cliente->fresh()->whatsapp_opt_out);
    }
}
