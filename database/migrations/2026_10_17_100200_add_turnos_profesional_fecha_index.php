<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Agenda of the professionals' portal: every query filters by professional and
// by a fecha_hora range (a day, a week, a month). With only
// turnos_profesional_id, Postgres reads all the historical turnos of the
// professional and discards by date afterwards; with (profesional_id,
// fecha_hora) it goes straight to the range. This is performance only: no
// business rule depends on the index, so it can be dropped safely with
// `php artisan migrate:rollback --step=1`.
//
// Same structure as 2026_10_13_120000_add_indexes_to_foreign_keys: on Postgres
// it is created CONCURRENTLY (does not block writes), which cannot run inside a
// transaction, hence $withinTransaction = false. IF NOT EXISTS makes the
// migration re-runnable. If a CREATE INDEX CONCURRENTLY is interrupted midway,
// Postgres leaves the index INVALID and IF NOT EXISTS would skip it: drop it
// with DROP INDEX and retry.
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLA = 'turnos';

    private const INDICE = 'turnos_profesional_id_fecha_hora_index';

    private const COLUMNAS = ['profesional_id', 'fecha_hora'];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)',
                self::INDICE,
                self::TABLA,
                implode(', ', self::COLUMNAS),
            ));

            return;
        }

        if (! Schema::hasIndex(self::TABLA, self::INDICE)) {
            Schema::table(self::TABLA, fn (Blueprint $t) => $t->index(self::COLUMNAS, self::INDICE));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDICE);

            return;
        }

        if (Schema::hasIndex(self::TABLA, self::INDICE)) {
            Schema::table(self::TABLA, fn (Blueprint $t) => $t->dropIndex(self::INDICE));
        }
    }
};
