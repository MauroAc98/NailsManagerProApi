<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El webhook viejo de Mercado Pago debe fallar CERRADO: sin secreto
 * configurado no se puede verificar nada (antes hash_hmac con clave vacia
 * dejaba a cualquiera falsificar una firma valida), y una cabecera mal
 * formada no puede romper con un 500.
 */
class WebhookMercadoPagoFirmaTest extends TestCase
{
    use RefreshDatabase;

    private function firmaFalsificadaConClaveVacia(string $dataId, string $requestId): string
    {
        $manifest = "id:{$dataId};request-id:{$requestId};";

        return 'ts=1,v1=' . hash_hmac('sha256', $manifest, '');
    }

    public function test_sin_secreto_configurado_responde_503_aunque_la_firma_sea_valida_con_clave_vacia(): void
    {
        config(['services.mercadopago.webhook_secret' => null]);

        $this->postJson('/api/webhooks/mercadopago?data_id=123', ['type' => 'payment'], [
            'x-signature' => $this->firmaFalsificadaConClaveVacia('123', 'req-1'),
            'x-request-id' => 'req-1',
        ])->assertStatus(503);
    }

    public function test_con_secreto_una_firma_incorrecta_es_401(): void
    {
        config(['services.mercadopago.webhook_secret' => 'secreto-real']);

        $this->postJson('/api/webhooks/mercadopago?data_id=123', ['type' => 'payment'], [
            'x-signature' => $this->firmaFalsificadaConClaveVacia('123', 'req-1'),
            'x-request-id' => 'req-1',
        ])->assertStatus(401);
    }

    public function test_una_cabecera_de_firma_mal_formada_es_401_y_no_un_500(): void
    {
        config(['services.mercadopago.webhook_secret' => 'secreto-real']);

        $this->postJson('/api/webhooks/mercadopago?data_id=123', ['type' => 'payment'], [
            'x-signature' => 'basura-sin-igual',
            'x-request-id' => 'req-1',
        ])->assertStatus(401);
    }

    public function test_sin_cabeceras_de_firma_es_401(): void
    {
        config(['services.mercadopago.webhook_secret' => 'secreto-real']);

        $this->postJson('/api/webhooks/mercadopago?data_id=123', ['type' => 'payment'])->assertStatus(401);
    }
}
