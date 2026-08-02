import type { MetadataRoute } from 'next';
import { headers } from 'next/headers';

const ROOT_DOMAIN = (process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost').toLowerCase();

const RESERVED = new Set(['www', 'app', 'api', 'admin', 'central']);

/**
 * Sitemap por host.
 *
 * No subdomínio da loja lista as páginas daquela loja; na landing, as da
 * plataforma. O mesmo build serve os dois, então a decisão é pelo Host.
 *
 * A landing não lista as lojas de propósito: não existe endpoint público que
 * as enumere (só o do staff, autenticado), e expor o catálogo inteiro de
 * clientes é decisão de produto, não detalhe de implementação. Cada loja é
 * descoberta pelo próprio sitemap, no seu subdomínio — que é o endereço
 * canônico dela de qualquer forma.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const host = (await headers()).get('host')?.split(':')[0].toLowerCase() ?? ROOT_DOMAIN;

  const sub = host.endsWith(`.${ROOT_DOMAIN}`)
    ? host.slice(0, -(ROOT_DOMAIN.length + 1))
    : null;

  const isStore = Boolean(sub) && !RESERVED.has(sub!) && !sub!.includes('.');

  // http em desenvolvimento: *.localhost não tem TLS.
  const scheme = ROOT_DOMAIN === 'localhost' ? 'http' : 'https';
  const port = ROOT_DOMAIN === 'localhost' ? ':3000' : '';
  const base = `${scheme}://${host}${port}`;
  const now = new Date();

  if (isStore) {
    return [
      {
        url: base,
        lastModified: now,
        // O cardápio muda com frequência e é a página que importa indexar.
        changeFrequency: 'daily',
        priority: 1,
      },
      {
        url: `${base}/loja`,
        lastModified: now,
        // Endereço e horário mudam pouco.
        changeFrequency: 'monthly',
        priority: 0.8,
      },
    ];
  }

  const landing = `${scheme}://${ROOT_DOMAIN}${port}`;

  return [
    {
      url: landing,
      lastModified: now,
      changeFrequency: 'weekly',
      priority: 1,
    },
    // Os documentos legais mudam raramente e não disputam relevância com a
    // landing, mas precisam ser indexáveis: é comum o lojista procurá-los pelo
    // buscador em vez de navegar pelo rodapé.
    ...['/termos', '/privacidade', '/lgpd'].map((path) => ({
      url: `${landing}${path}`,
      lastModified: now,
      changeFrequency: 'yearly' as const,
      priority: 0.3,
    })),
  ];
}
