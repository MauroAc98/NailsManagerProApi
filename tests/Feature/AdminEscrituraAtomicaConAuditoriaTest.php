<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cada escritura admin sobre una suscripción y su registro de auditoría van
 * en UNA transacción: si el insert de auditoría falla, el cambio se revierte
 * (antes quedaba aplicado pero sin registrar).
 */
class AdminEscrituraAtomicaConAuditoriaTest extends TestCase
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

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function escrituras(): array
    {
        return [
            // [acción, status inicial de la suscripción, body]
            'renew' => ['renew', 'ACTIVO', []],
            'suspend' => ['suspend', 'ACTIVO', []],
            'reactivate' => ['reactivate', 'SUSPENDIDO', []],
            'adjust-expiry' => ['adjust-expiry', 'ACTIVO', ['ends_at' => '2030-01-15']],
        ];
    }

    #[DataProvider('escrituras')]
    public function test_si_falla_la_auditoria_se_revierte_el_cambio(string $accion, string $statusInicial, array $body): void
    {
        $user = User::factory()->create();
        $user->subscription()->create([
            'ends_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'status' => $statusInicial,
        ]);
        $antes = $user->subscription()->first()->getAttributes();

        AdminAuditLog::creating(fn () => throw new \RuntimeException('auditoría caída'));

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/{$accion}", $body)
            ->assertStatus(500);

        $despues = $user->subscription()->first()->getAttributes();
        $this->assertSame($antes['status'], $despues['status']);
        $this->assertSame($antes['ends_at'], $despues['ends_at']);
        $this->assertSame($antes['renewed_at'] ?? null, $despues['renewed_at'] ?? null);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    #[DataProvider('escrituras')]
    public function test_camino_feliz_escribe_el_cambio_y_la_auditoria(string $accion, string $statusInicial, array $body): void
    {
        $user = User::factory()->create();
        $user->subscription()->create([
            'ends_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'status' => $statusInicial,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->postJson("/api/admin/subscriptions/{$user->id}/{$accion}", $body)
            ->assertOk();

        $this->assertDatabaseCount('admin_audit_logs', 1);
    }
}
