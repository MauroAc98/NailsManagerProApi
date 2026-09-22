<?php

namespace Tests\Feature;

use App\Models\UserMpCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class PublicInfoTest extends TestCase
{
    use CreaSalonPublico, RefreshDatabase;

    public function test_info_devuelve_solo_los_datos_publicos_con_suscripcion_vigente(): void
    {
        $user = $this->crearSalon(['is_exempt' => false, 'name' => 'Studio Ana', 'direccion' => 'Av. X 123', 'telefono' => '3765000000']);
        $this->crearSuscripcion($user);
        $ana = $this->crearProfesional($user, 'Ana');
        $this->crearProfesional($user, 'Inactiva', false);

        $res = $this->getJson("/api/public/{$user->slug}/info")->assertOk();

        $res->assertExactJson([
            'nombre' => 'Studio Ana',
            'logo_url' => null,
            'direccion' => 'Av. X 123',
            'profesionales' => [['id' => $ana->id, 'nombre' => 'Ana', 'avatar_url' => null]],
            'pago_habilitado' => false,
        ]);
    }

    public function test_pago_habilitado_es_false_sin_credenciales_de_mp_aunque_tenga_sena_configurada(): void
    {
        $user = $this->crearSalon(['sena_monto' => 5000]);

        $this->getJson("/api/public/{$user->slug}/info")
            ->assertOk()
            ->assertJsonPath('pago_habilitado', false);
    }

    public function test_pago_habilitado_es_false_con_credenciales_de_mp_pero_sin_sena_configurada(): void
    {
        $user = $this->crearSalon(['sena_monto' => null]);
        UserMpCredential::create(['user_id' => $user->id, 'mp_access_token' => 'APP_USR-token', 'mp_user_id' => 'MP-1']);

        $this->getJson("/api/public/{$user->slug}/info")
            ->assertOk()
            ->assertJsonPath('pago_habilitado', false);
    }

    public function test_pago_habilitado_es_true_con_sena_y_credenciales_de_mp(): void
    {
        $user = $this->crearSalon(['sena_monto' => 5000]);
        UserMpCredential::create(['user_id' => $user->id, 'mp_access_token' => 'APP_USR-token', 'mp_user_id' => 'MP-1']);

        $this->getJson("/api/public/{$user->slug}/info")
            ->assertOk()
            ->assertJsonPath('pago_habilitado', true);
    }

    public function test_info_incluye_el_avatar_url_de_la_profesional_cuando_tiene_uno(): void
    {
        $user = $this->crearSalon();
        $ana = $this->crearProfesional($user, 'Ana');
        $ana->update(['avatar_path' => 'avatars/ana.jpg']);

        $res = $this->getJson("/api/public/{$user->slug}/info")->assertOk();

        $res->assertJsonPath('profesionales.0.avatar_url', $ana->fresh()->avatar_url);
        $this->assertNotNull($res->json('profesionales.0.avatar_url'));
    }

    public function test_info_no_filtra_telefono_email_ni_slug(): void
    {
        $user = $this->crearSalon(['telefono' => '3765000000']);

        $body = $this->getJson("/api/public/{$user->slug}/info")->assertOk()->getContent();

        $this->assertStringNotContainsString('3765000000', $body);
        $this->assertStringNotContainsString($user->email, $body);
        $this->assertStringNotContainsString('"slug"', $body);
    }

    public function test_info_404_para_slug_inexistente(): void
    {
        $this->getJson('/api/public/no-existe/info')->assertNotFound();
    }

    public function test_info_404_si_la_suscripcion_esta_vencida(): void
    {
        $user = $this->crearSalon(['is_exempt' => false]);
        $this->crearSuscripcion($user, 'VENCIDO', now()->subDay());

        $this->getJson("/api/public/{$user->slug}/info")->assertNotFound();
    }

    public function test_info_404_si_la_suscripcion_esta_suspendida(): void
    {
        $user = $this->crearSalon(['is_exempt' => false]);
        $this->crearSuscripcion($user, 'SUSPENDIDO', now()->addDays(10));

        $this->getJson("/api/public/{$user->slug}/info")->assertNotFound();
    }

    public function test_info_404_si_no_tiene_suscripcion(): void
    {
        $user = $this->crearSalon(['is_exempt' => false]);

        $this->getJson("/api/public/{$user->slug}/info")->assertNotFound();
    }

    public function test_info_ok_para_cuenta_exenta_sin_suscripcion(): void
    {
        $user = $this->crearSalon(['is_exempt' => true]);

        $this->getJson("/api/public/{$user->slug}/info")->assertOk();
    }
}
