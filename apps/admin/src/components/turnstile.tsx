import { useEffect, useRef } from 'react';

/**
 * Sitekey do Turnstile. Pública por natureza — o que protege é o secret, que
 * fica só na API. Como toda VITE_*, é inlined no bundle: mudar exige rebuild.
 *
 * Vazia em desenvolvimento, onde a verificação também está desligada no
 * backend; aí o widget não é renderizado e o login segue direto.
 */
const SITE_KEY = import.meta.env.VITE_TURNSTILE_SITE_KEY ?? '';

/** `render=explicit` para termos o id do widget e poder resetá-lo. */
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

export function isTurnstileEnabled(): boolean {
  return SITE_KEY !== '';
}

export type TurnstileHandle = { reset: () => void };

/** Carrega o script uma única vez por página, mesmo com vários widgets. */
let scriptPromise: Promise<void> | null = null;

function loadScript(): Promise<void> {
  if (scriptPromise) return scriptPromise;

  scriptPromise = new Promise<void>((resolve, reject) => {
    const script = document.createElement('script');
    script.src = SCRIPT_SRC;
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => {
      // Zera para uma nova tentativa poder recarregar; senão o widget ficaria
      // permanentemente quebrado após uma falha de rede passageira.
      scriptPromise = null;
      reject(new Error('Falha ao carregar o Turnstile.'));
    };
    document.head.appendChild(script);
  });

  return scriptPromise;
}

export function Turnstile({
  onToken,
  handleRef,
}: {
  onToken: (token: string) => void;
  handleRef?: React.RefObject<TurnstileHandle | null>;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const widgetIdRef = useRef<string | null>(null);

  // Em ref para o efeito não depender da identidade da função: recriar o widget
  // a cada render descartaria um token já resolvido.
  const onTokenRef = useRef(onToken);
  onTokenRef.current = onToken;

  useEffect(() => {
    if (!isTurnstileEnabled()) return;

    // O StrictMode do React monta o efeito duas vezes em desenvolvimento;
    // sem isto o widget seria renderizado em duplicata.
    let cancelled = false;

    loadScript()
      .then(() => {
        const api = window.turnstile;
        const el = containerRef.current;

        if (cancelled || !api || !el || widgetIdRef.current !== null) return;

        widgetIdRef.current = api.render(el, {
          sitekey: SITE_KEY,
          callback: (token: string) => onTokenRef.current(token),
          // Token expirado antes do envio: pede outro sozinho.
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
      })
      .catch(() => {
        // Script inacessível: o backend falha aberto no mesmo cenário, então
        // travar o formulário aqui só impediria um login legítimo.
      });

    return () => {
      cancelled = true;

      const id = widgetIdRef.current;
      if (id !== null) {
        window.turnstile?.remove(id);
        widgetIdRef.current = null;
      }
    };
  }, [handleRef]);

  if (!isTurnstileEnabled()) return null;

  return <div ref={containerRef} />;
}
