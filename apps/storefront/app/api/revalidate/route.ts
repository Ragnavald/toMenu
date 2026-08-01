import { revalidateTag } from 'next/cache';
import type { NextRequest } from 'next/server';
import { menuCacheTag } from '@/lib/api';

/**
 * Invalidação sob demanda do cardápio, chamada pela API quando algo muda.
 *
 * Antes o cardápio dependia só de TTL: 60s de ISR aqui e 60s de s-maxage no
 * Cloudflare, camadas independentes que na pior sobreposição somavam e faziam
 * uma edição levar até ~2min para chegar ao cliente. Com esta rota o Laravel
 * avisa no instante da edição e o tempo cai para segundos.
 *
 * `{ expire: 0 }` é o que os docs do Next 16 prescrevem para webhooks de
 * sistemas externos: expira a entrada imediatamente, em vez do
 * stale-while-revalidate de `profile: 'max'`, que continuaria servindo o
 * cardápio antigo enquanto revalida — exatamente o que queremos evitar aqui.
 * `updateTag`, a alternativa recomendada, só existe dentro de Server Actions e
 * não pode ser chamada de um Route Handler.
 */
export async function POST(request: NextRequest) {
  const secret = process.env.REVALIDATE_SECRET;

  // Sem segredo configurado a rota fica fechada. Deixá-la aberta permitiria a
  // qualquer um forçar re-render de todas as lojas — um DoS barato contra a
  // origem, já que cada chamada custa um miss de cache.
  if (!secret) {
    return Response.json(
      { revalidated: false, message: 'Revalidação não configurada.' },
      { status: 503 },
    );
  }

  if (request.headers.get('x-revalidate-secret') !== secret) {
    return Response.json(
      { revalidated: false, message: 'Não autorizado.' },
      { status: 401 },
    );
  }

  const tenant = new URL(request.url).searchParams.get('tenant');

  if (!tenant) {
    return Response.json(
      { revalidated: false, message: 'Parâmetro tenant é obrigatório.' },
      { status: 400 },
    );
  }

  revalidateTag(menuCacheTag(tenant), { expire: 0 });

  return Response.json({ revalidated: true, tenant, now: Date.now() });
}
