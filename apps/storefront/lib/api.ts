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

/** Domínio raiz, para reconstruir o Host da loja no fetch server-side. */
const ROOT_DOMAIN = process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost';

/**
 * Identifica o tenant para a API a partir do servidor.
 *
 * `IdentifyTenant` resolve pelo Host, e só aceita X-Tenant quando
 * TENANCY_TRUST_HEADER está ligado — o que vale apenas em desenvolvimento,
 * porque em produção um header escolhido pelo cliente permitiria trocar de
 * loja à vontade. Como o fetch server-side vai para a URL interna, o Host da
 * requisição seria o do container; enviá-lo explicitamente é o que faz a API
 * enxergar `pizzaria.tomenu.app` em vez de `nginx`.
 *
 * X-Tenant continua sendo enviado para que o ambiente de desenvolvimento, onde
 * não há wildcard DNS, siga funcionando; em produção a API o ignora.
 */
function serverTenantHeaders(tenantSlug: string): HeadersInit {
  return {
    Accept: 'application/json',
    Host: `${tenantSlug}.${ROOT_DOMAIN}`,
    'X-Tenant': tenantSlug,
  };
}

/**
 * Busca o cardápio no servidor (RSC).
 *
 * `revalidate: 60` alinha o ISR do Next com o `s-maxage=60` que a API envia,
 * de modo que as duas camadas expiram juntas em vez de brigar.
 */
export async function fetchMenu(tenantSlug: string): Promise<Menu | null> {
  try {
    const response = await fetch(`${INTERNAL_API_URL}/api/menu`, {
      headers: serverTenantHeaders(tenantSlug),
      cache: 'no-store',
    });

    if (!response.ok) return null;

    return (await response.json()) as Menu;
  } catch {
    // A API pode estar fora do ar; a página trata com notFound() em vez de
    // derrubar o render inteiro.
    return null;
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

