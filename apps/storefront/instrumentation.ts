import * as Sentry from '@sentry/nextjs';

/**
 * Monitoramento de erros do lado servidor do storefront.
 *
 * Só o servidor, de propósito. Não existe `instrumentation-client.ts` aqui: o
 * cardápio é a página mais acessada da plataforma, quase toda em Server
 * Components, e carregar o SDK no navegador custaria dezenas de KB no bundle
 * de cada visitante do QR code — pagando LCP para capturar erro de JavaScript
 * numa página que quase não tem JavaScript.
 *
 * O que este arquivo cobre é o que dói: falha ao renderizar o cardápio, erro
 * no fetch da API durante o SSR, e exceção em rota de servidor.
 *
 * Sem DSN nada é inicializado, então desenvolvimento e build seguem intocados.
 */
export async function register() {
  if (!process.env.SENTRY_DSN) return;

  // O runtime edge tem API própria e não é usado por este app (o proxy roda em
  // Node); inicializar só o que existe evita erro de import no build.
  if (process.env.NEXT_RUNTIME === 'nodejs') {
    Sentry.init({
      dsn: process.env.SENTRY_DSN,
      environment: process.env.SENTRY_ENVIRONMENT ?? 'production',
      // Erro é o que interessa; tracing consome a cota gratuita rápido e não
      // responde nenhuma pergunta que a gente tenha hoje.
      tracesSampleRate: 0,
    });
  }
}

/**
 * Captura erro de Server Component, proxy e rota de servidor.
 *
 * Sem este export, uma exceção durante o render do cardápio vira apenas uma
 * página de erro para o visitante e uma linha no log do container — que
 * ninguém lê no momento em que acontece.
 */
export const onRequestError = Sentry.captureRequestError;
