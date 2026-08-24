<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClienteTelefonoValidoTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────
    // Caso real de producción: la profesional tipeó "+54583295" sin el
    // código de área, y la regex vieja (regex:/^\+[1-9]\d{7,14}$/) lo
    // aceptaba porque solo contaba dígitos — el envío de WhatsApp fallaba
    // en silencio. Debería haber sido "+543764583295".
    // ─────────────────────────────────────────────
    public function test_rechaza_un_numero_argentino_truncado_sin_codigo_de_area(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/clientes', [
                'nombre' => 'Sofía',
                'apellido' => 'Gómez',
                'telefono' => '+54583295',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('telefono');
    }

    public static function numerosValidosPorPais(): array
    {
        return [
            'Argentina' => ['+543764583295'],
            'Brasil' => ['+5511987654321'],
            'Uruguay' => ['+59894123456'],
            'Paraguay' => ['+595981234567'],
            'Chile' => ['+56987654321'],
            'Bolivia' => ['+59171234567'],
        ];
    }

    #[DataProvider('numerosValidosPorPais')]
    public function test_acepta_un_numero_valido_de_cada_pais_soportado(string $telefono): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/clientes', [
                'nombre' => 'Sofía',
                'apellido' => 'Gómez',
                'telefono' => $telefono,
            ]);

        $response->assertCreated();
    }

    public function test_rechaza_un_numero_sintacticamente_valido_de_un_pais_no_soportado(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/clientes', [
                'nombre' => 'Sofía',
                'apellido' => 'Gómez',
                // Número de EE.UU. real y válido, pero de un país no soportado.
                'telefono' => '+14155552671',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('telefono');
    }

    public function test_update_rechaza_un_numero_argentino_truncado_sin_codigo_de_area(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $cliente = $user->clientes()->create([
            'nombre' => 'Sofía',
            'apellido' => 'Gómez',
            'telefono' => '+543764583295',
            'activo' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/clientes/{$cliente->id}", [
                'telefono' => '+54583295',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('telefono');
    }

    #[DataProvider('numerosValidosPorPais')]
    public function test_update_acepta_un_numero_valido_de_cada_pais_soportado(string $telefono): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $cliente = $user->clientes()->create([
            'nombre' => 'Sofía',
            'apellido' => 'Gómez',
            'telefono' => '+543764583295',
            'activo' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/clientes/{$cliente->id}", [
                'telefono' => $telefono,
            ]);

        $response->assertOk();
    }

    public function test_update_rechaza_un_numero_de_un_pais_no_soportado(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $cliente = $user->clientes()->create([
            'nombre' => 'Sofía',
            'apellido' => 'Gómez',
            'telefono' => '+543764583295',
            'activo' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/clientes/{$cliente->id}", [
                'telefono' => '+14155552671',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('telefono');
    }
}
