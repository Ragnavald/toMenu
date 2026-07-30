import type { Menu } from './types';

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';

/**
 * Busca o cardápio no servidor (RSC).
 *
 * `revalidate: 60` alinha o ISR do Next com o `s-maxage=60` que a API envia,
 * de modo que as duas camadas expiram juntas em vez de brigar. O tenant vai no
 * header porque em desenvolvimento não há wildcard DNS; em produção o próprio
 * Host resolve e o header deixa de ser necessário.
 */
export async function fetchMenu(tenantSlug: string): Promise<Menu | null> {
  try {
    const response = await fetch(`${API_URL}/api/menu`, {
      headers: { 'X-Tenant': tenantSlug, Accept: 'application/json' },
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

