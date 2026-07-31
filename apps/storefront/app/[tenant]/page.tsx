import { notFound } from 'next/navigation';
import { fetchMenu } from '@/lib/api';
import { StoreHeader } from '@/components/store-header';
import { MenuBrowser } from '@/components/menu-browser';
import { CartBar } from '@/components/cart-bar';

/**
 * O cardápio muda com pouca frequência, mas é a página mais acessada: cada
 * visitante do QR code cai aqui. Com `force-dynamic` todo acesso atravessava
 * até a origem em NYC — e o `s-maxage=60` que a API envia era ignorado, porque
 * o Next marcava a resposta como não-cacheável antes de chegar ao Cloudflare.
 *
 * 60s alinha as três camadas (ISR do Next, s-maxage da API, Edge TTL do
 * Cloudflare): um preço editado no admin aparece em no máximo um minuto.
 */
export const revalidate = 60;

type Props = { params: Promise<{ tenant: string }> };

export default async function StorePage({ params }: Props) {
  const { tenant } = await params;
  const menu = await fetchMenu(tenant);

  if (!menu) notFound();

  return (
    <>
      {/* Header e cardápio são Server Components: o HTML já chega pronto,
          indexável e com LCP baixo. Só carrinho e modal são client. */}
      <StoreHeader tenant={menu.tenant} />
      <MenuBrowser categories={menu.categories} layout={menu.theme.layout} />
      <CartBar tenant={menu.tenant} tenantSlug={tenant} />
    </>
  );
}
