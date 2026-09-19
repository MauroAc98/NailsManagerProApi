<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
