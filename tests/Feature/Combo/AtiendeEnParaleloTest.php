<?php

namespace Tests\Feature\Combo;

use App\Models\Servicio;
use App\Models\User;
use Tests\Feature\Contract\AdminContractTestCase;

/**
 * Studio setting "atiende en paralelo" (combo-multi-profesional, PR 1a):
 * persisted through the existing PUT /perfil, exposed on GET auth/me, and it
 * cannot be turned OFF while an active parallel promo exists (DQ3).
 */
class AtiendeEnParaleloTest extends AdminContractTestCase
{
    private function crearPromo(User $dueno, string $nombre, ?string $modo, bool $activo = true): Servicio
    {
        return Servicio::create([
            'user_id' => $dueno->id,
            'nombre' => $nombre,
            'duracion_minutos' => 60,
            'precio' => 18000,
            'activo' => $activo,
            'es_promo' => true,
            'modo_promo' => $modo,
        ]);
    }

    private function encender(): void
    {
        $this->user->forceFill(['atiende_en_paralelo' => true])->save();
    }

    // 1a.1 — Rule L: the legacy request (field absent) is unchanged.
    public function test_legacy_put_perfil_without_the_field_keeps_every_existing_value(): void
    {
        $antes = $this->admin()->getJson('/api/auth/me')->assertOk()->json();

        $despues = $this->admin()->putJson('/api/perfil', ['name' => 'Nuevo Nombre'])->assertOk()->json();

        $this->assertSame('Nuevo Nombre', $despues['name']);
        foreach ($antes as $clave => $valor) {
            if (in_array($clave, ['name', 'updated_at'], true)) {
                continue;
            }
            $this->assertArrayHasKey($clave, $despues, "PUT /perfil dropped '{$clave}'");
            $this->assertSame($valor, $despues[$clave], "PUT /perfil changed '{$clave}'");
        }
    }

    // 1a.2
    public function test_default_is_false_on_me(): void
    {
        $json = $this->admin()->getJson('/api/auth/me')->assertOk()->json();

        $this->assertArrayHasKey('atiende_en_paralelo', $json);
        $this->assertFalse($json['atiende_en_paralelo']);
    }

    public function test_put_true_persists_and_is_exposed_by_perfil_and_me(): void
    {
        $put = $this->admin()->putJson('/api/perfil', ['atiende_en_paralelo' => true])->assertOk()->json();

        $this->assertTrue($put['atiende_en_paralelo']);
        $this->assertTrue($this->user->fresh()->atiende_en_paralelo);
        $this->assertTrue($this->admin()->getJson('/api/auth/me')->json('atiende_en_paralelo'));
    }

    public function test_put_rejects_a_non_boolean_value(): void
    {
        $this->admin()->putJson('/api/perfil', ['atiende_en_paralelo' => 'quizas'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('atiende_en_paralelo');
    }

    // 1a.3 — turn-off guard
    public function test_turning_off_is_blocked_while_an_active_parallel_promo_exists(): void
    {
        $this->encender();
        $promo = $this->crearPromo($this->user, 'Softgel + Semis', 'paralelo');

        $res = $this->admin()->putJson('/api/perfil', ['atiende_en_paralelo' => false])->assertStatus(422);

        $res->assertJsonPath('code', 'promos_paralelas_activas');
        $res->assertJsonPath('promos.0.id', $promo->id);
        $res->assertJsonPath('promos.0.nombre', 'Softgel + Semis');
        $this->assertIsString($res->json('message'));
        $this->assertTrue($this->user->fresh()->atiende_en_paralelo, 'the setting must stay ON');
    }

    public function test_the_block_lists_every_parallel_promo_and_saves_nothing_else(): void
    {
        $this->encender();
        $this->crearPromo($this->user, 'Promo A', 'paralelo');
        $this->crearPromo($this->user, 'Promo B', 'paralelo');

        $res = $this->admin()
            ->putJson('/api/perfil', ['atiende_en_paralelo' => false, 'telefono' => '3765999999'])
            ->assertStatus(422);

        $this->assertCount(2, $res->json('promos'));
        $this->assertNotSame('3765999999', $this->user->fresh()->telefono, 'a rejected request must not partially save');
    }

    public function test_turning_off_is_allowed_with_only_sequential_or_legacy_promos(): void
    {
        $this->encender();
        $this->crearPromo($this->user, 'Secuencia', 'secuencia');
        $this->crearPromo($this->user, 'Legacy', null);

        $this->admin()->putJson('/api/perfil', ['atiende_en_paralelo' => false])
            ->assertOk()
            ->assertJsonPath('atiende_en_paralelo', false);
        $this->assertFalse($this->user->fresh()->atiende_en_paralelo);
    }

    public function test_an_inactive_parallel_promo_does_not_block_turning_off(): void
    {
        $this->encender();
        $this->crearPromo($this->user, 'Vieja', 'paralelo', activo: false);

        $this->admin()->putJson('/api/perfil', ['atiende_en_paralelo' => false])->assertOk();
    }

    public function test_parallel_promos_of_other_tenants_are_ignored(): void
    {
        $this->encender();
        $otro = User::factory()->create();
        $this->crearPromo($otro, 'Ajena', 'paralelo');

        $this->admin()->putJson('/api/perfil', ['atiende_en_paralelo' => false])->assertOk();
    }

    public function test_turning_off_when_already_off_is_idempotent_even_with_parallel_promos(): void
    {
        $this->crearPromo($this->user, 'Paralelo', 'paralelo');

        $this->admin()->putJson('/api/perfil', ['atiende_en_paralelo' => false])
            ->assertOk()
            ->assertJsonPath('atiende_en_paralelo', false);
    }

    public function test_turning_on_is_never_blocked(): void
    {
        $this->crearPromo($this->user, 'Paralelo', 'paralelo');

        $this->admin()->putJson('/api/perfil', ['atiende_en_paralelo' => true])
            ->assertOk()
            ->assertJsonPath('atiende_en_paralelo', true);
    }
}
