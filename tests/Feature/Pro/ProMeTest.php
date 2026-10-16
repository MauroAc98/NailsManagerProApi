<?php

namespace Tests\Feature\Pro;

use App\Models\ProfesionalCuenta;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaPortalProfesional;
use Tests\TestCase;

/**
 * PA-10: GET /api/pro/me is the negocio chooser. Exact whitelist, only active
 * links of active professionals, lapsed negocios shown as unavailable.
 */
class ProMeTest extends TestCase
{
    use CreaPortalProfesional;
    use RefreshDatabase;

    private ProfesionalCuenta $cuenta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cuenta = $this->crearCuentaPortal();
    }

    private function me(?ProfesionalCuenta $cuenta = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokenPara($cuenta ?? $this->cuenta))->getJson('/api/pro/me');
    }

    /** A negocio named $nombre with one professional linked to the account. */
    private function negocioVinculado(string $nombre, array $userAttrs = [], array $proAttrs = [], bool $vinculoActivo = true): array
    {
        $negocio = $this->crearNegocioPortal(array_merge(['name' => $nombre], $userAttrs));
        $negocio['ana']->forceFill(array_merge(['apellido' => 'Perez'], $proAttrs))->save();
        $vinculo = $this->vincularPortal($this->cuenta, $negocio['ana'], $vinculoActivo);

        return [$negocio, $vinculo];
    }

    public function test_it_returns_exactly_the_whitelisted_keys_ordered_by_link_id(): void
    {
        [, $primero] = $this->negocioVinculado('Negocio Uno');
        [, $segundo] = $this->negocioVinculado('Negocio Dos');

        $r = $this->me()->assertOk();

        $this->assertEqualsCanonicalizing(['cuenta', 'vinculos'], array_keys($r->json()));
        $this->assertSame(['email' => 'ana@x.com'], $r->json('cuenta'));
        $this->assertSame([$primero->id, $segundo->id], array_column($r->json('vinculos'), 'id'));
        $item = $r->json('vinculos.0');
        $this->assertEqualsCanonicalizing(['id', 'negocio', 'profesional', 'disponible', 'motivo'], array_keys($item));
        $this->assertSame(['nombre' => 'Negocio Uno'], $item['negocio']);
        $this->assertSame(['nombre' => 'Ana', 'apellido' => 'Perez', 'avatar_url' => null], $item['profesional']);
        $this->assertTrue($item['disponible']);
        $this->assertNull($item['motivo']);
    }

    public function test_avatar_url_is_the_public_url_when_the_professional_has_one(): void
    {
        $this->negocioVinculado('Negocio Uno', [], ['avatar_path' => 'avatars/ana.jpg']);

        $this->me()->assertJsonPath('vinculos.0.profesional.avatar_url', Storage::disk('public')->url('avatars/ana.jpg'));
    }

    public function test_inactive_links_and_links_of_inactive_professionals_are_not_listed(): void
    {
        $this->negocioVinculado('Inactivo por vinculo', [], [], false);
        $this->negocioVinculado('Profesional inactiva', [], ['activo' => false]);
        [, $visible] = $this->negocioVinculado('Visible');

        $this->assertSame([$visible->id], array_column($this->me()->json('vinculos'), 'id'));
    }

    public function test_an_account_without_any_active_link_gets_an_empty_list_not_an_error(): void
    {
        $this->negocioVinculado('Inactivo', [], [], false);

        $this->me()->assertOk()->assertExactJson(['cuenta' => ['email' => 'ana@x.com'], 'vinculos' => []]);
    }

    public function test_a_lapsed_negocio_is_listed_as_unavailable_with_the_subscription_code(): void
    {
        [$sinFila] = $this->negocioVinculado('Sin fila', ['is_exempt' => false]);
        [$suspendido] = $this->negocioVinculado('Suspendido', ['is_exempt' => false]);
        Subscription::create(['user_id' => $suspendido['user']->id, 'ends_at' => now()->addDays(9), 'status' => 'SUSPENDIDO']);
        [$vencido] = $this->negocioVinculado('Vencido', ['is_exempt' => false]);
        Subscription::create(['user_id' => $vencido['user']->id, 'ends_at' => now()->subDay(), 'status' => 'ACTIVO']);
        [$vigente] = $this->negocioVinculado('Vigente', ['is_exempt' => false]);
        Subscription::create(['user_id' => $vigente['user']->id, 'ends_at' => now()->addDay(), 'status' => 'ACTIVO']);

        $estados = collect($this->me()->json('vinculos'))->mapWithKeys(fn ($v) => [$v['negocio']['nombre'] => [$v['disponible'], $v['motivo']]]);

        $this->assertSame([
            'Sin fila' => [false, 'NO_SUBSCRIPTION'],
            'Suspendido' => [false, 'SUBSCRIPTION_SUSPENDED'],
            'Vencido' => [false, 'SUBSCRIPTION_EXPIRED'],
            'Vigente' => [true, null],
        ], $estados->all());
    }

    public function test_it_never_exposes_credentials_flags_internal_ids_or_the_owner(): void
    {
        $this->negocioVinculado('Negocio Uno', ['email' => 'titular-secreto@negocio.com']);

        $cuerpo = $this->me()->getContent();

        foreach (['titular-secreto@negocio.com', $this->cuenta->password, 'password', 'debe_cambiar_password', 'profesional_id', 'user_id', 'activo', 'ends_at', 'token'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $cuerpo);
        }
    }

    public function test_another_accounts_links_are_never_listed(): void
    {
        $this->negocioVinculado('Mio');
        $otra = $this->crearCuentaPortal('otra@x.com');
        $ajeno = $this->crearNegocioPortal(['name' => 'Ajeno']);
        $this->vincularPortal($otra, $ajeno['ana']);

        $nombres = array_column(array_column($this->me()->json('vinculos'), 'negocio'), 'nombre');

        $this->assertSame(['Mio'], $nombres);
        $this->assertSame(['Ajeno'], array_column(array_column($this->me($otra)->json('vinculos'), 'negocio'), 'nombre'));
    }

    public function test_query_count_does_not_grow_with_the_number_of_links(): void
    {
        $this->negocioVinculado('Uno', ['is_exempt' => false]);
        $this->me();
        DB::enableQueryLog();
        $this->me();
        $conUno = count(DB::getQueryLog());

        foreach (['Dos', 'Tres', 'Cuatro', 'Cinco'] as $nombre) {
            $this->negocioVinculado($nombre, ['is_exempt' => false]);
        }
        DB::flushQueryLog();
        $this->me();

        $this->assertSame($conUno, count(DB::getQueryLog()));
    }
}
