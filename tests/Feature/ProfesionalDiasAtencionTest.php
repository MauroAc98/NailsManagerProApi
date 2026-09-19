<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProfesionalDiasAtencionTest extends TestCase
{
    use RefreshDatabase;

    // ── Schema ────────────────────────────────────────────────────

    public function test_profesionales_tiene_columna_dias_atencion_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('profesionales', 'dias_atencion'));

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Ana', 'activo' => true]);

        // Insert crudo (bypass $fillable) para probar la columna en si, no el
        // modelo — la columna debe aceptar NULL sin backfill.
        $this->assertNull(
            DB::table('profesionales')->where('id', $profesional->id)->value('dias_atencion')
        );

        DB::table('profesionales')->where('id', $profesional->id)->update([
            'dias_atencion' => json_encode([1, 2, 3]),
        ]);

        $this->assertSame(
            '[1,2,3]',
            DB::table('profesionales')->where('id', $profesional->id)->value('dias_atencion')
        );
    }

    // ── Profesional::atiendeEl ───────────────────────────────────

    public function test_atiende_el_devuelve_true_para_cualquier_dia_cuando_dias_atencion_es_null(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Ana', 'activo' => true]);

        $this->assertTrue($profesional->atiendeEl(Carbon::parse('2026-09-20'))); // domingo
        $this->assertTrue($profesional->atiendeEl(Carbon::parse('2026-09-21'))); // lunes
    }

    public function test_atiende_el_respeta_la_lista_de_dias_configurados_convencion_carbon(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        // 1 = lunes, 3 = miercoles (convencion Carbon: 0=domingo..6=sabado)
        $profesional = Profesional::create([
            'user_id' => $user->id,
            'nombre' => 'Ana',
            'activo' => true,
            'dias_atencion' => [1, 3],
        ]);

        $this->assertTrue($profesional->atiendeEl(Carbon::parse('2026-09-21')));  // lunes
        $this->assertTrue($profesional->atiendeEl(Carbon::parse('2026-09-23')));  // miercoles
        $this->assertFalse($profesional->atiendeEl(Carbon::parse('2026-09-22'))); // martes
        $this->assertFalse($profesional->atiendeEl(Carbon::parse('2026-09-20'))); // domingo
    }
}
