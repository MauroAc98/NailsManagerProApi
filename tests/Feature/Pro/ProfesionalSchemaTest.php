<?php

namespace Tests\Feature\Pro;

use App\Models\ProfesionalCuenta;
use App\Models\ProfesionalVinculo;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

class ProfesionalSchemaTest extends TestCase
{
    private const MIGRACION_MARCA = 'migrations/2026_10_17_100250_add_cuenta_creada_aqui_to_profesional_vinculos_table.php';

    use CreaPortalProfesional;
    use RefreshDatabase;

    public function test_email_is_stored_lowercased_and_trimmed(): void
    {
        $cuenta = ProfesionalCuenta::create([
            'email'    => '  ANA@X.com ',
            'password' => Hash::make('password-seguro'),
        ]);

        $this->assertSame('ana@x.com', $cuenta->email);
        $this->assertSame('ana@x.com', $cuenta->fresh()->email);
        $this->assertDatabaseHas('profesional_cuentas', ['email' => 'ana@x.com']);
    }

    public function test_email_unique_is_case_insensitive_through_the_mutator(): void
    {
        $this->crearCuentaPortal('ana@x.com');

        try {
            $this->crearCuentaPortal('ANA@X.com');
            $this->fail('A second account with the same email in another case must be rejected.');
        } catch (UniqueConstraintViolationException) {
            // expected
        }

        $this->assertSame(1, ProfesionalCuenta::count());
    }

    public function test_defaults_are_active_and_must_change_password(): void
    {
        $cuenta = ProfesionalCuenta::create([
            'email'    => 'nueva@x.com',
            'password' => Hash::make('password-seguro'),
        ]);

        // In-memory (before reloading) and persisted values must agree.
        $this->assertTrue($cuenta->activo);
        $this->assertTrue($cuenta->debe_cambiar_password);

        $recargada = $cuenta->fresh();
        $this->assertTrue($recargada->activo);
        $this->assertTrue($recargada->debe_cambiar_password);
        $this->assertNull($recargada->ultimo_login_at);
        $this->assertNull($recargada->ultimo_login_ip);
    }

    public function test_password_is_hashed_and_hidden_from_serialization(): void
    {
        $cuenta = ProfesionalCuenta::create([
            'email'    => 'ana@x.com',
            'password' => 'password-seguro',
        ]);

        $this->assertNotSame('password-seguro', $cuenta->getRawOriginal('password'));
        $this->assertTrue(Hash::check('password-seguro', $cuenta->password));
        $this->assertArrayNotHasKey('password', $cuenta->toArray());
    }

    public function test_cuenta_coexists_with_a_tenant_user_with_the_same_email(): void
    {
        $user = User::factory()->create([
            'email'    => 'ana@x.com',
            'password' => Hash::make('clave-del-tenant'),
        ]);
        $cuenta = $this->crearCuentaPortal('ana@x.com', 'clave-del-portal');

        $this->assertSame('ana@x.com', $user->fresh()->email);
        $this->assertSame('ana@x.com', $cuenta->fresh()->email);
        $this->assertTrue(Hash::check('clave-del-tenant', $user->fresh()->password));
        $this->assertTrue(Hash::check('clave-del-portal', $cuenta->fresh()->password));
        $this->assertFalse(Hash::check('clave-del-tenant', $cuenta->fresh()->password));
    }

    public function test_one_cuenta_can_link_professionals_of_different_negocios(): void
    {
        $negocioA = $this->crearNegocioPortal();
        $negocioB = $this->crearNegocioPortal();
        $cuenta = $this->crearCuentaPortal();

        $v1 = $this->vincularPortal($cuenta, $negocioA['ana'], invitadoPor: $negocioA['user']);
        $v2 = $this->vincularPortal($cuenta, $negocioB['ana'], invitadoPor: $negocioB['user']);

        $this->assertEqualsCanonicalizing([$v1->id, $v2->id], $cuenta->vinculos()->pluck('id')->all());
        $this->assertTrue($v1->activo);
        $this->assertSame($cuenta->id, $negocioA['ana']->vinculo->cuenta_id);
        $this->assertSame($negocioB['user']->id, $v2->fresh()->invitado_por_user_id);
    }

    public function test_the_same_pair_cannot_be_linked_twice(): void
    {
        $negocio = $this->crearNegocioPortal();
        $cuenta = $this->crearCuentaPortal();
        $this->vincularPortal($cuenta, $negocio['ana']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->vincularPortal($cuenta, $negocio['ana']);
    }

    public function test_a_professional_has_at_most_one_cuenta(): void
    {
        $negocio = $this->crearNegocioPortal();
        $this->vincularPortal($this->crearCuentaPortal('a@x.com'), $negocio['ana']);

        try {
            $this->vincularPortal($this->crearCuentaPortal('b@x.com'), $negocio['ana']);
            $this->fail('A professional must not be linkable to a second account.');
        } catch (UniqueConstraintViolationException) {
            // expected
        }

        $this->assertSame(1, ProfesionalVinculo::count());
    }

    public function test_deleting_a_professional_deletes_only_her_link(): void
    {
        $negocio = $this->crearNegocioPortal();
        $cuenta = $this->crearCuentaPortal();
        $this->vincularPortal($cuenta, $negocio['ana']);
        $vBea = $this->vincularPortal($cuenta, $negocio['bea']);

        $negocio['ana']->delete();

        $this->assertSame([$vBea->id], $cuenta->vinculos()->pluck('id')->all());
        $this->assertNotNull(ProfesionalCuenta::find($cuenta->id));
    }

    public function test_deleting_a_cuenta_deletes_all_its_links_but_not_the_professionals(): void
    {
        $negocio = $this->crearNegocioPortal();
        $cuenta = $this->crearCuentaPortal();
        $this->vincularPortal($cuenta, $negocio['ana']);
        $this->vincularPortal($cuenta, $negocio['bea']);
        $otra = $this->vincularPortal($this->crearCuentaPortal('otra@x.com'), $negocio['titular']);

        $cuenta->delete();

        $this->assertSame([$otra->id], ProfesionalVinculo::pluck('id')->all());
        $this->assertNotNull($negocio['ana']->fresh());
        $this->assertNotNull($negocio['bea']->fresh());
    }

    public function test_deleting_the_inviting_owner_keeps_the_link_and_nulls_the_reference(): void
    {
        $negocio = $this->crearNegocioPortal();
        $otroNegocio = $this->crearNegocioPortal();
        $cuenta = $this->crearCuentaPortal();
        // The link lives under another negocio's professional so deleting the
        // inviting user does not cascade through profesionales.
        $vinculo = $this->vincularPortal($cuenta, $otroNegocio['ana'], invitadoPor: $negocio['user']);

        $negocio['user']->delete();

        $this->assertNull($vinculo->fresh()->invitado_por_user_id);
    }

    public function test_scope_activos_returns_only_active_links(): void
    {
        $negocio = $this->crearNegocioPortal();
        $cuenta = $this->crearCuentaPortal();
        $activo = $this->vincularPortal($cuenta, $negocio['ana'], activo: true);
        $this->vincularPortal($cuenta, $negocio['bea'], activo: false);

        $this->assertSame([$activo->id], ProfesionalVinculo::activos()->pluck('id')->all());
    }

    public function test_professional_without_link_has_no_vinculo(): void
    {
        $negocio = $this->crearNegocioPortal();
        $this->vincularPortal($this->crearCuentaPortal(), $negocio['ana']);

        $this->assertNull($negocio['bea']->vinculo);
        $this->assertNotNull($negocio['ana']->vinculo);
        $this->assertSame($negocio['ana']->id, $negocio['ana']->vinculo->profesional->id);
    }

    public function test_both_migrations_roll_back_and_re_apply_without_touching_other_tables(): void
    {
        $negocio = $this->crearNegocioPortal();
        $this->vincularPortal($this->crearCuentaPortal(), $negocio['ana']);

        $nuevas = ['profesional_cuentas', 'profesional_vinculos'];
        $tablasAntes = Schema::getTableListing(schemaQualified: false);
        $columnasAntes = [];
        foreach ($tablasAntes as $tabla) {
            $columnasAntes[$tabla] = Schema::getColumnListing($tabla);
        }
        $this->assertContains('profesional_cuentas', $tablasAntes);
        $this->assertContains('profesional_vinculos', $tablasAntes);

        // Reverse order: the ownership marker is added to the links, which reference the accounts.
        $marca = require database_path(self::MIGRACION_MARCA);
        $vinculos = require database_path('migrations/2026_10_17_100100_create_profesional_vinculos_table.php');
        $cuentas = require database_path('migrations/2026_10_17_100000_create_profesional_cuentas_table.php');
        $marca->down();
        $vinculos->down();
        $cuentas->down();

        $this->assertFalse(Schema::hasTable('profesional_vinculos'));
        $this->assertFalse(Schema::hasTable('profesional_cuentas'));
        $this->assertEqualsCanonicalizing(
            array_values(array_diff($tablasAntes, $nuevas)),
            Schema::getTableListing(schemaQualified: false),
        );
        foreach (array_diff($tablasAntes, $nuevas) as $tabla) {
            $this->assertSame($columnasAntes[$tabla], Schema::getColumnListing($tabla), "Table {$tabla} changed.");
        }

        $cuentas->up();
        $vinculos->up();
        $marca->up();

        $this->assertEqualsCanonicalizing($tablasAntes, Schema::getTableListing(schemaQualified: false));
        foreach ($nuevas as $tabla) {
            $this->assertSame($columnasAntes[$tabla], Schema::getColumnListing($tabla), "Table {$tabla} differs after re-migrating.");
        }
        // The re-created schema is usable again.
        $this->vincularPortal($this->crearCuentaPortal('otra@x.com'), $negocio['bea']);
        $this->assertSame(1, ProfesionalVinculo::count());
    }

    public function test_the_ownership_marker_defaults_to_false_and_its_migration_is_reversible(): void
    {
        $negocio = $this->crearNegocioPortal();
        $cuenta = $this->crearCuentaPortal();
        // A link written without mentioning the marker, like every row that existed before it.
        DB::table('profesional_vinculos')->insert([
            'cuenta_id' => $cuenta->id,
            'profesional_id' => $negocio['ana']->id,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTrue(Schema::hasColumn('profesional_vinculos', 'cuenta_creada_aqui'));
        $this->assertFalse(ProfesionalVinculo::sole()->cuenta_creada_aqui);
        $columnas = Schema::getColumnListing('profesional_vinculos');

        $marca = require database_path(self::MIGRACION_MARCA);
        $marca->down();

        $this->assertFalse(Schema::hasColumn('profesional_vinculos', 'cuenta_creada_aqui'));
        $this->assertSame(array_values(array_diff($columnas, ['cuenta_creada_aqui'])), Schema::getColumnListing('profesional_vinculos'));
        $this->assertSame(1, ProfesionalVinculo::count(), 'rolling back keeps the links');

        $marca->up();

        $this->assertSame($columnas, Schema::getColumnListing('profesional_vinculos'));
        $this->assertFalse(ProfesionalVinculo::sole()->cuenta_creada_aqui);
        // Still enforced after the round trip: a professional keeps at most one link.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->vincularPortal($this->crearCuentaPortal('otra@x.com'), $negocio['ana']);
    }
}
