<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El throttle por IP no frena a un atacante que rota IPs: se agrega un
 * bloqueo temporal por cuenta tras varios fallos. Solo cuentan los intentos
 * FALLIDOS y un login correcto limpia el contador, para que un tercero no
 * pueda dejar afuera a la duena con tocar su email.
 */
class LoginBloqueoPorCuentaTest extends TestCase
{
    use RefreshDatabase;

    private function loginDesde(string $ip, string $email, string $password, string $ruta = '/api/auth/login')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson($ruta, ['email' => $email, 'password' => $password]);
    }

    private function negocio(): User
    {
        return User::factory()->create([
            'email' => 'duena@ejemplo.com',
            'password' => Hash::make('clave-correcta'),
            'is_exempt' => true,
        ]);
    }

    public function test_tras_5_fallos_desde_la_misma_ip_tambien_bloquea_la_clave_correcta(): void
    {
        $this->negocio();

        for ($i = 0; $i < 5; $i++) {
            $this->loginDesde('10.0.0.1', 'duena@ejemplo.com', 'mal')->assertStatus(422);
        }

        $this->loginDesde('10.0.0.1', 'duena@ejemplo.com', 'clave-correcta')
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_rotar_ips_no_evita_el_bloqueo_por_cuenta(): void
    {
        $this->negocio();

        for ($i = 1; $i <= 15; $i++) {
            $this->loginDesde("10.0.1.{$i}", 'duena@ejemplo.com', 'mal')->assertStatus(422);
        }

        $this->loginDesde('10.0.2.1', 'duena@ejemplo.com', 'clave-correcta')->assertStatus(429);
    }

    public function test_un_login_correcto_limpia_los_fallos_acumulados(): void
    {
        $this->negocio();

        for ($i = 0; $i < 4; $i++) {
            $this->loginDesde('10.0.0.2', 'duena@ejemplo.com', 'mal')->assertStatus(422);
        }
        $this->loginDesde('10.0.0.2', 'duena@ejemplo.com', 'clave-correcta')->assertOk();

        for ($i = 0; $i < 4; $i++) {
            $this->loginDesde('10.0.0.2', 'duena@ejemplo.com', 'mal')->assertStatus(422);
        }
        $this->loginDesde('10.0.0.2', 'duena@ejemplo.com', 'clave-correcta')->assertOk();
    }

    public function test_el_bloqueo_de_una_cuenta_no_afecta_a_otra(): void
    {
        $this->negocio();
        User::factory()->create([
            'email' => 'otra@ejemplo.com',
            'password' => Hash::make('otra-clave'),
            'is_exempt' => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->loginDesde('10.0.0.3', 'duena@ejemplo.com', 'mal')->assertStatus(422);
        }

        $this->loginDesde('10.0.0.3', 'otra@ejemplo.com', 'otra-clave')->assertOk();
    }

    public function test_el_email_se_normaliza_para_que_mayusculas_no_evadan_el_bloqueo(): void
    {
        $this->negocio();

        for ($i = 0; $i < 5; $i++) {
            $this->loginDesde('10.0.0.4', 'DUENA@Ejemplo.com', 'mal')->assertStatus(422);
        }

        $this->loginDesde('10.0.0.4', 'duena@ejemplo.com', 'clave-correcta')->assertStatus(429);
    }

    public function test_el_admin_se_bloquea_mas_rapido_tras_3_fallos(): void
    {
        AdminUser::create(['name' => 'Admin', 'email' => 'admin@turnetto.app', 'password' => Hash::make('admin-clave')]);

        for ($i = 0; $i < 3; $i++) {
            $this->loginDesde('10.0.0.5', 'admin@turnetto.app', 'mal', '/api/admin/login')->assertStatus(401);
        }

        $this->loginDesde('10.0.0.5', 'admin@turnetto.app', 'admin-clave', '/api/admin/login')
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_el_admin_entra_normal_si_no_hubo_fallos(): void
    {
        AdminUser::create(['name' => 'Admin', 'email' => 'admin@turnetto.app', 'password' => Hash::make('admin-clave')]);

        $this->loginDesde('10.0.0.6', 'admin@turnetto.app', 'admin-clave', '/api/admin/login')->assertOk();
    }
}
