<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Postgres no crea índice en las claves foráneas (solo en la PK y en lo que se
// declara aparte). Estas columnas filtran/joinean todo el tiempo y, además,
// borrar un cliente, profesional o servicio obliga a Postgres a recorrer la
// tabla hija entera para aplicar ON DELETE SET NULL / CASCADE si falta el
// índice. En producción turno_servicio tenía ~48k lecturas secuenciales contra
// 10 usos de índice.
//
// En Postgres se crean CONCURRENTLY (no bloquean escrituras cuando las tablas
// sean grandes), lo que no puede correr dentro de una transacción: de ahí
// $withinTransaction = false. IF NOT EXISTS hace la migración re-ejecutable.
// Si un CREATE INDEX CONCURRENTLY se corta a mitad, Postgres deja el índice
// INVALID y IF NOT EXISTS lo saltearía: bajarlo con DROP INDEX y reintentar.
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<int, array{0: string, 1: string, 2: array<int, string>}> [tabla, índice, columnas] */
    private const INDICES = [
        ['turno_servicio', 'turno_servicio_turno_id_servicio_id_index', ['turno_id', 'servicio_id']],
        ['turno_servicio', 'turno_servicio_servicio_id_index', ['servicio_id']],
        ['turnos', 'turnos_cliente_id_index', ['cliente_id']],
        ['turnos', 'turnos_profesional_id_index', ['profesional_id']],
        ['turnos', 'turnos_grupo_id_index', ['grupo_id']],
        ['turnos', 'turnos_reserva_web_id_index', ['reserva_web_id']],
        ['whatsapp_mensajes', 'whatsapp_mensajes_user_id_index', ['user_id']],
        ['servicios', 'servicios_user_id_index', ['user_id']],
    ];

    public function up(): void
    {
        foreach (self::INDICES as [$tabla, $indice, $columnas]) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement(sprintf(
                    'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)',
                    $indice,
                    $tabla,
                    implode(', ', $columnas),
                ));

                continue;
            }

            if (! Schema::hasIndex($tabla, $indice)) {
                Schema::table($tabla, fn (Blueprint $t) => $t->index($columnas, $indice));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as [$tabla, $indice]) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$indice}");

                continue;
            }

            if (Schema::hasIndex($tabla, $indice)) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropIndex($indice));
            }
        }
    }
};
