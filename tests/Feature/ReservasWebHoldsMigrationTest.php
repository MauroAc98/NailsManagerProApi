<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReservasWebHoldsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_19_100000_add_holds_to_reservas_web_table.php');
    }

    private function fila(User $user, ?Profesional $prof, string $estado, string $hora = '10:00', array $extra = []): array
    {
        return array_merge([
            'user_id'                => $user->id,
            'profesional_id'         => $prof?->id,
            'servicio_ids'           => '[1]',
            'fecha'                  => '2099-06-11',
            'slot_hora'              => $hora,
            'duracion_total_minutos' => 60,
            'estado'                 => $estado,
            'public_token'           => bin2hex(random_bytes(20)),
            'created_at'             => now(),
            'updated_at'             => now(),
        ], $extra);
    }

    private function salon(): array
    {
        $user = User::factory()->create();
        $prof = Profesional::create(['user_id' => $user->id, 'nombre' => 'Ana', 'activo' => true]);

        return [$user, $prof];
    }

    public function test_agrega_las_columnas_y_hace_nullables_nombre_completo_y_telefono(): void
    {
        foreach ([
            'public_token', 'profesional_id', 'expira_en', 'pago_extendido', 'alta_ocupacion',
            'device_hash', 'idempotency_key', 'nombre', 'apellido', 'nota',
            'requiere_reembolso', 'confirmada_en', 'motivo_cierre',
        ] as $col) {
            $this->assertTrue(Schema::hasColumn('reservas_web', $col), $col);
        }
        $this->assertTrue(Schema::hasTable('reserva_reputaciones'));

        [$user, $prof] = $this->salon();
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'held'));

        $this->assertNull(DB::table('reservas_web')->first()->nombre_completo);
    }

    public function test_el_estado_admite_los_estados_nuevos(): void
    {
        [$user, $prof] = $this->salon();
        foreach (['held', 'pending_payment', 'confirmed', 'expired', 'cancelled', 'accepted', 'rejected'] as $i => $estado) {
            DB::table('reservas_web')->insert($this->fila($user, $prof, $estado, sprintf('%02d:00', 8 + $i)));
        }

        $this->assertSame(7, DB::table('reservas_web')->count());
    }

    public function test_el_indice_unico_parcial_rechaza_un_duplicado_vivo(): void
    {
        [$user, $prof] = $this->salon();
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'held'));

        $this->expectException(QueryException::class);
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'pending_payment'));
    }

    public function test_el_indice_unico_parcial_permite_duplicado_si_uno_esta_expirado_o_cancelado(): void
    {
        [$user, $prof] = $this->salon();
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'expired'));
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'cancelled'));
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'held'));
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'confirmed'));

        $this->assertSame(4, DB::table('reservas_web')->count());
    }

    public function test_el_indice_unico_parcial_no_cruza_profesionales(): void
    {
        [$user, $prof] = $this->salon();
        $otra = Profesional::create(['user_id' => $user->id, 'nombre' => 'Bea', 'activo' => true]);
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'held'));
        DB::table('reservas_web')->insert($this->fila($user, $otra, 'held'));

        $this->assertSame(2, DB::table('reservas_web')->count());
    }

    public function test_el_token_publico_es_unico(): void
    {
        [$user, $prof] = $this->salon();
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'held', '10:00', ['public_token' => 'x']));

        $this->expectException(QueryException::class);
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'held', '11:00', ['public_token' => 'x']));
    }

    public function test_down_mapea_estados_nuevos_y_quita_las_columnas(): void
    {
        [$user, $prof] = $this->salon();
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'held', '09:00'));
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'confirmed', '10:00'));
        DB::table('reservas_web')->insert($this->fila($user, $prof, 'cancelled', '11:00'));

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('reservas_web', 'public_token'));
        $this->assertFalse(Schema::hasTable('reserva_reputaciones'));
        $estados = DB::table('reservas_web')->orderBy('slot_hora')->pluck('estado')->all();
        $this->assertSame(['pending_payment', 'accepted', 'rejected'], $estados);
        $this->assertSame('', DB::table('reservas_web')->first()->nombre_completo);

        // Vuelve a subir sin romper (reversible en ambos sentidos).
        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('reservas_web', 'public_token'));
    }
}
