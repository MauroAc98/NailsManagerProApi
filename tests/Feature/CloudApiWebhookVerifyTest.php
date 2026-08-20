<?php

namespace Tests\Feature;

use Tests\TestCase;

class CloudApiWebhookVerifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp_cloud.verify_token' => 'token-de-test']);
    }

    public function test_responde_el_challenge_cuando_el_token_es_correcto(): void
    {
        $response = $this->get('/api/webhooks/whatsapp-cloud?hub.mode=subscribe&hub.verify_token=token-de-test&hub.challenge=abc123');

        $response->assertOk();
        $this->assertSame('abc123', $response->getContent());
    }

    public function test_devuelve_403_cuando_el_token_es_incorrecto(): void
    {
        $response = $this->get('/api/webhooks/whatsapp-cloud?hub.mode=subscribe&hub.verify_token=otro-token&hub.challenge=abc123');

        $response->assertStatus(403);
    }

    public function test_devuelve_403_cuando_el_modo_no_es_subscribe(): void
    {
        $response = $this->get('/api/webhooks/whatsapp-cloud?hub.mode=unsubscribe&hub.verify_token=token-de-test&hub.challenge=abc123');

        $response->assertStatus(403);
    }
}
