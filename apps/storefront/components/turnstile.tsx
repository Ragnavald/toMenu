'use client';

import { useEffect, useRef } from 'react';
import Script from 'next/script';

const SITE_KEY = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY ?? '';

/**
 * Renderização explícita (`render=explicit`) em vez da automática: só assim
 * temos o id do widget para poder resetá-lo. Um token vale uma única
 * verificação, então todo envio recusado precisa de um token novo.
 */
const SCRIPT_SRC =
  'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

type TurnstileApi = {
  render: (el: HTMLElement, options: Record<string, unknown>) => string;
  reset: (widgetId: string) => void;
  remove: (widgetId: string) => void;
};

declare global {
  interface Window {
    turnstile?: TurnstileApi;
  }
}

/** Sem sitekey configurada o widget some — e o backend também não exige token. */
export function isTurnstileEnabled(): boolean {
  return SITE_KEY !== '';
}

export type TurnstileHandle = { reset: () => void };

export function Turnstile({
  onToken,
  handleRef,
}: {
  onToken: (token: string) => void;
  /** Preenchido com o `reset`, para o formulário chamar após uma falha. */
  handleRef?: React.RefObject<TurnstileHandle | null>;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const widgetIdRef = useRef<string | null>(null);

  // Guardado em ref para o efeito de montagem não depender da identidade da
  // função: o widget é montado uma única vez, e recriá-lo a cada render
  // descartaria um token já resolvido.
  const onTokenRef = useRef(onToken);
  onTokenRef.current = onToken;

  // Desmontagem: solta o widget para não deixar iframe órfão ao trocar de rota.
  useEffect(() => {
    return () => {
      const id = widgetIdRef.current;
      if (id !== null) {
        window.turnstile?.remove(id);
        widgetIdRef.current = null;
      }
    };
  }, []);

  if (!isTurnstileEnabled()) return null;

  /*
   * `onReady` roda quando o script carrega e também a cada remontagem do
   * componente — que é justamente quando o widget precisa ser recriado. `onLoad`
   * sozinho não cobriria a remontagem com o script já em cache.
   */
  function mount() {
    const api = window.turnstile;
    const el = containerRef.current;

    if (!api || !el || widgetIdRef.current !== null) return;

    widgetIdRef.current = api.render(el, {
      sitekey: SITE_KEY,
      callback: (token: string) => onTokenRef.current(token),
      // Token expirado sem envio: pede outro sozinho, senão o usuário que
      // demora preenchendo o formulário recebe um erro no envio.
      'expired-callback': () => onTokenRef.current(''),
      'error-callback': () => onTokenRef.current(''),
    });

    if (handleRef) {
      handleRef.current = {
        reset: () => {
          if (widgetIdRef.current !== null) {
            api.reset(widgetIdRef.current);
            onTokenRef.current('');
          }
        },
      };
    }
  }

  return (
    <>
      <Script
        id="cf-turnstile"
        src={SCRIPT_SRC}
        strategy="afterInteractive"
        onReady={mount}
      />
      <div ref={containerRef} />
    </>
  );
}
