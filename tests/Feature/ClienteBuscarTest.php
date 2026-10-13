<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteBuscarTest extends TestCase
{
    use RefreshDatabase;

    private function crearCliente(User $user, string $nombre, string $apellido, string $telefono): Cliente
    {
        return Cliente::create([
            'user_id' => $user->id,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'telefono' => $telefono,
        ]);
    }

    private function idsDeBusqueda(User $user, string $buscar, bool $paginado = false): array
    {
        $url = '/api/clientes?buscar='.urlencode($buscar).($paginado ? '&page=1' : '');
        $json = $this->actingAs($user, 'sanctum')->getJson($url)->assertOk()->json();

        return collect($paginado ? $json['data'] : $json)->pluck('id')->all();
    }

    public function test_busca_por_nombre_o_apellido_sin_distinguir_mayusculas(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $marta = $this->crearCliente($user, 'Marta', 'Rios', '3764111111');
        $jose = $this->crearCliente($user, 'José', 'Paz', '3764222222');

        $this->assertSame([$marta->id], $this->idsDeBusqueda($user, 'MART'));
        $this->assertSame([$marta->id], $this->idsDeBusqueda($user, 'rios'));
        $this->assertSame([$jose->id], $this->idsDeBusqueda($user, 'paz'));
    }

    public function test_busca_por_telefono(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $marta = $this->crearCliente($user, 'Marta', 'Rios', '3764111111');
        $this->crearCliente($user, 'José', 'Paz', '3764222222');

        $this->assertSame([$marta->id], $this->idsDeBusqueda($user, '4111'));
    }

    public function test_varias_palabras_matchean_nombre_y_apellido_juntos(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $marta = $this->crearCliente($user, 'Marta', 'Rios', '3764111111');
        $this->crearCliente($user, 'Marta', 'Paz', '3764222222');   // misma nombre, otro apellido
        $this->crearCliente($user, 'Lucía', 'Rios', '3764333333');   // mismo apellido, otro nombre

        $this->assertSame([$marta->id], $this->idsDeBusqueda($user, 'marta rios'));
        // El orden de las palabras no importa.
        $this->assertSame([$marta->id], $this->idsDeBusqueda($user, 'rios marta'));
        // Espacios de más no rompen la búsqueda.
        $this->assertSame([$marta->id], $this->idsDeBusqueda($user, '  marta   rios  '));
    }

    public function test_funciona_igual_con_paginado(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $marta = $this->crearCliente($user, 'Marta', 'Rios', '3764111111');
        $this->crearCliente($user, 'Marta', 'Paz', '3764222222');

        $this->assertSame([$marta->id], $this->idsDeBusqueda($user, 'marta rios', paginado: true));
    }

    public function test_no_devuelve_clientes_de_otra_cuenta(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otro = User::factory()->create(['is_exempt' => true]);
        $this->crearCliente($otro, 'Marta', 'Rios', '3764111111');

        $this->assertSame([], $this->idsDeBusqueda($user, 'marta rios'));
    }
}
