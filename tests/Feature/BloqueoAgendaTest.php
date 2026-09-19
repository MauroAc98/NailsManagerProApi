<?php

namespace Tests\Feature;

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
}
