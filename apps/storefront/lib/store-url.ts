import { headers } from 'next/headers';

/**
 * Raiz da loja no endereço que o visitante está enxergando.
 *
 * A mesma loja é servida de duas formas, e elas não compartilham prefixo:
 *
 *   pizzaria.to-menu.com/       → raiz "/"          (proxy reescreve por dentro)
 *   to-menu.com/pizzaria        → raiz "/pizzaria"
 *
 * Nos dois casos a página recebe o mesmo `params.tenant`, então o slug sozinho
 * não distingue um do outro — quem sabe é o proxy, que marca a requisição com
 * x-tenant-subdomain quando resolveu a loja pelo host.
 *
 * Isto existe porque href relativo ("loja", "./") não resolve igual nos dois:
 * a partir de /pizzaria SEM barra final o navegador descarta o último segmento
 * e "loja" vira /loja — uma rota que não pertence a loja alguma, e responde
 * 404. Montar o caminho absoluto remove a dependência de como o navegador
 * interpreta a URL atual.
 */
export async function storeBasePath(tenantSlug: string): Promise<string> {
  const fromSubdomain = (await headers()).get('x-tenant-subdomain') === '1';

  return fromSubdomain ? '' : `/${tenantSlug}`;
}

/**
 * Caminho absoluto de uma rota interna da loja.
 *
 * `storeHref(slug, 'loja')` devolve "/loja" no subdomínio e "/pizzaria/loja"
 * no acesso por caminho. Passar '' devolve a raiz do cardápio.
 */
export async function storeHref(
  tenantSlug: string,
  path = '',
): Promise<string> {
  const base = await storeBasePath(tenantSlug);
  const suffix = path ? `/${path}` : '';

  // No subdomínio, base é "" e um path vazio resultaria em href="" — que o
  // navegador trata como "a URL atual, inclusive query string". "/" é o
  // equivalente correto.
  return `${base}${suffix}` || '/';
}
