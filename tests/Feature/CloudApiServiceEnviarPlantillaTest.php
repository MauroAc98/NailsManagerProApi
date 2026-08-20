<?php

namespace Tests\Feature;

use App\Services\CloudApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudApiServiceEnviarPlantillaTest extends TestCase
{
    public function test_arma_el_payload_correcto_y_devuelve_el_message_id(): void
    {
        config([
            'services.whatsapp_cloud.token' => 'fake-token-123',
            'services.whatsapp_cloud.phone_number_id' => '1315423274987306',
            'services.whatsapp_cloud.api_version' => 'v25.0',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.ABC123']],
            ], 200),
        ]);

        $messageId = app(CloudApiService::class)->enviarPlantilla(
            '5493765123456',
            'recordatorio_turno',
            'es_AR',
            ['Martina', 'Nails Studio', '20/08/2026', '15:30', 'manicura semipermanente', '3765000000']
        );

        $this->assertSame('wamid.ABC123', $messageId);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://graph.facebook.com/v25.0/1315423274987306/messages'
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

        $messageId = app(CloudApiService::class)->enviarPlantilla(
            '5493765123456',
            'recordatorio_turno',
            'es_AR',
            ['Martina', 'Nails Studio', '20/08/2026', '15:30', 'manicura semipermanente']
        );

        $this->assertNull($messageId);
    }
}
