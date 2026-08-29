<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionStatusSuspendidoMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_08_29_120000_widen_subscriptions_status_add_suspendido.php'
        );
    }

    public function test_la_columna_status_acepta_suspendido(): void
    {
        $user = User::factory()->create();

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'SUSPENDIDO',
        ]);

        $this->assertSame('SUSPENDIDO', $subscription->fresh()->status);
    }

    public function test_la_columna_status_rechaza_un_valor_invalido(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('subscriptions')->insert([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'PIRULO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_down_mapea_suspendido_a_vencido_y_vuelve_a_angostar_el_check(): void
    {
        $user = User::factory()->create();

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'ends_at' => now()->addDays(10),
            'status' => 'SUSPENDIDO',
        ]);

        $this->migration()->down();

        $this->assertSame('VENCIDO', $subscription->fresh()->status);

        $rechazado = false;

        try {
            DB::table('subscriptions')->where('id', $subscription->id)->update(['status' => 'SUSPENDIDO']);
        } catch (QueryException) {
            $rechazado = true;
        }

        $this->assertTrue($rechazado, 'El CHECK angostado debería rechazar SUSPENDIDO tras el down().');

        // Restaura el ancho de la columna para no dejar el schema a medias
        // para el resto de la suite (RefreshDatabase migra por test, pero el
        // esquema sqlite :memory: es compartido dentro del test).
        $this->migration()->up();
    }
}
