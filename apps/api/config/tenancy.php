<?php

return [
    /*
     * Domínio raiz da plataforma. Subdomínios de um nível abaixo dele são
     * tratados como slugs de tenant; qualquer outro host é recusado.
     *
     * Precisa casar com o certificado wildcard `*.dominio` do Cloudflare, que
     * cobre exatamente um nível — host fora disso não teria TLS válido.
     */
    'root_domain' => env('TENANCY_ROOT_DOMAIN', 'tomenu.test'),

    /*
     * Como montar a URL pública de cada loja. Em desenvolvimento o storefront
     * responde em :3000 e sem TLS; em produção, 443 implícito.
     */
    'storefront_scheme' => env('TENANCY_STOREFRONT_SCHEME', 'http'),
    'storefront_port' => env('TENANCY_STOREFRONT_PORT'),

    /*
     * Aceitar o header X-Tenant para identificar o tenant.
     *
     * Apenas para desenvolvimento, onde não há wildcard DNS configurado.
     * DEVE ser false em produção: o header é controlado pelo cliente, e confiar
     * nele permitiria a qualquer visitante escolher o tenant que quisesse.
     */
    'trust_header' => (bool) env('TENANCY_TRUST_HEADER', false),

    'cache' => [
        'menu_ttl' => env('TENANCY_MENU_TTL', 3600),
        // Janela em que a versão obsoleta é servida enquanto outro processo
        // reconstrói o cache — evita stampede no pico do almoço.
        'stale_ttl' => env('TENANCY_STALE_TTL', 86400),
    ],
];
