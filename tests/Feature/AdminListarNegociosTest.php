<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminListarNegociosTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'Superadmin',
            'email' => 'admin@turnetto.app',
            'password' => 'password-de-test',
        ]);
    }

    public function test_rechaza_sin_sesion_admin(): void
    {
        $this->getJson('/api/admin/negocios')
            ->assertStatus(401);
    }

    public function test_admin_lista_todos_los_negocios_con_la_forma_esperada(): void
    {
        $user = User::factory()->create(['email' => 'match@estudio.com']);
        $user->subscription()->create(['ends_at' => now()->addDays(10), 'status' => 'ACTIVO']);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/negocios')
            ->assertOk();

        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'id' => $user->id,
            'email' => 'match@estudio.com',
            'slug' => $user->slug,
        ]);
        $response->assertJsonStructure([['id', 'name', 'slug', 'email', 'is_exempt', 'subscription' => ['ends_at', 'status', 'renewed_at']]]);
    }

    // No pagination for v1 — only 6 negocios exist in production, keep the
    // response a plain JSON array (see AdminController::listarNegocios()).
    public function test_devuelve_todos_los_negocios_sin_paginar(): void
    {
        User::factory()->count(7)->create();

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/negocios')
            ->assertOk();

        $response->assertJsonCount(7);
    }

    // Orden = workflow real del admin: quién vence antes va primero. Un
    // negocio sin suscripción no debe romper el sort.
    public function test_ordena_por_ends_at_ascendente_soonest_first(): void
    {
        $vencePronto = User::factory()->create(['name' => 'Vence Pronto']);
        $vencePronto->subscription()->create(['ends_at' => now()->addDays(2), 'status' => 'ACTIVO']);

        $venceTarde = User::factory()->create(['name' => 'Vence Tarde']);
        $venceTarde->subscription()->create(['ends_at' => now()->addDays(30), 'status' => 'ACTIVO']);

        $venceMedio = User::factory()->create(['name' => 'Vence Medio']);
        $venceMedio->subscription()->create(['ends_at' => now()->addDays(10), 'status' => 'ACTIVO']);

        $response = $this->actingAs($this->admin, 'admin')
            ->getJson('/api/admin/negocios')
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertSame(
            [$vencePronto->id, $venceMedio->id, $venceTarde->id],
            $ids,
        );
    }
}
