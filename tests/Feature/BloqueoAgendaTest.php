<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BloqueoAgendaTest extends TestCase
{
    use RefreshDatabase;

    // ── Schema ────────────────────────────────────────────────────

    public function test_tabla_bloqueos_agenda_tiene_las_columnas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('bloqueos_agenda'));
        $this->assertTrue(Schema::hasColumns('bloqueos_agenda', [
            'id', 'user_id', 'profesional_id', 'fecha', 'hora_desde', 'hora_hasta', 'motivo', 'created_at', 'updated_at',
        ]));
    }

    public function test_profesional_id_es_nullable_para_bloqueo_de_todo_el_salon(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);

        $id = DB::table('bloqueos_agenda')->insertGetId([
            'user_id' => $user->id,
            'profesional_id' => null,
            'fecha' => '2099-12-24',
            'hora_desde' => null,
            'hora_hasta' => null,
            'motivo' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(DB::table('bloqueos_agenda')->where('id', $id)->value('profesional_id'));
    }

    public function test_borrar_la_profesional_borra_en_cascada_sus_bloqueos(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Ana', 'activo' => true]);

        $id = DB::table('bloqueos_agenda')->insertGetId([
            'user_id' => $user->id,
            'profesional_id' => $profesional->id,
            'fecha' => '2099-12-24',
            'hora_desde' => null,
            'hora_hasta' => null,
            'motivo' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profesional->delete();

        $this->assertDatabaseMissing('bloqueos_agenda', ['id' => $id]);
    }

    // ── Model scopes ──────────────────────────────────────────────

    public function test_scope_del_usuario_solo_trae_bloqueos_de_la_cuenta(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $otroUsuario = User::factory()->create(['is_exempt' => true]);

        $propio = BloqueoAgenda::create(['user_id' => $user->id, 'fecha' => '2099-12-24']);
        BloqueoAgenda::create(['user_id' => $otroUsuario->id, 'fecha' => '2099-12-24']);

        $resultado = BloqueoAgenda::delUsuario($user)->get();

        $this->assertCount(1, $resultado);
        $this->assertSame($propio->id, $resultado->first()->id);
    }

    public function test_scope_aplica_a_matchea_salon_wide_o_la_profesional_dada(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $ana = Profesional::create(['user_id' => $user->id, 'nombre' => 'Ana', 'activo' => true]);
        $bea = Profesional::create(['user_id' => $user->id, 'nombre' => 'Bea', 'activo' => true]);

        $salonWide = BloqueoAgenda::create(['user_id' => $user->id, 'profesional_id' => null, 'fecha' => '2099-12-24']);
        $deAna = BloqueoAgenda::create(['user_id' => $user->id, 'profesional_id' => $ana->id, 'fecha' => '2099-12-24']);
        BloqueoAgenda::create(['user_id' => $user->id, 'profesional_id' => $bea->id, 'fecha' => '2099-12-24']);

        $paraAna = BloqueoAgenda::aplicaA($ana->id)->get()->pluck('id')->sort()->values()->all();

        $this->assertSame([$salonWide->id, $deAna->id], collect($paraAna)->sort()->values()->all());
    }
}
