<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaSalonPublico;
use Tests\TestCase;

class PublicThrottleTest extends TestCase
{
    use RefreshDatabase, CreaSalonPublico;

    /**
     * @dataProvider lecturas
     */
    public function test_la_request_61_de_una_lectura_publica_da_429(string $ruta): void
    {
        $user = $this->crearSalon();
        $url = "/api/public/{$user->slug}/{$ruta}";

        for ($i = 0; $i < 60; $i++) {
            $this->assertNotSame(429, $this->getJson($url)->getStatusCode(), "request {$i}");
        }

        $this->getJson($url)->assertStatus(429);
    }

    public static function lecturas(): array
    {
        return [
            'info'           => ['info'],
            'servicios'      => ['servicios'],
            'disponibilidad' => ['disponibilidad?fecha=2099-01-01&servicio_ids[]=1'],
        ];
    }
}
