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

    // Reportes con IA (texto → SQL de solo lectura). Desactivado por defecto.
    'groq' => [
        'enabled' => (bool) env('AI_REPORTS_ENABLED', false),
        'key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'llama-3.1-8b-instant'),
        'url' => env('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions'),
        'connection' => env('AI_REPORTS_DB_CONNECTION', 'mysql_reports'),
        'max_rows' => (int) env('AI_REPORTS_MAX_ROWS', 500),
        'timeout_ms' => (int) env('AI_REPORTS_TIMEOUT_MS', 5000),
    ],

    // Pago en línea del carrito. Sin credenciales el checkout en línea queda
    // desactivado (no se registra ninguna venta sin pago verificado).
    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_CLIENT_SECRET'),
        'mode' => env('PAYPAL_MODE', 'sandbox'), // sandbox | live
        // Moneda en la que cobra PayPal (no admite BOB). Los importes de la
        // aplicación son siempre BOB y se convierten con la tasa del día
        // registrada por el administrador (tabla tipos_cambio).
        'currency' => env('PAYPAL_CURRENCY', 'USD'),
        // ID del webhook configurado en PayPal: obligatorio para verificar firmas.
        // Sin él, el pago en línea queda desactivado.
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],

];
