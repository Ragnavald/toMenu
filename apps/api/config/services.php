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

    /*
     * Cloudflare Turnstile — verificação de robô no login e no cadastro.
     *
     * Sem `secret` a verificação fica desligada (dev e testes). Em produção as
     * duas chaves são obrigatórias: a sitekey é pública e vai no build dos
     * frontends, o secret nunca sai do servidor.
     */
    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET'),
        // Curto de propósito: é uma chamada síncrona no caminho do login.
        'timeout' => env('TURNSTILE_TIMEOUT', 5),
    ],

    /*
     * Stripe — dois conjuntos de chaves, um modo ativo.
     *
     * STRIPE_MODE (test|live) escolhe qual trio de credenciais vale. Os dois
     * convivem no mesmo .env de propósito: trocar de ambiente vira a edição de
     * UMA linha, e não a substituição de três chaves à mão — operação em que
     * esquecer o webhook secret para trás é o erro clássico, e silencioso,
     * porque a cobrança funciona e só a confirmação some.
     *
     * As chaves de teste também servem de fallback do modo live: um deploy com
     * STRIPE_MODE=live e as chaves live ausentes falha na validação do
     * StripeServiceProvider, e não com uma cobrança real feita por engano.
     */
    'stripe' => [
        'mode' => env('STRIPE_MODE', 'test'),

        'test' => [
            'key' => env('STRIPE_TEST_KEY'),
            'secret' => env('STRIPE_TEST_SECRET'),
            'webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
        ],

        'live' => [
            'key' => env('STRIPE_LIVE_KEY'),
            'secret' => env('STRIPE_LIVE_SECRET'),
            'webhook_secret' => env('STRIPE_LIVE_WEBHOOK_SECRET'),
        ],

        // Comissão retida pela plataforma em cada pedido (application_fee).
        'platform_fee_percent' => env('STRIPE_PLATFORM_FEE_PERCENT', 5.0),
    ],

];
