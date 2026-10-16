<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Validación de desde/hasta en los 3 endpoints de uso del panel admin
 * (uso-por-salon, uso/negocios, uso/negocios/{user}): fecha inválida,
 * rango invertido o mayor a 366 días => 422 (antes: 500 o un loop día por
 * día sin tope sobre desde=1900-01-01).
 */
class AdminUsoRangoFechasTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'Superadmin',
            'email' => 'admin@turnetto.app',
            'password' => 'password-de-test',
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function endpoints(): array
    {
        return [
            'uso-por-salon' => ['/api/admin/whatsapp/uso-por-salon'],
            'resumen' => ['/api/admin/uso/negocios'],
            'detalle' => ['/api/admin/uso/negocios/{user}'],
        ];
    }

    private function url(string $plantilla, array $query = []): string
    {
        $user = User::factory()->create();
        $url = str_replace('{user}', (string) $user->id, $plantilla);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    #[DataProvider('endpoints')]
    public function test_fecha_invalida_es_422(string $plantilla): void
    {
        $this->actingAs($this->admin, 'admin')
            ->getJson($this->url($plantilla, ['desde' => 'no-es-fecha', 'hasta' => '2026-09-30']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('desde');

        $this->actingAs($this->admin, 'admin')
            ->getJson($this->url($plantilla, ['desde' => '2026-09-01', 'hasta' => '2026-13-45']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('hasta');
    }

    #[DataProvider('endpoints')]
    public function test_rango_invertido_es_422(string $plantilla): void
    {
        $this->actingAs($this->admin, 'admin')
            ->getJson($this->url($plantilla, ['desde' => '2026-09-30', 'hasta' => '2026-09-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('hasta');
    }

    #[DataProvider('endpoints')]
    public function test_rango_mayor_a_366_dias_es_422(string $plantilla): void
    {
        $this->actingAs($this->admin, 'admin')
            ->getJson($this->url($plantilla, ['desde' => '1900-01-01', 'hasta' => '2026-09-30']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('hasta');

        // 2025-09-01 -> 2026-09-30 son 394 días.
        $this->actingAs($this->admin, 'admin')
            ->getJson($this->url($plantilla, ['desde' => '2025-09-01', 'hasta' => '2026-09-30']))
            ->assertStatus(422);
    }

    #[DataProvider('endpoints')]
    public function test_solo_desde_muy_viejo_tambien_es_422(string $plantilla): void
    {
        // hasta ausente => hoy: el rango efectivo también está acotado.
        $this->actingAs($this->admin, 'admin')
            ->getJson($this->url($plantilla, ['desde' => '1900-01-01']))
            ->assertStatus(422);
    }

    #[DataProvider('endpoints')]
    public function test_rango_valido_y_el_maximo_de_366_dias_responden_200(string $plantilla): void
    {
        $this->actingAs($this->admin, 'admin')
            ->getJson($this->url($plantilla, ['desde' => '2026-09-01', 'hasta' => '2026-09-30']))
            ->assertOk();

        // 2025-09-30 -> 2026-09-30 = 365 días de diferencia; el tope es 366.
        $this->actingAs($this->admin, 'admin')
            ->getJson($this->url($plantilla, ['desde' => '2025-09-30', 'hasta' => '2026-09-30']))
            ->assertOk();
    }

    #[DataProvider('endpoints')]
    public function test_sin_parametros_sigue_usando_el_default(string $plantilla): void
    {
        $this->actingAs($this->admin, 'admin')
            ->getJson($this->url($plantilla))
            ->assertOk()
            ->assertJsonStructure(['desde', 'hasta']);
    }

    #[DataProvider('endpoints')]
    public function test_parametros_vacios_equivalen_a_ausentes(string $plantilla): void
    {
        $this->actingAs($this->admin, 'admin')
            ->getJson($this->url($plantilla, ['desde' => '', 'hasta' => '']))
            ->assertOk();
    }
}
