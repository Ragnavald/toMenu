import { headers } from 'next/headers';
import { fetchMenuResult } from '@/lib/api';
import { canonicalStoreUrl } from '@/lib/store-url';

const ROOT_DOMAIN = (process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost').toLowerCase();

const RESERVED = new Set(['www', 'app', 'api', 'admin', 'central']);

/**
 * llms.txt por host — o mesmo racional do robots.ts e do sitemap.ts.
 *
 * Um único build serve a landing e todas as lojas, então o conteúdo é decidido
 * pelo Host. Servir o texto da plataforma no subdomínio da pizzaria seria pior
 * que não ter arquivo nenhum: o assistente descreveria o produto ToMenu para
 * quem perguntou pelo cardápio da loja.
 *
 * O arquivo não fica em public/ justamente por isso — lá ele seria estático e
 * igual para todo host. E o proxy precisa deixá-lo passar sem reescrita, senão
 * vira /pizzaria/llms.txt e responde 404 (ver proxy.ts).
 *
 * Formato: https://llmstxt.org — markdown, H1 com o nome, blockquote com o
 * resumo, seções de links em lista.
 */
export async function GET() {
  const requestHeaders = await headers();
  const rawHost = requestHeaders.get('host') ?? ROOT_DOMAIN;
  const host = rawHost.split(':')[0].toLowerCase();

  const sub = host.endsWith(`.${ROOT_DOMAIN}`)
    ? host.slice(0, -(ROOT_DOMAIN.length + 1))
    : null;

  const isStore = Boolean(sub) && !RESERVED.has(sub!) && !sub!.includes('.');

  const body = isStore ? await storeText(sub!) : platformText();

  return new Response(body, {
    headers: {
      // charset explícito: o texto tem acento em quase toda linha, e sem isto
      // o cliente pode assumir latin-1 e exibir "cardápio" corrompido.
      'Content-Type': 'text/plain; charset=utf-8',
      // O conteúdo da loja sai do cardápio, que muda; uma hora casa com o TTL
      // do fetchMenuResult e evita que a CDN sirva um menu antigo por dias.
      'Cache-Control': 'public, max-age=0, s-maxage=3600',
    },
  });
}

/**
 * llms.txt da loja.
 *
 * Descreve uma loja só, e é montado a partir do mesmo cardápio que a página
 * renderiza — nada aqui é escrito à mão sobre o estabelecimento, porque
 * duplicar o dado do lojista num texto fixo garante que ele fique errado assim
 * que ele editar algo.
 */
async function storeText(slug: string): Promise<string> {
  const result = await fetchMenuResult(slug);

  // Loja suspensa ou inexistente não ganha descrição: o endereço pode voltar
  // com outro dono, e um resumo antigo persistido no índice de um assistente é
  // mais difícil de desfazer que uma página 404.
  if (result.status !== 'ok') {
    return [
      '# Loja indisponível',
      '',
      '> Esta loja não está disponível no momento.',
      '',
    ].join('\n');
  }

  const { tenant, categories } = result.menu;
  const { name, description, address, acceptsOrders } = tenant;

  const home = canonicalStoreUrl(slug);
  const profile = canonicalStoreUrl(slug, 'loja');

  const summary = description?.trim()
    ? description.trim()
    : acceptsOrders
      ? `Cardápio digital de ${name}, com pedidos online para entrega ou retirada.`
      : `Cardápio digital de ${name}.`;

  const lines = [
    `# ${name}`,
    '',
    `> ${summary}`,
    '',
  ];

  if (address) {
    lines.push(`Endereço: ${address}`, '');
  }

  lines.push(
    acceptsOrders
      ? 'Os pedidos são feitos pela própria página do cardápio, sem aplicativo e sem cadastro.'
      : 'Esta página exibe o cardápio para consulta; os pedidos não são feitos por aqui.',
    '',
    '## Páginas',
    '',
    `- [Cardápio](${home}): itens, preços e descrições.`,
    `- [A loja](${profile}): endereço, horário de funcionamento e contato.`,
    '',
  );

  // As categorias dizem ao assistente o que a loja vende sem que ele precise
  // buscar a página inteira — é a pergunta mais provável ("tem pizza doce?") e
  // a resposta mais barata de dar.
  const named = categories.map((category) => category.name).filter(Boolean);

  if (named.length > 0) {
    lines.push('## Seções do cardápio', '', ...named.map((n) => `- ${n}`), '');
  }

  return lines.join('\n');
}

/**
 * llms.txt da plataforma (landing).
 *
 * Fala do produto para quem procura um sistema de cardápio, e não de nenhuma
 * loja em particular. As lojas não são listadas aqui pelo mesmo motivo do
 * sitemap: não existe endpoint público que as enumere, e expor o catálogo de
 * clientes é decisão de produto.
 */
function platformText(): string {
  const scheme = ROOT_DOMAIN === 'localhost' ? 'http' : 'https';
  const port = ROOT_DOMAIN === 'localhost' ? ':3000' : '';
  const base = `${scheme}://${ROOT_DOMAIN}${port}`;

  return [
    '# ToMenu',
    '',
    '> Plataforma de cardápio digital para restaurantes, pizzarias e lanchonetes.',
    'Cada estabelecimento recebe um endereço próprio com a sua marca, acessível',
    'por QR Code ou link, sem aplicativo para instalar e sem cadastro para quem pede.',
    '',
    'São dois planos, e a diferença entre eles é uma só: o Cardápio digital exibe o',
    'menu; o Pro também recebe o pedido, com entrega configurável, acompanhamento em',
    'tempo real e relatórios financeiros. Nenhum dos dois cobra comissão por venda.',
    '',
    `Cada loja é publicada num subdomínio próprio (por exemplo pizzaria.${ROOT_DOMAIN}),`,
    'que é o endereço canônico dela e traz o seu próprio cardápio, o seu llms.txt',
    'e o seu sitemap.',
    '',
    '## Páginas',
    '',
    `- [Início](${base}): o que a plataforma faz, planos e cadastro.`,
    '',
    '## Documentos legais',
    '',
    `- [Termos de uso](${base}/termos)`,
    `- [Política de privacidade](${base}/privacidade)`,
    `- [LGPD](${base}/lgpd)`,
    '',
  ].join('\n');
}
