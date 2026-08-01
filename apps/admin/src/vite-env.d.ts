/// <reference types="vite/client" />

interface ImportMetaEnv {
  /**
   * Origem absoluta da API (https://api.to-menu.com), obrigatória no build de
   * produção. Ausente em desenvolvimento, onde o proxy do vite.config.ts
   * resolve `/api` na mesma origem.
   */
  readonly VITE_API_URL?: string;

  /**
   * Host exato que serve o painel da plataforma (`admin.to-menu.com`).
   *
   * Normalmente dispensável: o App.tsx reconhece o painel pelo prefixo
   * `admin.` do hostname, e isso já vale em desenvolvimento porque
   * `*.localhost` resolve nativamente. Existe como escape para hospedagens em
   * que o painel não mora num subdomínio `admin.`.
   */
  readonly VITE_PLATFORM_HOST?: string;

  /**
   * Origem do painel do lojista (`https://app.to-menu.com`), destino da
   * impersonação. Sem ela, o App.tsx deriva trocando `admin.` por `app.` no
   * host corrente — o que basta quando os dois painéis são subdomínios irmãos.
   * Defina como string vazia para manter tudo na mesma origem.
   */
  readonly VITE_STORE_ADMIN_URL?: string;

  /** Sitekey pública do Turnstile; ausente desliga o widget. */
  readonly VITE_TURNSTILE_SITE_KEY?: string;

  /**
   * Chave pública do Reverb — a mesma `REVERB_APP_KEY` da API. Ausente,
   * o painel funciona sem tempo real e o alerta de pedido novo não toca.
   */
  readonly VITE_REVERB_APP_KEY?: string;

  /**
   * Endereço público do servidor WebSocket. Em produção é o host que termina o
   * TLS na frente do Reverb (`ws.to-menu.com`), não o `0.0.0.0` que o processo
   * escuta. Ausente, assume o hostname do próprio painel.
   */
  readonly VITE_REVERB_HOST?: string;

  /** Porta do WebSocket: 8080 em desenvolvimento, 443 atrás do proxy TLS. */
  readonly VITE_REVERB_PORT?: string;

  /** `https` liga o wss. Qualquer outro valor mantém ws puro. */
  readonly VITE_REVERB_SCHEME?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
