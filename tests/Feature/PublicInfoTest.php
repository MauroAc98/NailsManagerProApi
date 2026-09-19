<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class PublicInfoTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    public function test_info_devuelve_solo_los_datos_publicos_con_suscripcion_vigente(): void
    {
        $user = $this->crearSalon(['is_exempt' => false, 'name' => 'Studio Ana', 'direccion' => 'Av. X 123', 'telefono' => '3765000000']);
        $this->crearSuscripcion($user);
        $ana = $this->crearProfesional($user, 'Ana');
        $this->crearProfesional($user, 'Inactiva', false);

        $res = $this->getJson("/api/public/{$user->slug}/info")->assertOk();

        $res->assertExactJson([
            'nombre'        => 'Studio Ana',
            'logo_url'      => null,
            'direccion'     => 'Av. X 123',
            'profesionales' => [['id' => $ana->id, 'nombre' => 'Ana']],
        ]);
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
