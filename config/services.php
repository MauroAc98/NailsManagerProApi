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
    ],

    // Embedded Signup (Coexistence) — onboarding del número propio de cada
    // salón. Mismo Meta app que whatsapp_cloud: se reusa WHATSAPP_CLOUD_APP_SECRET
    // a propósito para no duplicar el mismo secreto bajo dos nombres.
    'whatsapp_es' => [
        'enabled' => (bool) env('WHATSAPP_ES_ENABLED', false),
        // CSV de user_id habilitados. Vacío = NADIE (fail-closed). Para
        // habilitar a todos los salones hay que setear explícitamente
        // WHATSAPP_ES_ALLOW_ALL=true; una allowlist vacía o borrada por error
        // durante la ventana gated no debe exponer el onboarding en silencio.
        'allowed_user_ids' => array_filter(array_map('intval', explode(',', (string) env('WHATSAPP_ES_ALLOWED_USER_IDS', '')))),
        'allow_all' => (bool) env('WHATSAPP_ES_ALLOW_ALL', false),
        'app_id' => env('WHATSAPP_ES_APP_ID'),
        'app_secret' => env('WHATSAPP_CLOUD_APP_SECRET'),
        'config_id' => env('WHATSAPP_ES_CONFIG_ID'),
        'graph_version' => env('WHATSAPP_ES_GRAPH_VERSION', env('WHATSAPP_CLOUD_API_VERSION', 'v26.0')),
    ],

    'frontend_url' => env('FRONTEND_URL'),

];