<?php

namespace Tests\Feature;

use App\Models\HistoriaPrecioFoto;
use App\Models\Profesional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfesionalHistoriaPreciosTest extends TestCase
{
    use RefreshDatabase;

    private function fakeImagen(string $nombre = 'foto.jpg'): UploadedFile
    {
        // No usamos UploadedFile::fake()->image() porque requiere la
        // extensión GD (no disponible en este entorno de test); create()
        // con mimeType explícito alcanza para pasar la regla 'image'.
        return UploadedFile::fake()->create($nombre, 100, 'image/jpeg');
    }

    public function test_patchea_template_id_via_el_endpoint_de_update_existente(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", [
                'historia_precios_template_id' => 'grid',
            ])
            ->assertOk();

        $response->assertJsonPath('historia_precios_template_id', 'grid');

        $this->assertSame('grid', $profesional->fresh()->historia_precios_template_id);
    }

    public function test_rechaza_un_valor_de_template_que_no_esta_en_el_enum(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", [
                'historia_precios_template_id' => 'no-existe',
            ])
            ->assertStatus(422);
    }

    public function test_permite_volver_a_null_el_campo_de_historia_precios(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create([
            'user_id' => $user->id,
            'nombre' => 'Jefa',
            'activo' => true,
            'historia_precios_template_id' => 'fullbleed',
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", [
                'historia_precios_template_id' => null,
            ])
            ->assertOk()
            ->assertJsonPath('historia_precios_template_id', null);
    }

    public function test_patchea_la_nota_adicional_via_el_endpoint_de_update_existente(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $nota = [
            'precios' => ['texto' => 'Seña del 50%', 'activa' => true, 'alineacion' => 'left'],
            'promociones' => ['texto' => '', 'activa' => true, 'alineacion' => 'center'],
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", [
                'historia_precios_nota' => $nota,
            ])
            ->assertOk();

        $response->assertJsonPath('historia_precios_nota.precios.texto', 'Seña del 50%');
        $response->assertJsonPath('historia_precios_nota.precios.alineacion', 'left');

        // ConvertEmptyStringsToNull (middleware global de Laravel) normaliza
        // '' -> null en el request ANTES de la validación — el frontend
        // nunca ve la diferencia (useHistoriaPrecios ya trata null como
        // texto vacío al hidratar), pero el round-trip real guarda null,
        // no ''.
        $nota['promociones']['texto'] = null;
        $this->assertSame($nota, $profesional->fresh()->historia_precios_nota);
    }

    public function test_rechaza_una_alineacion_de_nota_que_no_esta_en_el_enum(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", [
                'historia_precios_nota' => [
                    'precios' => ['texto' => 'x', 'activa' => true, 'alineacion' => 'diagonal'],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_rechaza_un_texto_de_nota_mas_largo_que_180_caracteres(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", [
                'historia_precios_nota' => [
                    'precios' => ['texto' => str_repeat('a', 181), 'activa' => true, 'alineacion' => 'center'],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_un_payload_parcial_de_nota_mergea_en_vez_de_borrar_el_otro_modo(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create([
            'user_id' => $user->id,
            'nombre' => 'Jefa',
            'activo' => true,
            'historia_precios_nota' => [
                'precios' => ['texto' => 'Seña 50%', 'activa' => true, 'alineacion' => 'left'],
                'promociones' => ['texto' => 'Válido hasta fin de mes', 'activa' => true, 'alineacion' => 'center'],
            ],
        ]);

        // Manda SOLO 'precios' — la validación lo permite ('sometimes' en
        // cada hoja), 'promociones' no debe desaparecer.
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", [
                'historia_precios_nota' => [
                    'precios' => ['texto' => 'Seña 60%', 'activa' => false, 'alineacion' => 'right'],
                ],
            ])
            ->assertOk();

        $response->assertJsonPath('historia_precios_nota.precios.texto', 'Seña 60%');
        $response->assertJsonPath('historia_precios_nota.promociones.texto', 'Válido hasta fin de mes');

        $guardado = $profesional->fresh()->historia_precios_nota;
        $this->assertSame('Seña 60%', $guardado['precios']['texto']);
        $this->assertSame('Válido hasta fin de mes', $guardado['promociones']['texto']);
    }

    public function test_permite_volver_a_null_la_nota_adicional(): void
    {
        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create([
            'user_id' => $user->id,
            'nombre' => 'Jefa',
            'activo' => true,
            'historia_precios_nota' => ['precios' => ['texto' => 'algo', 'activa' => true, 'alineacion' => 'center']],
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/profesionales/{$profesional->id}", [
                'historia_precios_nota' => null,
            ])
            ->assertOk()
            ->assertJsonPath('historia_precios_nota', null);
    }

    public function test_sube_una_foto_y_devuelve_el_profesional_completo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('lista-precios.jpg'),
            ])
            ->assertOk();

        // Devuelve el Profesional COMPLETO (mismo shape que index/show), no
        // solo la foto — el reducer del frontend depende de esto.
        $response->assertJsonPath('id', $profesional->id);
        $response->assertJsonPath('nombre', 'Jefa');
        $response->assertJsonCount(1, 'historia_precios_fotos');
        $response->assertJsonStructure([
            'historia_precios_fotos' => [['id', 'url', 'orden']],
        ]);

        $this->assertSame(1, HistoriaPrecioFoto::where('profesional_id', $profesional->id)->count());

        $path = HistoriaPrecioFoto::first()->getRawOriginal('path');
        Storage::disk('public')->assertExists($path);
    }

    public function test_las_fotos_subidas_quedan_ordenadas_por_orden_de_llegada(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('a.jpg'),
            ])->assertOk();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('b.jpg'),
            ])->assertOk();

        $fotos = $response->json('historia_precios_fotos');
        $this->assertCount(2, $fotos);
        $this->assertSame(0, $fotos[0]['orden']);
        $this->assertSame(1, $fotos[1]['orden']);
    }

    public function test_rechaza_subir_una_quinta_foto(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                    'imagen' => $this->fakeImagen("foto-{$i}.jpg"),
                ])->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('foto-5.jpg'),
            ])
            ->assertStatus(422);

        $this->assertSame(4, HistoriaPrecioFoto::where('profesional_id', $profesional->id)->count());
    }

    public function test_borra_una_foto_fila_y_archivo_y_devuelve_el_profesional_completo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        $subida = $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('a.jpg'),
            ])->assertOk();

        $fotoId = $subida->json('historia_precios_fotos.0.id');
        $path = HistoriaPrecioFoto::findOrFail($fotoId)->getRawOriginal('path');

        Storage::disk('public')->assertExists($path);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/profesionales/{$profesional->id}/historia-precios-fotos/{$fotoId}")
            ->assertOk();

        $response->assertJsonPath('id', $profesional->id);
        $response->assertJsonCount(0, 'historia_precios_fotos');

        $this->assertDatabaseMissing('historia_precios_fotos', ['id' => $fotoId]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_resubir_despues_de_borrar_no_repite_el_orden_de_una_foto_existente(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        // 4 fotos: orden 0,1,2,3
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                    'imagen' => $this->fakeImagen("foto-{$i}.jpg"),
                ])->assertOk();
        }

        // Borra la de orden 1 (flujo de "reemplazar" = delete + re-upload)
        $fotoOrden1 = HistoriaPrecioFoto::where('profesional_id', $profesional->id)
            ->where('orden', 1)->firstOrFail();
        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/profesionales/{$profesional->id}/historia-precios-fotos/{$fotoOrden1->id}")
            ->assertOk();

        // Sube una nueva — antes del fix, esto repetía 'orden' = 3 (el
        // conteo post-borrado) en vez de continuar desde el máximo real.
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('nueva.jpg'),
            ])->assertOk();

        $ordenes = collect($response->json('historia_precios_fotos'))->pluck('orden')->all();
        $this->assertSame([0, 2, 3, 4], $ordenes);
        $this->assertSame(4, count(array_unique($ordenes)));
    }

    public function test_reordena_las_fotos_via_el_endpoint_de_reordenar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['is_exempt' => true]);
        $profesional = Profesional::create(['user_id' => $user->id, 'nombre' => 'Jefa', 'activo' => true]);

        // 3 fotos: orden 0,1,2
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                    'imagen' => $this->fakeImagen("foto-{$i}.jpg"),
                ])->assertOk();
        }

        $idsOriginal = HistoriaPrecioFoto::where('profesional_id', $profesional->id)
            ->orderBy('orden')->pluck('id')->all();
        $idsInvertidos = array_reverse($idsOriginal);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/profesionales/{$profesional->id}/historia-precios-fotos/reordenar", [
                'ids' => $idsInvertidos,
            ])
            ->assertOk();

        $fotos = collect($response->json('historia_precios_fotos'))->sortBy('orden')->values();
        $this->assertSame($idsInvertidos, $fotos->pluck('id')->all());
        $this->assertSame([0, 1, 2], $fotos->pluck('orden')->all());
    }

    public function test_rechaza_reordenar_con_una_foto_de_otro_profesional(): void
    {
        Storage::fake('public');

        $dueno = User::factory()->create(['is_exempt' => true]);
        $intruso = User::factory()->create(['is_exempt' => true]);

        $profesional = Profesional::create(['user_id' => $dueno->id, 'nombre' => 'Jefa', 'activo' => true]);
        $profesionalIntruso = Profesional::create(['user_id' => $intruso->id, 'nombre' => 'Otra', 'activo' => true]);

        $subida = $this->actingAs($dueno, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('a.jpg'),
            ])->assertOk();
        $fotoIdDueno = $subida->json('historia_precios_fotos.0.id');

        $subidaIntruso = $this->actingAs($intruso, 'sanctum')
            ->postJson("/api/profesionales/{$profesionalIntruso->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('b.jpg'),
            ])->assertOk();
        $fotoIdIntruso = $subidaIntruso->json('historia_precios_fotos.0.id');

        $this->actingAs($dueno, 'sanctum')
            ->patchJson("/api/profesionales/{$profesional->id}/historia-precios-fotos/reordenar", [
                'ids' => [$fotoIdDueno, $fotoIdIntruso],
            ])
            ->assertStatus(422);
    }

    public function test_no_permite_borrar_una_foto_de_otro_usuario(): void
    {
        Storage::fake('public');

        $dueno = User::factory()->create(['is_exempt' => true]);
        $intruso = User::factory()->create(['is_exempt' => true]);

        $profesional = Profesional::create(['user_id' => $dueno->id, 'nombre' => 'Jefa', 'activo' => true]);

        $subida = $this->actingAs($dueno, 'sanctum')
            ->postJson("/api/profesionales/{$profesional->id}/historia-precios-fotos", [
                'imagen' => $this->fakeImagen('a.jpg'),
            ])->assertOk();

        $fotoId = $subida->json('historia_precios_fotos.0.id');

        $this->actingAs($intruso, 'sanctum')
            ->deleteJson("/api/profesionales/{$profesional->id}/historia-precios-fotos/{$fotoId}")
            ->assertStatus(404);

        $this->assertDatabaseHas('historia_precios_fotos', ['id' => $fotoId]);
    }
}
