<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserMpCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El access_token de Mercado Pago es una credencial de la cuenta del negocio:
 * un toJson()/toArray() accidental de la relacion (alcanzable desde un User
 * serializado) no debe emitirlo, ni cifrado ni en claro. Mismo criterio que
 * WhatsappConnection::$hidden.
 */
class UserMpCredentialOcultaTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_array_no_incluye_el_access_token(): void
    {
        $user = User::factory()->create();
        $credencial = UserMpCredential::create([
            'user_id' => $user->id,
            'mp_access_token' => 'APP_USR-secreto-de-verdad',
            'mp_user_id' => 'MP-123',
        ]);

        $this->assertArrayNotHasKey('mp_access_token', $credencial->toArray());
        $this->assertStringNotContainsString('APP_USR-secreto-de-verdad', $credencial->toJson());
    }

    public function test_serializar_el_user_con_la_relacion_cargada_tampoco_filtra_el_token(): void
    {
        $user = User::factory()->create();
        UserMpCredential::create([
            'user_id' => $user->id,
            'mp_access_token' => 'APP_USR-secreto-de-verdad',
            'mp_user_id' => 'MP-123',
        ]);

        $json = $user->load('mpCredentials')->toJson();

        $this->assertStringNotContainsString('APP_USR-secreto-de-verdad', $json);
    }
}
