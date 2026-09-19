<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Profesional;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnoScopeSolapaConTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profesional $prof;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_exempt' => true]);
        $this->prof = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Ana', 'activo' => true]);
    }

    private function turno(string $fechaHora, int $duracion = 60, string $estado = 'confirmado', ?Profesional $prof = null): Turno
    {
        $cliente = Cliente::firstOrCreate(['user_id' => $this->user->id, 'nombre' => 'C'], ['telefono' => '3765252395']);

        return Turno::create([
            'user_id' => $this->user->id,
            'profesional_id' => ($prof ?? $this->prof)->id,
            'cliente_id' => $cliente->id,
            'fecha_hora' => $fechaHora,
            'duracion_total_minutos' => $duracion,
            'estado' => $estado,
            'origen' => 'app',
        ]);
    }

    private function solapan(string $inicio, string $fin): int
    {
        return Turno::solapaCon($inicio, $fin)->count();
    }

    public function test_detecta_solapamiento_parcial_por_ambos_lados(): void
    {
        $this->turno('2099-01-01 10:00:00', 60);

        $this->assertSame(1, $this->solapan('2099-01-01 09:30:00', '2099-01-01 10:30:00'));
        $this->assertSame(1, $this->solapan('2099-01-01 10:30:00', '2099-01-01 11:30:00'));
        $this->assertSame(1, $this->solapan('2099-01-01 10:15:00', '2099-01-01 10:45:00'));
    }

    public function test_intervalos_adyacentes_no_se_solapan(): void
    {
        $this->turno('2099-01-01 10:00:00', 60);

        $this->assertSame(0, $this->solapan('2099-01-01 09:00:00', '2099-01-01 10:00:00'));
        $this->assertSame(0, $this->solapan('2099-01-01 11:00:00', '2099-01-01 12:00:00'));
    }

    public function test_compone_con_scope_por_profesional(): void
    {
        $otra = Profesional::create(['user_id' => $this->user->id, 'nombre' => 'Bea', 'activo' => true]);
        $this->turno('2099-01-01 10:00:00', 60, 'confirmado', $otra);

        $this->assertSame(0, Turno::where('profesional_id', $this->prof->id)
            ->solapaCon('2099-01-01 10:00:00', '2099-01-01 11:00:00')->count());
    }

    public function test_compone_con_confirmados_e_ignora_cancelados(): void
    {
        $this->turno('2099-01-01 10:00:00', 60, 'cancelado');

        $this->assertSame(0, Turno::confirmados()
            ->solapaCon('2099-01-01 10:00:00', '2099-01-01 11:00:00')->count());
    }
}
