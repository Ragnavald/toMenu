import { NextResponse } from 'next/server';
import type { NextRequest } from 'next/server';

const ROOT_DOMAIN = (process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost').toLowerCase();

/** Subdomínios da plataforma; nunca são lojas. */
const RESERVED = new Set(['www', 'app', 'api', 'admin', 'central']);

/**
 * A partir do Next.js 16 o antigo `middleware.ts` passou a se chamar
 * `proxy.ts`. A funcionalidade é a mesma; mudaram o nome do arquivo e da
 * função exportada.
 *
 * Reescreve host -> rota interna, para que a aplicação sempre trabalhe com
 * /[tenant]/... independentemente da forma de acesso:
 *
 *   pizzaria.tomenu.app   -> /pizzaria
 *   pizzaria.localhost    -> /pizzaria        (desenvolvimento)
 *   tomenu.app            -> /                (landing da plataforma)
 *   tomenu.app/pizzaria   -> /pizzaria        (passa direto, sem reescrita)
 *
 * A resolução autoritativa continua no backend: aqui apenas se decide qual
 * identificador enviar, e o Laravel valida se existe e está ativo.
 */
export function proxy(request: NextRequest) {
  // A porta precisa sair antes da comparação: em desenvolvimento o host chega
  // como "loja.localhost:3000" e o sufixo nunca casaria.
  const host = (request.headers.get('host') ?? '').split(':')[0].toLowerCase();
  const { pathname } = request.nextUrl;

  if (!host.endsWith(`.${ROOT_DOMAIN}`)) {
    return NextResponse.next();
  }

  const sub = host.slice(0, -(ROOT_DOMAIN.length + 1));

  // Subdomínio com ponto (loja.staging.tomenu.app) não é slug de loja.
  if (!sub || RESERVED.has(sub) || sub.includes('.')) {
    return NextResponse.next();
  }

  // As rotas próprias do storefront (/api/revalidate) não pertencem a loja
  // nenhuma. Sem esta saída, um POST para loja.to-menu.com/api/revalidate
  // viraria /loja/api/revalidate e responderia 404.
  if (pathname.startsWith('/api/')) {
    return NextResponse.next();
  }

  const url = request.nextUrl.clone();
  url.pathname = `/${sub}${pathname}`;

  return NextResponse.rewrite(url);
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],
};
