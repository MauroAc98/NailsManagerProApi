<?php

namespace Tests\Feature;

use App\Models\Profesional;
use App\Models\ReservaWeb;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class ReservaWebModelTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private function hold(User $user, array $attrs = []): ReservaWeb
    {
        return ReservaWeb::create(array_merge([
            'user_id'                => $user->id,
            'servicio_ids'           => [1],
            'fecha'                  => '2099-06-11',
            'slot_hora'              => '10:00:00',
            'duracion_total_minutos' => 60,
            'estado'                 => 'held',
            'public_token'           => ReservaWeb::generarToken(),
            'expira_en'              => 2000,
        ], $attrs));
    }

    public function test_fecha_queda_como_string_plano(): void
    {
        $user = $this->crearSalon();
        $r = $this->hold($user);

        $this->assertSame('2099-06-11', $r->fresh()->fecha);
        $this->assertSame('2099-06-11', DB_fecha($r));
    }

    public function test_generar_token_da_40_caracteres_alfanumericos_distintos(): void
    {
        $a = ReservaWeb::generarToken();
        $b = ReservaWeb::generarToken();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $a);
        $this->assertNotSame($a, $b);
    }

    public function test_bloqueantes_son_held_y_pending_payment(): void
    {
        $user = $this->crearSalon();
        $prof = $this->crearProfesional($user);
        foreach (['held', 'pending_payment', 'confirmed', 'expired', 'cancelled'] as $i => $estado) {
            $this->hold($user, ['estado' => $estado, 'profesional_id' => $prof->id, 'slot_hora' => sprintf('%02d:00:00', 8 + $i)]);
        }

        $estados = ReservaWeb::bloqueantes()->orderBy('slot_hora')->pluck('estado')->all();

        $this->assertSame(['held', 'pending_payment'], $estados);
    }

    public function test_vivos_exige_expira_en_futuro(): void
    {
        $user = $this->crearSalon();
        $prof = $this->crearProfesional($user);
        $this->hold($user, ['profesional_id' => $prof->id, 'slot_hora' => '09:00:00', 'expira_en' => 1000]);
        $this->hold($user, ['profesional_id' => $prof->id, 'slot_hora' => '10:00:00', 'expira_en' => 1001]);
        $this->hold($user, ['profesional_id' => $prof->id, 'slot_hora' => '11:00:00', 'expira_en' => 999]);

        $horas = ReservaWeb::vivos(1000)->orderBy('slot_hora')->pluck('slot_hora')->all();

        $this->assertCount(1, $horas);
        $this->assertStringStartsWith('10:00', $horas[0]);
    }

    public function test_pendientes_legacy_excluye_las_filas_con_token(): void
    {
        $user = $this->crearSalon();
        $prof = $this->crearProfesional($user);
        $legacy = $this->hold($user, ['estado' => 'pending_payment', 'public_token' => null, 'slot_hora' => '09:00:00']);
        $this->hold($user, ['estado' => 'pending_payment', 'profesional_id' => $prof->id, 'slot_hora' => '10:00:00']);

        $this->assertSame([$legacy->id], ReservaWeb::pendientes()->pluck('id')->all());
    }

    public function test_el_panel_no_lista_ni_acepta_holds_nuevos(): void
    {
        $user = $this->crearSalon();
        $this->crearSuscripcion($user);
        $prof = $this->crearProfesional($user);
        $nuevo = $this->hold($user, ['estado' => 'pending_payment', 'profesional_id' => $prof->id, 'slot_hora' => '11:00:00']);
        Sanctum::actingAs($user);

        // (El panel legacy ya falla con filas propias por PagoSena sin $table: bug previo, fuera de alcance.)
        $this->getJson('/api/reservas')->assertOk()->assertExactJson([]);
        $this->postJson("/api/reservas/{$nuevo->id}/aceptar")->assertNotFound();
        $this->postJson("/api/reservas/{$nuevo->id}/rechazar")->assertNotFound();
        $this->assertSame('pending_payment', $nuevo->fresh()->estado);
    }
}

function DB_fecha(ReservaWeb $r): string
{
    return substr((string) \Illuminate\Support\Facades\DB::table('reservas_web')->where('id', $r->id)->value('fecha'), 0, 10);
}
