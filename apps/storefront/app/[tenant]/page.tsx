import { notFound } from 'next/navigation';
import { fetchMenuResult } from '@/lib/api';
import { canonicalStoreUrl } from '@/lib/store-url';
import { restaurantSchema } from '@/lib/structured-data';
import { JsonLd } from '@/components/json-ld';
import { StoreSuspended } from '@/components/store-suspended';
import { StoreHeader } from '@/components/store-header';
import { MenuBrowser } from '@/components/menu-browser';
import { CartBar } from '@/components/cart-bar';
import { FulfillmentBar } from '@/components/fulfillment-bar';
import { PoweredBy } from '@/components/powered-by';

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
      {/* Diz ao buscador que esta página é um restaurante — com endereço,
          horário, faixa de preço e o cardápio inteiro. É o que habilita o
          resultado rico; sem isso a página é só texto para o crawler. */}
      <JsonLd
        data={restaurantSchema({
          tenant: menu.tenant,
          categories: menu.categories,
          url: canonicalStoreUrl(tenant),
        })}
      />

      {/* Header e cardápio são Server Components: o HTML já chega pronto,
          indexável e com LCP baixo. Só carrinho e modal são client. */}
      <StoreHeader tenant={menu.tenant} />

      {/* Vitrine não recebe pedido, então não há modalidade a escolher. */}
      {menu.tenant.acceptsOrders && <FulfillmentBar tenant={menu.tenant} />}

      <MenuBrowser
        categories={menu.categories}
        layout={menu.theme.layout}
        acceptsOrders={menu.tenant.acceptsOrders}
      />

      {/* Assinatura da plataforma, ao final do conteúdo. Fica antes da CartBar
          no DOM porque a barra é `fixed` e sai do fluxo — a ordem aqui é a que
          o leitor de tela e o buscador percorrem. */}
      <PoweredBy surface={menu.theme.surface} />

      {/* Plano somente-cardápio: a barra do carrinho não existe. */}
      {menu.tenant.acceptsOrders && (
        <CartBar tenant={menu.tenant} tenantSlug={tenant} />
      )}
    </>
  );
}
