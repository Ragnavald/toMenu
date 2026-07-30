import { notFound } from 'next/navigation';
import { fetchMenu } from '@/lib/api';
import { StoreHeader } from '@/components/store-header';
import { MenuBrowser } from '@/components/menu-browser';
import { CartBar } from '@/components/cart-bar';

export const dynamic = 'force-dynamic';
export const revalidate = 0;

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
