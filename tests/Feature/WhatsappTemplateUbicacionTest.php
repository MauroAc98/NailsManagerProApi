<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappTemplateUbicacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tiene_ubicacion_es_true_con_coordenadas_validas(): void
    {
        $user = User::factory()->create(['latitud' => -27.4692, 'longitud' => -58.8306]);

        $this->assertTrue(WhatsappTemplate::tieneUbicacion($user));
    }

    public function test_tiene_ubicacion_es_false_sin_coordenadas(): void
    {
        $user = User::factory()->create(['latitud' => null, 'longitud' => null]);

        $this->assertFalse(WhatsappTemplate::tieneUbicacion($user));
    }

    public function test_tiene_ubicacion_es_false_con_par_cero_cero(): void
    {
        $user = User::factory()->create(['latitud' => 0, 'longitud' => 0]);

        $this->assertFalse(WhatsappTemplate::tieneUbicacion($user));
    }

    public function test_header_ubicacion_es_null_sin_coordenadas(): void
    {
        $user = User::factory()->create(['latitud' => null, 'longitud' => null]);

        $this->assertNull(WhatsappTemplate::headerUbicacionCloudApi($user));
    }

    public function test_header_ubicacion_nunca_incluye_la_direccion_escrita_por_el_negocio(): void
    {
        // La tarjeta de ubicación de WhatsApp muestra el pin del mapa; el
        // texto libre del campo dirección ya viaja en el cuerpo ({{6}}) y
        // duplicarlo en la tarjeta no es lo que el negocio quiere.
        $user = User::factory()->create([
            'latitud' => -27.4692,
            'longitud' => -58.8306,
            'direccion' => "Av. Siempre Viva 742\nEntre Piso 1",
        ]);

        $header = WhatsappTemplate::headerUbicacionCloudApi($user);

        $this->assertArrayNotHasKey('address', $header);
        $this->assertSame('-27.4692', $header['latitude']);
    }

    public function test_header_ubicacion_omite_name_vacio(): void
    {
        $user = User::factory()->create([
            'latitud' => -27.4692,
            'longitud' => -58.8306,
            'name' => '',
        ]);

        $header = WhatsappTemplate::headerUbicacionCloudApi($user);

        $this->assertArrayNotHasKey('name', $header);
        $this->assertSame('-27.4692', $header['latitude']);
        $this->assertSame('-58.8306', $header['longitude']);
    }

    public function test_nombre_plantilla_meta_con_ubicacion_agrega_el_sufijo_mapa(): void
    {
        $this->assertSame('confirmacion_turno_mapa', WhatsappTemplate::nombrePlantillaMeta('confirmacion', true));
    }

    public function test_nombre_plantilla_meta_sin_ubicacion_no_cambia(): void
    {
        $this->assertSame('confirmacion_turno', WhatsappTemplate::nombrePlantillaMeta('confirmacion', false));
        $this->assertSame('confirmacion_turno', WhatsappTemplate::nombrePlantillaMeta('confirmacion'));
    }
}
