<?php

namespace Tests\Feature\Contract;

use App\Models\WhatsappMensaje;

/**
 * Characterization pins for GET /auth/me, PUT /perfil (raw User model) and
 * GET /turnos/notificaciones.
 */
class AdminPerfilNotificacionesShapeTest extends AdminContractTestCase
{
    private function userShape(): array
    {
        return [
            'id' => 'int', 'name' => 'string', 'email' => 'string', 'slug' => 'string',
            'email_verified_at' => 'string', 'created_at' => 'string', 'updated_at' => 'string',
            'telefono' => 'string', 'direccion' => 'string', 'locale' => 'string',
            'latitud' => 'number', 'longitud' => 'number',
            'recordatorio_automatico' => 'bool', 'confirmacion_automatica' => 'bool',
            'hora_recordatorio' => 'string', 'is_exempt' => 'bool', 'debe_cambiar_password' => 'bool',
            'whatsapp_pide_sena' => 'bool', 'sena_monto' => 'string',
            'whatsapp_sena_titular' => 'string|null', 'whatsapp_sena_entidad' => 'string|null',
            'whatsapp_sena_alias' => 'string|null', 'whatsapp_sena_cbu' => 'string|null',
            'notificaciones_vistas_at' => 'string|null',
            'categorias_gasto' => 'array', 'categorias_ingreso' => 'array',
            'whatsapp_requiere_envio_manual' => 'bool', 'logo_url' => 'string|null',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->user->update([
            'telefono' => '3765000000', 'locale' => 'es', 'latitud' => -27.4, 'longitud' => -58.9, 'sena_monto' => 500,
        ]);
    }

    public function test_me_shape(): void
    {
        $json = $this->admin()->getJson('/api/auth/me')->assertOk()->json();

        $this->assertContractShape($this->userShape(), $json);
        foreach (['password', 'remember_token', 'fcm_token', 'logo_path'] as $oculto) {
            $this->assertArrayNotHasKey($oculto, $json);
        }
    }

    public function test_update_perfil_shape(): void
    {
        $json = $this->admin()->putJson('/api/perfil', ['telefono' => '3765111111'])->assertOk()->json();

        $this->assertContractShape($this->userShape() + ['whatsapp_connection' => 'array|null'], $json);
        $this->assertSame('3765111111', $json['telefono']);
    }

    public function test_notificaciones_shape(): void
    {
        $turno = $this->crearTurno();
        WhatsappMensaje::create([
            'user_id' => $this->user->id, 'turno_id' => $turno->id, 'numero' => '3765252395',
            'provider' => 'cloud_api', 'mensaje' => 'Hola Cli', 'tipo' => 'confirmacion',
            'message_id' => 'wamid.1', 'status' => 'delivered',
        ]);

        $json = $this->admin()->getJson('/api/turnos/notificaciones')->assertOk()->json();

        $this->assertContractShape([
            'turnos_manana' => 'int',
            'no_vistos' => 'int',
            'mensajes' => ['*' => [
                'id' => 'int', 'tipo' => 'string', 'status' => 'string',
                'cliente_nombre' => 'string', 'cliente_apellido' => 'string|null',
                'created_at' => 'string', 'mensaje' => 'string',
            ]],
        ], $json);
    }
}
