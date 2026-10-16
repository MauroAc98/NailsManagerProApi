<?php

namespace Tests\Feature\Pro;

use App\Support\BloqueoLogin;
use Tests\TestCase;

/**
 * Lockout profile of the professional portal login (PA-04, D11): the
 * (email, IP) pair locks after 5 failures and the IP after 30 failures against
 * any email. There is NO account-only lock, because anyone could use it to
 * keep a real professional out by failing from anywhere.
 */
class BloqueoLoginProfesionalTest extends TestCase
{
    public function test_pair_locks_after_five_failures_and_not_before(): void
    {
        $bloqueo = BloqueoLogin::profesional();

        for ($i = 0; $i < 4; $i++) {
            $bloqueo->registrarFallo('ana@x.com', '10.20.0.1');
        }
        $this->assertNull($bloqueo->segundosDeBloqueo('ana@x.com', '10.20.0.1'));

        $bloqueo->registrarFallo('ana@x.com', '10.20.0.1');
        $this->assertGreaterThan(0, $bloqueo->segundosDeBloqueo('ana@x.com', '10.20.0.1'));
    }

    public function test_pair_lock_does_not_reach_the_same_email_from_another_ip(): void
    {
        $bloqueo = BloqueoLogin::profesional();

        for ($i = 0; $i < 5; $i++) {
            $bloqueo->registrarFallo('ana@x.com', '10.20.1.1');
        }

        $this->assertNotNull($bloqueo->segundosDeBloqueo('ana@x.com', '10.20.1.1'));
        $this->assertNull($bloqueo->segundosDeBloqueo('ana@x.com', '10.20.1.2'));
    }

    public function test_there_is_no_account_only_lock_when_failures_come_from_many_ips(): void
    {
        $bloqueo = BloqueoLogin::profesional();

        for ($i = 1; $i <= 100; $i++) {
            $bloqueo->registrarFallo('ana@x.com', "10.20.2.{$i}");
        }

        $this->assertNull($bloqueo->segundosDeBloqueo('ana@x.com', '10.20.3.1'));
    }

    public function test_ip_locks_after_thirty_failures_against_different_emails(): void
    {
        $bloqueo = BloqueoLogin::profesional();

        for ($i = 1; $i <= 29; $i++) {
            $bloqueo->registrarFallo("probe{$i}@x.com", '10.20.4.1');
        }
        $this->assertNull($bloqueo->segundosDeBloqueo('ana@x.com', '10.20.4.1'));

        $bloqueo->registrarFallo('probe30@x.com', '10.20.4.1');
        $this->assertNotNull($bloqueo->segundosDeBloqueo('ana@x.com', '10.20.4.1'));
        $this->assertNull($bloqueo->segundosDeBloqueo('ana@x.com', '10.20.4.2'));
    }

    public function test_clearing_a_pair_keeps_the_ip_counter(): void
    {
        $bloqueo = BloqueoLogin::profesional();

        for ($i = 0; $i < 4; $i++) {
            $bloqueo->registrarFallo('ana@x.com', '10.20.5.1');
        }
        $bloqueo->limpiar('ana@x.com', '10.20.5.1');
        // The pair starts from zero again: 4 more failures do not lock it.
        for ($i = 0; $i < 4; $i++) {
            $bloqueo->registrarFallo('ana@x.com', '10.20.5.1');
        }
        $this->assertNull($bloqueo->segundosDeBloqueo('ana@x.com', '10.20.5.1'));

        // The IP counter was NOT cleared: 8 so far, 22 more reach 30.
        for ($i = 1; $i <= 22; $i++) {
            $bloqueo->registrarFallo("probe{$i}@x.com", '10.20.5.1');
        }
        $this->assertNotNull($bloqueo->segundosDeBloqueo('otra@x.com', '10.20.5.1'));
    }

    public function test_scope_is_separate_from_tenant_and_admin(): void
    {
        for ($i = 0; $i < 5; $i++) {
            BloqueoLogin::negocio()->registrarFallo('ana@x.com', '10.20.6.1');
            BloqueoLogin::admin()->registrarFallo('ana@x.com', '10.20.6.1');
        }
        $this->assertNotNull(BloqueoLogin::negocio()->segundosDeBloqueo('ana@x.com', '10.20.6.1'));
        $this->assertNotNull(BloqueoLogin::admin()->segundosDeBloqueo('ana@x.com', '10.20.6.1'));
        $this->assertNull(BloqueoLogin::profesional()->segundosDeBloqueo('ana@x.com', '10.20.6.1'));

        for ($i = 0; $i < 5; $i++) {
            BloqueoLogin::profesional()->registrarFallo('bea@x.com', '10.20.6.2');
        }
        $this->assertNotNull(BloqueoLogin::profesional()->segundosDeBloqueo('bea@x.com', '10.20.6.2'));
        $this->assertNull(BloqueoLogin::negocio()->segundosDeBloqueo('bea@x.com', '10.20.6.2'));
        $this->assertNull(BloqueoLogin::admin()->segundosDeBloqueo('bea@x.com', '10.20.6.2'));
    }
}
