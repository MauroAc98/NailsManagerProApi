<?php

namespace Tests\Feature;

use App\Services\CloudApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudApiServiceEnviarPlantillaTest extends TestCase
{
    public function test_api_version_por_defecto_es_v26_sin_override_de_env(): void
    {
        // No seteamos WHATSAPP_CLOUD_API_VERSION: debe resolver al default
        // del config, alineado con la muestra confirmada de Meta App
        // Dashboard (webhook phone_number_quality_update, v26.0).
        $this->assertSame('v26.0', config('services.whatsapp_cloud.api_version'));
    }

    public function test_arma_el_payload_correcto_y_devuelve_el_message_id(): void
    {
        config([
            'services.whatsapp_cloud.token' => 'fake-token-123',
            'services.whatsapp_cloud.phone_number_id' => '1315423274987306',
            'services.whatsapp_cloud.api_version' => 'v26.0',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.ABC123']],
            ], 200),
        ]);

        $resultado = app(CloudApiService::class)->enviarPlantilla(
            '5493765123456',
            'recordatorio_turno',
            'es_AR',
            ['Martina', 'Nails Studio', '20/08/2026', '15:30', 'manicura semipermanente', '3765000000']
        );

        $this->assertSame('wamid.ABC123', $resultado->messageId);
        $this->assertSame(200, $resultado->statusCode);
        $this->assertSame(['messages' => [['id' => 'wamid.ABC123']]], $resultado->respuesta);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://graph.facebook.com/v26.0/1315423274987306/messages'
                && $request->hasHeader('Authorization', 'Bearer fake-token-123')
                && $body['messaging_product'] === 'whatsapp'
                && $body['to'] === '5493765123456'
                && $body['type'] === 'template'
                && $body['template']['name'] === 'recordatorio_turno'
                && $body['template']['language']['code'] === 'es_AR'
                && $body['template']['components'][0]['parameters'][0] === ['type' => 'text', 'text' => 'Martina']
                && $body['template']['components'][0]['parameters'][4] === ['type' => 'text', 'text' => 'manicura semipermanente']
                && $body['template']['components'][0]['parameters'][5] === ['type' => 'text', 'text' => '3765000000'];
        });
    }

    public function test_devuelve_null_y_loguea_cuando_meta_rechaza_el_envio(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid parameter']], 400),
        ]);

        $resultado = app(CloudApiService::class)->enviarPlantilla(
            '5493765123456',
            'recordatorio_turno',
            'es_AR',
            ['Martina', 'Nails Studio', '20/08/2026', '15:30', 'manicura semipermanente']
        );

        $this->assertNull($resultado->messageId);
        $this->assertSame(400, $resultado->statusCode);
        $this->assertSame(['error' => ['message' => 'Invalid parameter']], $resultado->respuesta);
    }

    // §Design "flat optional params, named arguments mandatory": pasar
    // token/phoneNumberId por argumento nombrado debe pegarle a ESE número
    // con ESE token, no al compartido resuelto en el constructor.
    public function test_con_token_y_phone_number_id_override_pega_al_numero_del_tenant(): void
    {
        config([
            'services.whatsapp_cloud.token' => 'token-compartido',
            'services.whatsapp_cloud.phone_number_id' => '1315423274987306',
            'services.whatsapp_cloud.api_version' => 'v26.0',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TENANT1']],
            ], 200),
        ]);

        $resultado = app(CloudApiService::class)->enviarPlantilla(
            '5493765123456',
            'recordatorio_turno',
            'es_AR',
            ['Martina', 'Nails Studio', '20/08/2026', '15:30', 'manicura semipermanente', '3765000000'],
            token: 'token-de-tenant',
            phoneNumberId: '999888777666555',
        );

        $this->assertSame('wamid.TENANT1', $resultado->messageId);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v26.0/999888777666555/messages'
                && $request->hasHeader('Authorization', 'Bearer token-de-tenant')
                && ! $request->hasHeader('Authorization', 'Bearer token-compartido');
        });
    }

    // Sin overrides el comportamiento debe seguir siendo byte-idéntico al
    // de hoy: null resuelve al token/número compartido del constructor.
    public function test_sin_overrides_el_comportamiento_es_identico_al_de_hoy(): void
    {
        config([
            'services.whatsapp_cloud.token' => 'token-compartido',
            'services.whatsapp_cloud.phone_number_id' => '1315423274987306',
            'services.whatsapp_cloud.api_version' => 'v26.0',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.COMPARTIDO1']],
            ], 200),
        ]);

        $resultado = app(CloudApiService::class)->enviarPlantilla(
            '5493765123456',
            'recordatorio_turno',
            'es_AR',
            ['Martina', 'Nails Studio', '20/08/2026', '15:30', 'manicura semipermanente', '3765000000'],
        );

        $this->assertSame('wamid.COMPARTIDO1', $resultado->messageId);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v26.0/1315423274987306/messages'
                && $request->hasHeader('Authorization', 'Bearer token-compartido');
        });
    }
}
