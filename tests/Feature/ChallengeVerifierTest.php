<?php

namespace Tests\Feature;

use App\Services\Reservas\ChallengeVerifier;
use App\Services\Reservas\NullChallengeVerifier;
use App\Services\Reservas\TurnstileVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChallengeVerifierTest extends TestCase
{
    private const URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.turnstile.secret' => 'sekret']);
    }

    public function test_por_defecto_esta_deshabilitado_y_se_resuelve_el_null(): void
    {
        $this->assertFalse(config('reservas.challenge.habilitado'));
        $this->assertInstanceOf(NullChallengeVerifier::class, app(ChallengeVerifier::class));
        $this->assertTrue(app(ChallengeVerifier::class)->verificar(null, '1.2.3.4'));
    }

    public function test_habilitado_se_resuelve_turnstile(): void
    {
        config(['reservas.challenge.habilitado' => true]);

        $this->assertInstanceOf(TurnstileVerifier::class, app(ChallengeVerifier::class));
    }

    public function test_turnstile_exitoso_envia_secret_response_e_ip(): void
    {
        Http::fake([self::URL => Http::response(['success' => true])]);

        $this->assertTrue((new TurnstileVerifier())->verificar('tok', '9.9.9.9'));

        Http::assertSent(function (Request $r) {
            return $r->url() === self::URL
                && $r['secret'] === 'sekret'
                && $r['response'] === 'tok'
                && $r['remoteip'] === '9.9.9.9';
        });
    }

    public function test_turnstile_rechaza_si_success_es_false(): void
    {
        Http::fake([self::URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $this->assertFalse((new TurnstileVerifier())->verificar('tok', '9.9.9.9'));
    }

    public function test_falla_cerrado_sin_token_o_sin_secret_y_no_llama_a_cloudflare(): void
    {
        Http::fake([self::URL => Http::response(['success' => true])]);

        $this->assertFalse((new TurnstileVerifier())->verificar(null, '1.1.1.1'));
        $this->assertFalse((new TurnstileVerifier())->verificar('', '1.1.1.1'));

        config(['services.turnstile.secret' => '']);
        $this->assertFalse((new TurnstileVerifier())->verificar('tok', '1.1.1.1'));

        Http::assertNothingSent();
    }

    public function test_falla_cerrado_con_5xx(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);

        $this->assertFalse((new TurnstileVerifier())->verificar('tok', '1.1.1.1'));
    }

    public function test_falla_cerrado_con_timeout(): void
    {
        Http::fake([self::URL => fn () => throw new ConnectionException('timeout')]);

        $this->assertFalse((new TurnstileVerifier())->verificar('tok', '1.1.1.1'));
    }
}
