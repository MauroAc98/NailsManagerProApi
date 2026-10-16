<?php

namespace Tests\Feature;

use App\Support\BloqueoLogin;
use Tests\TestCase;

/**
 * Reglas del bloqueo de login por ambito. El admin NO se bloquea por cuenta a
 * secas (cualquiera podria dejar afuera al admin real tirando fallos): solo por
 * par (cuenta, IP) y por tope de fallos de una misma IP contra cualquier cuenta.
 */
class BloqueoLoginTest extends TestCase
{
    public function test_admin_fallos_ajenos_desde_otra_ip_no_bloquean_a_la_victima(): void
    {
        $bloqueo = BloqueoLogin::admin();

        for ($i = 0; $i < 3; $i++) {
            $bloqueo->registrarFallo('admin@turnetto.app', '10.9.0.1');
        }

        $this->assertNotNull($bloqueo->segundosDeBloqueo('admin@turnetto.app', '10.9.0.1'));
        $this->assertNull($bloqueo->segundosDeBloqueo('admin@turnetto.app', '10.9.0.2'));
    }

    public function test_admin_rotar_ips_no_bloquea_la_cuenta(): void
    {
        $bloqueo = BloqueoLogin::admin();

        for ($i = 1; $i <= 30; $i++) {
            $bloqueo->registrarFallo('admin@turnetto.app', "10.9.1.{$i}");
        }

        $this->assertNull($bloqueo->segundosDeBloqueo('admin@turnetto.app', '10.9.2.1'));
    }

    public function test_admin_misma_ip_y_email_bloquea_tras_3_fallos(): void
    {
        $bloqueo = BloqueoLogin::admin();

        $bloqueo->registrarFallo('admin@turnetto.app', '10.9.3.1');
        $bloqueo->registrarFallo('admin@turnetto.app', '10.9.3.1');
        $this->assertNull($bloqueo->segundosDeBloqueo('admin@turnetto.app', '10.9.3.1'));

        $bloqueo->registrarFallo('admin@turnetto.app', '10.9.3.1');
        $this->assertNotNull($bloqueo->segundosDeBloqueo('admin@turnetto.app', '10.9.3.1'));
    }

    public function test_admin_tope_por_ip_bloquea_cualquier_email_desde_esa_ip(): void
    {
        $bloqueo = BloqueoLogin::admin();

        // 10 fallos contra 10 emails distintos: ningun par llega a 3.
        for ($i = 1; $i <= 10; $i++) {
            $bloqueo->registrarFallo("probe{$i}@ejemplo.com", '10.9.4.1');
        }

        $this->assertNotNull($bloqueo->segundosDeBloqueo('admin@turnetto.app', '10.9.4.1'));
        $this->assertNull($bloqueo->segundosDeBloqueo('admin@turnetto.app', '10.9.4.2'));
    }

    public function test_admin_bajo_el_tope_por_ip_no_bloquea(): void
    {
        $bloqueo = BloqueoLogin::admin();

        for ($i = 1; $i <= 9; $i++) {
            $bloqueo->registrarFallo("probe{$i}@ejemplo.com", '10.9.5.1');
        }

        $this->assertNull($bloqueo->segundosDeBloqueo('admin@turnetto.app', '10.9.5.1'));
    }

    public function test_admin_un_login_correcto_limpia_el_par(): void
    {
        $bloqueo = BloqueoLogin::admin();

        $bloqueo->registrarFallo('admin@turnetto.app', '10.9.6.1');
        $bloqueo->registrarFallo('admin@turnetto.app', '10.9.6.1');
        $bloqueo->limpiar('admin@turnetto.app', '10.9.6.1');
        $bloqueo->registrarFallo('admin@turnetto.app', '10.9.6.1');
        $bloqueo->registrarFallo('admin@turnetto.app', '10.9.6.1');

        $this->assertNull($bloqueo->segundosDeBloqueo('admin@turnetto.app', '10.9.6.1'));
    }

    // Regresion: negocio() conserva su bloqueo por cuenta (15) y no tiene tope por IP.
    public function test_negocio_conserva_el_bloqueo_por_cuenta_y_no_tiene_tope_por_ip(): void
    {
        $bloqueo = BloqueoLogin::negocio();

        for ($i = 1; $i <= 15; $i++) {
            $bloqueo->registrarFallo('duena@ejemplo.com', "10.9.7.{$i}");
        }
        $this->assertNotNull($bloqueo->segundosDeBloqueo('duena@ejemplo.com', '10.9.8.1'));

        for ($i = 1; $i <= 12; $i++) {
            $bloqueo->registrarFallo("otra{$i}@ejemplo.com", '10.9.9.1');
        }
        $this->assertNull($bloqueo->segundosDeBloqueo('nueva@ejemplo.com', '10.9.9.1'));
    }
}
