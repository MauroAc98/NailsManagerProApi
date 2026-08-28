<?php

namespace Tests\Feature;

use App\Actions\GuardEmbeddedSignup;
use App\Exceptions\EmbeddedSignupDeshabilitadoException;
use App\Exceptions\UsuarioNoHabilitadoException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * La gate de Advanced Access vive DENTRO del seam reusable (design §7, A3):
 * GuardEmbeddedSignup es invocada como primer paso de
 * EmbeddedSignupService::conectar(), de modo que todo caller — incluido un
 * futuro auth:sanctum de self-service — queda gateado. El path GET de estado
 * nunca llama a conectar(), así que nunca pasa por esta guarda.
 */
class WhatsappEsGateTest extends TestCase
{
    use RefreshDatabase;

    private function configurarGate(bool $enabled, array $allowedUserIds = [], bool $allowAll = false): void
    {
        config([
            'services.whatsapp_es.enabled' => $enabled,
            'services.whatsapp_es.allowed_user_ids' => $allowedUserIds,
            'services.whatsapp_es.allow_all' => $allowAll,
        ]);
    }

    public function test_gate_deshabilitada_lanza_403(): void
    {
        $this->configurarGate(enabled: false);
        $user = User::factory()->create();

        try {
            app(GuardEmbeddedSignup::class)->verificar($user);
            $this->fail('Se esperaba EmbeddedSignupDeshabilitadoException.');
        } catch (EmbeddedSignupDeshabilitadoException $e) {
            $this->assertInstanceOf(HttpExceptionInterface::class, $e);
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_usuario_fuera_de_la_allowlist_con_allow_all_false_lanza_403(): void
    {
        $permitido = User::factory()->create();
        $bloqueado = User::factory()->create();
        $this->configurarGate(enabled: true, allowedUserIds: [$permitido->id], allowAll: false);

        try {
            app(GuardEmbeddedSignup::class)->verificar($bloqueado);
            $this->fail('Se esperaba UsuarioNoHabilitadoException.');
        } catch (UsuarioNoHabilitadoException $e) {
            $this->assertInstanceOf(HttpExceptionInterface::class, $e);
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame($bloqueado->id, $e->userId);
        }
    }

    public function test_allowlist_vacia_falla_cerrado_para_todos(): void
    {
        $this->configurarGate(enabled: true, allowedUserIds: [], allowAll: false);

        foreach (User::factory()->count(3)->create() as $user) {
            try {
                app(GuardEmbeddedSignup::class)->verificar($user);
                $this->fail("La allowlist vacía debería bloquear al user {$user->id}.");
            } catch (UsuarioNoHabilitadoException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_usuario_en_la_allowlist_pasa(): void
    {
        $user = User::factory()->create();
        $this->configurarGate(enabled: true, allowedUserIds: [$user->id], allowAll: false);

        app(GuardEmbeddedSignup::class)->verificar($user);

        $this->expectNotToPerformAssertions();
    }

    public function test_allow_all_true_pasa_para_cualquier_usuario_fuera_de_la_allowlist(): void
    {
        $user = User::factory()->create();
        $this->configurarGate(enabled: true, allowedUserIds: [], allowAll: true);

        app(GuardEmbeddedSignup::class)->verificar($user);

        $this->expectNotToPerformAssertions();
    }

    public function test_allow_all_no_evita_el_master_switch_deshabilitado(): void
    {
        $user = User::factory()->create();
        $this->configurarGate(enabled: false, allowedUserIds: [$user->id], allowAll: true);

        $this->expectException(EmbeddedSignupDeshabilitadoException::class);

        app(GuardEmbeddedSignup::class)->verificar($user);
    }
}
