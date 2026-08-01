import type { Menu } from './types';

/** URL usada pelo browser: precisa ser alcançável da internet. */
const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';

/**
 * URL usada pelo render no servidor.
 *
 * Em produção aponta para o nginx pela rede interna do compose, evitando a
 * volta pela internet e pelo túnel a cada render. Sem esta variável cai no
 * valor público, que é o comportamento correto em desenvolvimento.
 */
const INTERNAL_API_URL = process.env.INTERNAL_API_URL ?? API_URL;

/**
 * Identifica o tenant para a API a partir do servidor.
 *
 * `IdentifyTenant` resolve pelo Host (subdomínio) ou pelo path param. No
 * render server-side o Host não serve: a URL é a interna, e o `fetch` do Node
 * descarta um Host definido à mão por ser forbidden header — a API veria
 * "nginx" e devolveria 404. Por isso o slug vai no path.
 *
 * X-Tenant continua sendo enviado para que o ambiente de desenvolvimento, onde
 * não há wildcard DNS, siga funcionando; em produção a API o ignora.
 */
function serverTenantHeaders(tenantSlug: string): HeadersInit {
  return {
    Accept: 'application/json',
    // Só serve ao desenvolvimento, onde TENANCY_TRUST_HEADER está ligado por
    // não haver wildcard DNS. Em produção a API ignora e usa o path.
    'X-Tenant': tenantSlug,
  };
}

/**
 * Tag de cache do cardápio de uma loja.
 *
 * A API chama /api/revalidate com esta tag sempre que algo do cardápio muda,
 * o que derruba a entrada do Data Cache na hora. É por tenant: invalidar a
 * loja A não pode custar um re-render da loja B.
 */
export function menuCacheTag(tenantSlug: string): string {
  return `menu:${tenantSlug}`;
}

/**
 * Busca o cardápio no servidor (RSC).
 *
 * O tenant vai no PATH, não no Host. A rota interna existe exatamente para
 * isto: o `fetch` do Node descarta um header `Host` definido à mão — é um
 * forbidden header — e usaria "nginx", o host da URL interna, fazendo a API
 * devolver 404 para toda loja.
 *
 * O TTL é longo de propósito: quem invalida é a API, por tag, no instante da
 * edição. Antes eram 60s aqui e 60s na CDN, camadas dessincronizadas que
 * somavam e faziam uma alteração levar até ~2min para aparecer. Com a
 * invalidação por evento o tempo cai para segundos, e o TTL passa a ser
 * apenas a rede de proteção para o caso de um webhook se perder.
 *
 * A URL contém o slug, então cada loja tem sua própria entrada no Data Cache
 * — sem isso, o cache de uma loja serviria o cardápio de outra.
 */
export async function fetchMenu(tenantSlug: string): Promise<Menu | null> {
  const result = await fetchMenuResult(tenantSlug);

  return result.status === 'ok' ? result.menu : null;
}

/**
 * Resultado da busca do cardápio, distinguindo loja suspensa de inexistente.
 *
 * A API responde 403 para loja suspensa (IdentifyTenant) e 404 para slug que
 * não existe. Colapsar os dois em `null` faria a loja suspensa cair na página
 * "Loja não encontrada", que é enganosa: o endereço existe e volta ao ar
 * quando a pendência for resolvida.
 */
export type MenuResult =
  | { status: 'ok'; menu: Menu }
  | { status: 'suspended' }
  | { status: 'missing' };

export async function fetchMenuResult(tenantSlug: string): Promise<MenuResult> {
  try {
    const response = await fetch(`${INTERNAL_API_URL}/api/${tenantSlug}/menu`, {
      headers: serverTenantHeaders(tenantSlug),
      next: { revalidate: 3600, tags: [menuCacheTag(tenantSlug)] },
    });

    if (response.status === 403) return { status: 'suspended' };

    if (!response.ok) return { status: 'missing' };

    return { status: 'ok', menu: (await response.json()) as Menu };
  } catch {
    // A API pode estar fora do ar; a página trata com notFound() em vez de
    // derrubar o render inteiro.
    return { status: 'missing' };
  }
}

/*
 * As funções abaixo rodam no browser, onde a página já está em
 * `pizzaria.tomenu.app` — o Host correto vai junto sem esforço e a API resolve
 * o tenant por ele. X-Tenant fica só para o desenvolvimento em localhost, sem
 * wildcard DNS; em produção é ignorado.
 */

export async function submitOrder(tenantSlug: string, payload: unknown) {
  const response = await fetch(`${API_URL}/api/orders`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Tenant': tenantSlug,
    },
    body: JSON.stringify(payload),
  });

  const data = await response.json();

  if (!response.ok) {
    throw new Error(data?.message ?? 'Não foi possível concluir o pedido.');
  }

  return data;
}

export function formatMoney(cents: number): string {
  return (cents / 100).toLocaleString('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  });
}

export async function fetchOrderStatus(tenantSlug: string, orderId: number) {
  try {
    const response = await fetch(`${API_URL}/api/orders/${orderId}`, {
      headers: {
        'X-Tenant': tenantSlug,
        Accept: 'application/json',
      },
      cache: 'no-store',
    });

    if (!response.ok) return null;

    return await response.json();
  } catch {
    return null;
  }
}

