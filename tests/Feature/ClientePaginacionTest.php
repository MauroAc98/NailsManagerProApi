<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientePaginacionTest extends TestCase
{
    use RefreshDatabase;

    private function crearClientes(User $user, int $cantidad): void
    {
        for ($i = 0; $i < $cantidad; $i++) {
            Cliente::create([
                'user_id'  => $user->id,
                'nombre'   => "Cliente{$i}",
                'apellido' => sprintf('Apellido%02d', $i),
                'telefono' => '+549376' . str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function test_pagina_los_clientes_cuando_se_manda_page(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $this->crearClientes($user, 35);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/clientes?page=1&per_page=10')
            ->assertOk();

        $response->assertJsonCount(10, 'data');
        $response->assertJsonPath('current_page', 1);
        $response->assertJsonPath('last_page', 4);
        $response->assertJsonPath('total', 35);
    }

    public function test_sin_page_devuelve_el_array_plano_de_siempre(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $this->crearClientes($user, 35);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/clientes')
            ->assertOk();

        $response->assertJsonCount(35);
        $response->assertJsonMissingPath('data');
        $response->assertJsonMissingPath('current_page');
    }

    // NOTA: no hay test de `?buscar=` combinado con `?page=` — el WHERE de
    // index() usa ILIKE (Postgres-only), sintaxis inválida en SQLite. El
    // suite entero corre contra sqlite :memory: (ver phpunit.xml), así que
    // NINGÚN test de este repo puede ejercitar el filtro `buscar` tal como
    // está escrito hoy — no es un problema introducido acá, es una brecha de
    // testeabilidad preexistente en el WHERE de index() (compartida con la
    // rama `buscar` que ya usaba ILIKE desde antes de este cambio).
}
