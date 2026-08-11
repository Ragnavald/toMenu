import type { MetadataRoute } from 'next';
import { headers } from 'next/headers';

const ROOT_DOMAIN = (process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost').toLowerCase();

const RESERVED = new Set(['www', 'app', 'api', 'admin', 'central']);

/**
 * robots.txt por host.
 *
 * O mesmo build serve a landing e todas as lojas, e o sitemap de cada uma é
 * diferente: apontar todo mundo para o sitemap da plataforma faria o buscador
 * ignorar as páginas das lojas, que são o conteúdo que interessa indexar.
 *
 * Hoje quem responde /robots.txt em produção é o arquivo automático da
 * Cloudflare, que não declara sitemap nenhum. Este arquivo assume o caminho e
 * passa a declarar — sem ele o buscador só encontra as páginas por link.
 *
 * O llms.txt (app/llms.txt/route.ts) decide por host da mesma forma, mas não é
 * declarado aqui: o robots.txt não tem diretiva para ele, e a convenção do
 * llmstxt.org é o caminho fixo /llms.txt na raiz do host. Quem procura já sabe
 * onde olhar.
 */
export default async function robots(): Promise<MetadataRoute.Robots> {
  const host = (await headers()).get('host')?.split(':')[0].toLowerCase() ?? '';

  const isStore =
    host.endsWith(`.${ROOT_DOMAIN}`) &&
    !RESERVED.has(host.slice(0, -(ROOT_DOMAIN.length + 1)));

  // http em desenvolvimento: *.localhost não tem TLS, e uma URL https aqui
  // aponta para um endereço que não responde.
  const scheme = ROOT_DOMAIN === 'localhost' ? 'http' : 'https';
  const base = `${scheme}://${(await headers()).get('host') ?? ROOT_DOMAIN}`;

  return {
    rules: {
      userAgent: '*',
      allow: '/',
      /*
       * /api/revalidate é o webhook de invalidação, não conteúdo. Rastreá-lo
       * não vaza dado (ele exige segredo), mas gasta orçamento de crawl numa
       * rota que nunca deveria aparecer em resultado de busca.
       */
      disallow: ['/api/'],
    },
    sitemap: `${base}/sitemap.xml`,
    // Só a loja declara host canônico. Na landing isso é desnecessário, e um
    // host errado aqui atrapalharia mais do que ajuda.
    ...(isStore ? { host: base } : {}),
  };
}
