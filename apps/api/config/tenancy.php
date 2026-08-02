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
     * Onde o painel do lojista responde. Usado para montar o link de
     * redefinição de senha, que é servido pelo painel e não pela API.
     *
     * Em produção é `app.{root_domain}`; em desenvolvimento o Vite sobe em
     * :5173, sem TLS. Explícito no env porque um link errado aqui não quebra
     * nada visível — só entrega ao lojista uma URL que não abre.
     */
    'admin_url' => env('TENANCY_ADMIN_URL'),

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

        /*
         * Quanto tempo a CDN pode servir o cardápio sem consultar a origem.
         *
         * Curto por ser apenas rede de proteção: a invalidação real acontece
         * por evento (ver 'purge'), no instante da edição. Antes eram 60s aqui
         * e 60s de ISR no Next — dois TTLs independentes que se somavam no
         * pior caso e faziam uma alteração demorar até ~2min para aparecer.
         */
        'cdn_ttl' => env('TENANCY_CDN_TTL', 30),
    ],

    /*
     * Invalidação sob demanda das camadas de cache que ficam fora do Laravel.
     *
     * Sem isto o cardápio só atualiza por expiração de TTL, e a loja edita um
     * preço sem ver o resultado — o motivo de existir este bloco é derrubar
     * esse tempo de minutos para segundos.
     *
     * Ambos os alvos são opcionais: sem configuração, o purge é ignorado em
     * silêncio e o sistema volta a depender do TTL, que continua correto.
     */
    'purge' => [
        // URL interna do storefront (ex.: http://storefront:3000). Interna
        // porque a chamada não precisa sair para a internet e voltar.
        'storefront_url' => env('STOREFRONT_INTERNAL_URL'),
        // Segredo compartilhado com REVALIDATE_SECRET do storefront.
        'revalidate_secret' => env('REVALIDATE_SECRET'),

        // Purge do cache de borda. Sem o token, só o Next é invalidado — e o
        // visitante ainda espera o cdn_ttl acima.
        'cloudflare_zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'cloudflare_api_token' => env('CLOUDFLARE_API_TOKEN'),

        // Timeout curto: isto roda numa fila, mas um alvo inacessível não pode
        // segurar o worker e atrasar os outros jobs.
        'timeout' => (int) env('TENANCY_PURGE_TIMEOUT', 5),
    ],
];
