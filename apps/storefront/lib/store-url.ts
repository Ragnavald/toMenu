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

const ROOT_DOMAIN = process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost';

/**
 * URL canônica absoluta da loja.
 *
 * A mesma loja responde em dois endereços — subdomínio e caminho — e para o
 * buscador isso é conteúdo duplicado: ele escolhe um sozinho, divide o sinal de
 * relevância entre os dois e pode indexar o que a loja não divulga.
 *
 * O subdomínio é eleito canônico porque é o endereço que a plataforma imprime
 * no QR code e vende como "endereço próprio da sua loja".
 *
 * Em desenvolvimento devolve o caminho, já que *.localhost não tem TLS e uma
 * canônica https quebraria a navegação local.
 */
export function canonicalStoreUrl(tenantSlug: string, path = ''): string {
  const suffix = path ? `/${path}` : '';

  if (ROOT_DOMAIN === 'localhost') {
    return `http://${tenantSlug}.localhost:3000${suffix}`;
  }

  return `https://${tenantSlug}.${ROOT_DOMAIN}${suffix}`;
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
