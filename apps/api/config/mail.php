<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    /*
     * Remetente.
     *
     * O default é "ToMenu", e não o `env('APP_NAME')` que vinha do framework.
     * O encadeamento antigo tinha DOIS pontos de falha silenciosa: sem
     * MAIL_FROM_NAME caía no APP_NAME, e sem APP_NAME caía em "Laravel" — foi
     * assim que o e-mail de confirmação saiu assinado como Laravel. Um default
     * errado aqui não quebra nada em teste; só aparece na caixa do lojista.
     */
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'suporte@to-menu.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'ToMenu')),
    ],

    /*
     * Logo do cabeçalho dos e-mails (resources/views/vendor/mail/html/header).
     *
     * Precisa ser URL absoluta e pública: o cliente de e-mail busca a imagem de
     * fora, sem sessão e sem cookie. Aponta para o wordmark que o nginx já
     * serve junto do painel, então não há host nem asset novo para manter.
     *
     * Vazia desativa a imagem e o cabeçalho volta a ser o nome em texto — que
     * é o que se quer em desenvolvimento, onde `app.to-menu.com` não existe.
     */
    'logo_url' => env('MAIL_LOGO_URL'),

];
