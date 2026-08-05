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

  // A rota própria do storefront não pertence a loja nenhuma. Sem esta saída,
  // um POST para loja.to-menu.com/api/revalidate viraria
  // /loja/api/revalidate e responderia 404.
  //
  // A exceção é só para este caminho, e não para todo /api/: as demais rotas
  // sob /api/ são do Laravel (/api/{slug}/menu) e precisam da reescrita para
  // que o nginx as encaminhe à API. Excluir o prefixo inteiro fazia o Next
  // responder 404 em HTML para o cardápio de todas as lojas.
  if (pathname === '/api/revalidate') {
    return NextResponse.next();
  }

  /*
   * robots.txt e sitemap.xml pertencem ao host, não à loja.
   *
   * Sem esta saída eles viram /pizzaria/robots.txt — rota inexistente — e o
   * buscador recebe a página 404 no lugar das diretivas. Os handlers já leem o
   * Host para decidir o que responder (ver app/robots.ts), então basta deixá-los
   * passar sem reescrita.
   */
  if (
    pathname === '/robots.txt' ||
    pathname === '/sitemap.xml' ||
    pathname === '/sw.js' ||
    pathname === '/manifest.json' ||
    pathname.startsWith('/img/') ||
    pathname === '/cooked.webp' ||
    pathname === '/cooked.jpg'
  ) {
    return NextResponse.next();
  }

  const url = request.nextUrl.clone();
  url.pathname = `/${sub}${pathname}`;

  /*
   * Sinaliza que o slug veio do host, não da URL visível.
   *
   * A reescrita é interna: o navegador continua em pizzaria.tomenu.app/, sem
   * o segmento do slug. Quem monta link precisa saber disso — no subdomínio a
   * raiz da loja é "/", no acesso por caminho é "/pizzaria". Sem este sinal a
   * página não tem como distinguir os dois casos, porque em ambos ela recebe
   * o mesmo `params.tenant`.
   */
  const headers = new Headers(request.headers);
  headers.set('x-tenant-subdomain', '1');

  return NextResponse.rewrite(url, { request: { headers } });
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],
};
