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

    public function test_header_ubicacion_usa_las_coordenadas_como_address_y_nunca_la_direccion_escrita(): void
    {
        // Meta exige `address`, y Google Maps arma la búsqueda con ese texto
        // (address + name), no con lat/long: un texto libre geocodifica "en
        // cualquier lado". "lat,lon" es un formato que Maps resuelve como un
        // punto exacto. La dirección escrita ya viaja en el cuerpo ({{6}}).
        $user = User::factory()->create([
            'latitud' => -27.4333825,
            'longitud' => -55.9110309,
            'direccion' => "Av. Siempre Viva 742
Entre Piso 1",
        ]);

        $header = WhatsappTemplate::headerUbicacionCloudApi($user);

        $this->assertSame('-27.4333825,-55.9110309', $header['address']);
        $this->assertStringNotContainsString('Siempre Viva', $header['address']);
    }

    public function test_header_ubicacion_incluye_address_aunque_no_haya_direccion_cargada(): void
    {
        $user = User::factory()->create([
            'latitud' => -27.4692,
            'longitud' => -58.8306,
            'direccion' => null,
        ]);

        $header = WhatsappTemplate::headerUbicacionCloudApi($user);

        $this->assertSame('-27.4692,-58.8306', $header['address']);
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
