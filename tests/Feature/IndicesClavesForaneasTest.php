<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Postgres no crea índice en las claves foráneas. Estas columnas se usan para
// filtrar/joinear y para que borrar un cliente o servicio no obligue a
// recorrer la tabla hija entera (ON DELETE SET NULL / CASCADE).
class IndicesClavesForaneasTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: array<int, string>}> */
    public static function indices(): array
    {
        return [
            'turno_servicio por turno (y servicio)' => ['turno_servicio', ['turno_id', 'servicio_id']],
            'turno_servicio por servicio' => ['turno_servicio', ['servicio_id']],
            'turnos por cliente' => ['turnos', ['cliente_id']],
            'turnos por profesional' => ['turnos', ['profesional_id']],
            'turnos por grupo' => ['turnos', ['grupo_id']],
            'turnos por reserva web' => ['turnos', ['reserva_web_id']],
            'whatsapp_mensajes por cuenta' => ['whatsapp_mensajes', ['user_id']],
            'servicios por cuenta' => ['servicios', ['user_id']],
        ];
    }

    /** @param  array<int, string>  $columnas */
    #[DataProvider('indices')]
    public function test_existe_el_indice(string $tabla, array $columnas): void
    {
        $this->assertTrue(
            Schema::hasIndex($tabla, $columnas),
            "Falta un índice en {$tabla}(".implode(', ', $columnas).')',
        );
    }
}
