<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappConnection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WhatsappConnectionUpsertTest extends TestCase
{
    use RefreshDatabase;

    private function datosConexion(array $override = []): array
    {
        return array_merge([
            'waba_id' => '111111111111111',
            'phone_number_id' => '222222222222222',
            'display_phone_number' => '5491122334455',
            'verified_name' => 'Salón Demo',
            'access_token' => 'EAAG-secreto-de-tenant',
            'token_expires_at' => null,
        ], $override);
    }

    public function test_upsert_por_user_id_no_crea_una_segunda_fila_al_re_ejecutar_es(): void
    {
        $user = User::factory()->create();

        WhatsappConnection::updateOrCreate(
            ['user_id' => $user->id],
            $this->datosConexion(['access_token' => 'token-viejo', 'phone_number_id' => '222222222222222']),
        );

        WhatsappConnection::updateOrCreate(
            ['user_id' => $user->id],
            $this->datosConexion(['access_token' => 'token-nuevo', 'phone_number_id' => '999999999999999']),
        );

        $this->assertSame(1, WhatsappConnection::where('user_id', $user->id)->count());

        $conexion = WhatsappConnection::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('token-nuevo', $conexion->access_token);
        $this->assertSame('999999999999999', $conexion->phone_number_id);
    }

    public function test_phone_number_id_es_unico_entre_salones(): void
    {
        $salonA = User::factory()->create();
        $salonB = User::factory()->create();

        WhatsappConnection::create($this->datosConexion([
            'user_id' => $salonA->id,
            'phone_number_id' => '222222222222222',
        ]));

        $this->expectException(QueryException::class);

        WhatsappConnection::create($this->datosConexion([
            'user_id' => $salonB->id,
            'phone_number_id' => '222222222222222',
        ]));
    }

    public function test_borrar_el_user_cascadea_y_elimina_la_conexion(): void
    {
        $user = User::factory()->create();
        WhatsappConnection::create($this->datosConexion(['user_id' => $user->id]));

        $user->delete();

        $this->assertSame(0, WhatsappConnection::count());
    }

    public function test_access_token_esta_cifrado_en_la_base_y_oculto_en_la_serializacion(): void
    {
        $user = User::factory()->create();
        WhatsappConnection::create($this->datosConexion([
            'user_id' => $user->id,
            'access_token' => 'EAAG-token-plano-de-tenant',
        ]));

        $crudo = DB::table('whatsapp_connections')->where('user_id', $user->id)->value('access_token');
        $this->assertNotSame('EAAG-token-plano-de-tenant', $crudo);
        $this->assertStringNotContainsString('EAAG-token-plano-de-tenant', (string) $crudo);

        $conexion = WhatsappConnection::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('EAAG-token-plano-de-tenant', $conexion->access_token);
        $this->assertArrayNotHasKey('access_token', $conexion->toArray());
    }

    public function test_token_expires_at_se_persiste_como_epoch_entero_crudo(): void
    {
        $user = User::factory()->create();
        $epoch = now()->addMonth()->timestamp;

        WhatsappConnection::create($this->datosConexion([
            'user_id' => $user->id,
            'token_expires_at' => $epoch,
        ]));

        $crudo = DB::table('whatsapp_connections')->where('user_id', $user->id)->value('token_expires_at');
        $this->assertEquals($epoch, $crudo);
        $this->assertSame($epoch, $user->whatsappConnection()->first()->token_expires_at);
    }

    public function test_estado_es_derivado_de_token_expires_at(): void
    {
        $user = User::factory()->create();

        $sinVencimiento = WhatsappConnection::create($this->datosConexion([
            'user_id' => $user->id,
            'token_expires_at' => null,
        ]));
        $this->assertSame('conectada', $sinVencimiento->estado);
        $this->assertTrue($sinVencimiento->estaVigente());

        $sinVencimiento->update(['token_expires_at' => now()->addDays(30)->timestamp]);
        $this->assertSame('conectada', $sinVencimiento->fresh()->estado);

        $sinVencimiento->update(['token_expires_at' => now()->addDays(3)->timestamp]);
        $porVencer = $sinVencimiento->fresh();
        $this->assertSame('por_vencer', $porVencer->estado);
        $this->assertTrue($porVencer->estaVigente());

        $sinVencimiento->update(['token_expires_at' => now()->subMinute()->timestamp]);
        $expirada = $sinVencimiento->fresh();
        $this->assertSame('expirada', $expirada->estado);
        $this->assertFalse($expirada->estaVigente());
    }
}
