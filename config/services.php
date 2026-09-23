<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp_cloud' => [
        'token' => env('WHATSAPP_CLOUD_TOKEN'),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID'),
        'api_version' => env('WHATSAPP_CLOUD_API_VERSION', 'v26.0'),
        'verify_token' => env('WHATSAPP_CLOUD_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_CLOUD_APP_SECRET'),
        'calidad_bloqueante' => array_filter(explode(',', (string) env('WHATSAPP_CLOUD_CALIDAD_BLOQUEANTE', 'RED'))),
        // Identifica la WABA propia de Turnetto en los webhooks de calidad:
        // permite detectar un entry.id desconocido/huérfano (alarma de
        // suscripción huérfana, ver EmbeddedSignupService). Nullable.
        'waba_id' => env('WHATSAPP_CLOUD_WABA_ID'),
        // Interruptor de la respuesta automatica a los mensajes entrantes del
        // numero compartido (ver AutorespuestaEntrante). Prendido por defecto;
        // WHATSAPP_AUTORESPUESTA_HABILITADA=false la apaga sin cambiar codigo.
        'autorespuesta_habilitada' => (bool) env('WHATSAPP_AUTORESPUESTA_HABILITADA', true),
    ],

    // Cloudflare Turnstile (reto anti-bot de la reserva online). Solo se usa si
    // RESERVAS_CHALLENGE_HABILITADO=true.
    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET'),
    ],

    'frontend_url' => env('FRONTEND_URL'),

];