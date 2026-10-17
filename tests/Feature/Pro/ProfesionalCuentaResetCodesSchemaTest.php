<?php

namespace Tests\Feature\Pro;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * PA-13 for the third portal table: the password-recovery codes. Times are
 * epoch integers (not datetimes) on purpose, see the migration.
 */
class ProfesionalCuentaResetCodesSchemaTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private const TABLA = 'profesional_cuenta_reset_codes';

    private const MIGRACION = 'migrations/2026_10_17_100300_create_profesional_cuenta_reset_codes_table.php';

    /** @return array<string, mixed> */
    private function fila(int $cuentaId, array $attrs = []): array
    {
        return array_merge([
            'cuenta_id' => $cuentaId,
            'code_hash' => 'hash-de-prueba',
            'expira_en' => 1_800_000_000,
            'ultimo_envio_en' => 1_799_998_200,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs);
    }

    public function test_the_table_has_the_expected_columns_with_zero_attempts_by_default(): void
    {
        $this->assertSame(
            ['id', 'cuenta_id', 'code_hash', 'expira_en', 'ultimo_envio_en', 'intentos', 'created_at', 'updated_at'],
            Schema::getColumnListing(self::TABLA),
        );

        // Plain integers, never datetimes: app.timezone is not UTC.
        $tipos = collect(Schema::getColumns(self::TABLA))->pluck('type_name', 'name');
        $this->assertContains($tipos['expira_en'], ['integer', 'bigint']);
        $this->assertContains($tipos['ultimo_envio_en'], ['integer', 'bigint']);

        $cuenta = $this->crearCuentaPortal();
        DB::table(self::TABLA)->insert($this->fila($cuenta->id));

        $fila = DB::table(self::TABLA)->first();
        $this->assertSame(0, (int) $fila->intentos);
        // Epoch integers round-trip untouched: no timezone shift is possible.
        $this->assertSame(1_800_000_000, (int) $fila->expira_en);
        $this->assertSame(1_799_998_200, (int) $fila->ultimo_envio_en);
    }

    public function test_an_account_has_at_most_one_active_code(): void
    {
        $cuenta = $this->crearCuentaPortal();
        DB::table(self::TABLA)->insert($this->fila($cuenta->id));

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table(self::TABLA)->insert($this->fila($cuenta->id, ['code_hash' => 'otro']));
    }

    public function test_two_different_accounts_can_each_hold_a_code(): void
    {
        $a = $this->crearCuentaPortal('a@x.com');
        $b = $this->crearCuentaPortal('b@x.com');
        DB::table(self::TABLA)->insert($this->fila($a->id));
        DB::table(self::TABLA)->insert($this->fila($b->id));

        $this->assertSame(2, DB::table(self::TABLA)->count());
    }

    public function test_a_code_needs_an_existing_account(): void
    {
        $this->expectException(QueryException::class);
        DB::table(self::TABLA)->insert($this->fila(999_999));
    }

    public function test_deleting_the_account_deletes_its_code_and_only_its_code(): void
    {
        $a = $this->crearCuentaPortal('a@x.com');
        $b = $this->crearCuentaPortal('b@x.com');
        DB::table(self::TABLA)->insert($this->fila($a->id));
        DB::table(self::TABLA)->insert($this->fila($b->id));

        $a->delete();

        $this->assertSame([$b->id], DB::table(self::TABLA)->pluck('cuenta_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_the_expiry_column_is_indexed_for_the_prune(): void
    {
        $this->assertTrue(Schema::hasIndex(self::TABLA, ['expira_en']));
        $this->assertTrue(Schema::hasIndex(self::TABLA, ['cuenta_id'], 'unique'));
    }

    public function test_the_migration_rolls_back_first_and_re_applies_without_touching_other_tables(): void
    {
        $cuenta = $this->crearCuentaPortal();
        DB::table(self::TABLA)->insert($this->fila($cuenta->id));

        $tablasAntes = Schema::getTableListing(schemaQualified: false);
        $columnasAntes = [];
        foreach ($tablasAntes as $tabla) {
            $columnasAntes[$tabla] = Schema::getColumnListing($tabla);
        }
        $this->assertContains(self::TABLA, $tablasAntes);

        // Reverse order: the codes reference the accounts, so they go first.
        $migracion = require database_path(self::MIGRACION);
        $migracion->down();

        $this->assertFalse(Schema::hasTable(self::TABLA));
        $this->assertEqualsCanonicalizing(
            array_values(array_diff($tablasAntes, [self::TABLA])),
            Schema::getTableListing(schemaQualified: false),
        );
        foreach (array_diff($tablasAntes, [self::TABLA]) as $tabla) {
            $this->assertSame($columnasAntes[$tabla], Schema::getColumnListing($tabla), "Table {$tabla} changed.");
        }
        $this->assertSame(1, DB::table('profesional_cuentas')->count(), 'rolling back keeps the accounts');

        $migracion->up();

        $this->assertEqualsCanonicalizing($tablasAntes, Schema::getTableListing(schemaQualified: false));
        $this->assertSame($columnasAntes[self::TABLA], Schema::getColumnListing(self::TABLA));
        $this->assertSame(0, DB::table(self::TABLA)->count());
        // Usable again, and the uniqueness survived the round trip.
        DB::table(self::TABLA)->insert($this->fila($cuenta->id));
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table(self::TABLA)->insert($this->fila($cuenta->id));
    }
}
