import { notFound } from 'next/navigation';
import { fetchMenuResult } from '@/lib/api';
import { StoreSuspended } from '@/components/store-suspended';
import { StoreHeader } from '@/components/store-header';
import { MenuBrowser } from '@/components/menu-browser';
import { CartBar } from '@/components/cart-bar';

/**
 * O cardápio muda com pouca frequência, mas é a página mais acessada: cada
 * visitante do QR code cai aqui. Com `force-dynamic` todo acesso atravessava
 * até a origem em NYC — e o `s-maxage` que a API envia era ignorado, porque
 * o Next marcava a resposta como não-cacheável antes de chegar ao Cloudflare.
 *
 * O TTL é longo porque a invalidação não depende mais dele: a API chama
 * /api/revalidate com a tag da loja no instante em que o cardápio muda, e a
 * mesma requisição purga o Cloudflare. O tempo até a alteração aparecer passa
 * a ser de segundos em vez do encadeamento de dois TTLs de 60s, que somavam
 * até ~2min no pior caso. O TTL só sustenta o caso raro do webhook perdido.
 */
export const revalidate = 3600;

type Props = { params: Promise<{ tenant: string }> };

export default async function StorePage({ params }: Props) {
  const { tenant } = await params;
  const result = await fetchMenuResult(tenant);

  // O layout já intercepta a loja suspensa; repetir aqui evita depender dessa
  // ordem de renderização e mantém a page correta se ela for usada isolada.
  if (result.status === 'suspended') return <StoreSuspended />;

  if (result.status === 'missing') notFound();

  const { menu } = result;

  return (
    <>
      {/* Header e cardápio são Server Components: o HTML já chega pronto,
          indexável e com LCP baixo. Só carrinho e modal são client. */}
      <StoreHeader tenant={menu.tenant} />
      <MenuBrowser
        categories={menu.categories}
        layout={menu.theme.layout}
        acceptsOrders={menu.tenant.acceptsOrders}
      />

      {/* Plano somente-cardápio: a barra do carrinho não existe. */}
      {menu.tenant.acceptsOrders && (
        <CartBar tenant={menu.tenant} tenantSlug={tenant} />
      )}
    </>
  );
}
