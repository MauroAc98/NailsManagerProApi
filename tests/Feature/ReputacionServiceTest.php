<?php

namespace Tests\Feature;

use App\Models\ReservaReputacion;
use App\Services\Reservas\NullVerificadorWhatsapp;
use App\Services\Reservas\ReputacionService;
use App\Services\Reservas\VerificadorWhatsapp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class ReputacionServiceTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    private const AHORA = 1_800_000_000;

    private function svc(): ReputacionService
    {
        return new ReputacionService();
    }

    public function test_los_hashes_son_hmac_de_64_hex_y_estables_sin_exponer_el_valor(): void
    {
        $s = $this->svc();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $s->hashDevice('tok_abc'));
        $this->assertSame($s->hashDevice('tok_abc'), $s->hashDevice('tok_abc'));
        $this->assertNotSame($s->hashDevice('tok_abc'), $s->hashDevice('tok_abd'));
        $this->assertStringNotContainsString('tok_abc', $s->hashDevice('tok_abc'));
    }

    public function test_el_telefono_se_normaliza_a_los_ultimos_10_digitos(): void
    {
        $s = $this->svc();

        $this->assertSame($s->hashTelefono('+5493765252395'), $s->hashTelefono('+54 376 525-2395'));
        $this->assertSame($s->hashTelefono('+5493765252395'), $s->hashTelefono('3765252395'));
        $this->assertNotSame($s->hashTelefono('+5493765252395'), $s->hashTelefono('+5493765252396'));
    }

    public function test_un_vencido_sin_pago_incrementa_y_bloquea_al_telefono_30_minutos(): void
    {
        $user = $this->crearSalon();
        $s = $this->svc();
        $dev = $s->hashDevice('d1');
        $tel = $s->hashTelefono('+5493765252395');

        $s->registrarVencido($user->id, $dev, $tel, self::AHORA);

        $this->assertSame(1, ReservaReputacion::where('kind', 'device')->value('expirados_sin_pago'));
        $this->assertSame(1, ReservaReputacion::where('kind', 'phone')->value('expirados_sin_pago'));
        $this->assertSame(1800, $s->cooldownRestante($user->id, $tel, self::AHORA));
        $this->assertSame(1, $s->cooldownRestante($user->id, $tel, self::AHORA + 1799));
        $this->assertSame(0, $s->cooldownRestante($user->id, $tel, self::AHORA + 1800));
        // El dispositivo no tiene cooldown de espera (solo el telefono).
        $this->assertNull(ReservaReputacion::where('kind', 'device')->value('bloqueado_hasta'));
    }

    public function test_sin_telefono_solo_cuenta_el_dispositivo(): void
    {
        $user = $this->crearSalon();

        $this->svc()->registrarVencido($user->id, $this->svc()->hashDevice('d1'), null, self::AHORA);

        $this->assertSame(1, ReservaReputacion::count());
    }

    public function test_dos_vencidos_dentro_de_24h_exigen_verificacion_y_uno_no(): void
    {
        $user = $this->crearSalon();
        $s = $this->svc();
        $tel = $s->hashTelefono('+5493765252395');

        $s->registrarVencido($user->id, null, $tel, self::AHORA);
        $this->assertFalse($s->necesitaVerificacion($user->id, null, $tel, self::AHORA + 10));

        $s->registrarVencido($user->id, null, $tel, self::AHORA + 3600);
        $this->assertTrue($s->necesitaVerificacion($user->id, null, $tel, self::AHORA + 3700));
    }

    public function test_verificacion_por_dispositivo_o_por_telefono(): void
    {
        $user = $this->crearSalon();
        $s = $this->svc();
        $dev = $s->hashDevice('d1');

        $s->registrarVencido($user->id, $dev, null, self::AHORA);
        $s->registrarVencido($user->id, $dev, null, self::AHORA + 60);

        $this->assertTrue($s->necesitaVerificacion($user->id, $dev, $s->hashTelefono('3765000000'), self::AHORA + 100));
        $this->assertFalse($s->necesitaVerificacion($user->id, $s->hashDevice('otro'), $s->hashTelefono('3765000000'), self::AHORA + 100));
    }

    public function test_los_vencidos_fuera_de_la_ventana_reinician_el_conteo(): void
    {
        $user = $this->crearSalon();
        $s = $this->svc();
        $tel = $s->hashTelefono('+5493765252395');

        $s->registrarVencido($user->id, null, $tel, self::AHORA);
        $s->registrarVencido($user->id, null, $tel, self::AHORA + 25 * 3600);

        $this->assertSame(1, ReservaReputacion::value('expirados_sin_pago'));
        $this->assertFalse($s->necesitaVerificacion($user->id, null, $tel, self::AHORA + 25 * 3600 + 1));
    }

    public function test_verificado_hasta_limpia_la_exigencia(): void
    {
        $user = $this->crearSalon();
        $s = $this->svc();
        $tel = $s->hashTelefono('+5493765252395');
        $s->registrarVencido($user->id, null, $tel, self::AHORA);
        $s->registrarVencido($user->id, null, $tel, self::AHORA + 10);

        $s->marcarVerificado($user->id, 'phone', $tel, self::AHORA + 1000);

        $this->assertFalse($s->necesitaVerificacion($user->id, null, $tel, self::AHORA + 60));
        // Vencida la verificacion vuelve a exigirse (el conteo sigue dentro de la ventana de 24h).
        $this->assertTrue($s->necesitaVerificacion($user->id, null, $tel, self::AHORA + 1001));
    }

    public function test_la_reputacion_es_por_salon(): void
    {
        $a = $this->crearSalon();
        $b = $this->crearSalon();
        $s = $this->svc();
        $tel = $s->hashTelefono('+5493765252395');

        $s->registrarVencido($a->id, null, $tel, self::AHORA);

        $this->assertSame(0, $s->cooldownRestante($b->id, $tel, self::AHORA));
        $this->assertGreaterThan(0, $s->cooldownRestante($a->id, $tel, self::AHORA));
    }

    public function test_el_verificador_de_whatsapp_por_defecto_es_el_null(): void
    {
        $v = app(VerificadorWhatsapp::class);

        $this->assertInstanceOf(NullVerificadorWhatsapp::class, $v);
        $this->assertFalse($v->enviarCodigo('+5493765252395'));
        $this->assertFalse($v->validarCodigo('+5493765252395', '123456'));
    }
}
