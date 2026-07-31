import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { fetchMenuResult } from '@/lib/api';
import { fontClassFor } from '@/lib/fonts';
import { getContrastInk } from '@/lib/contrast';
import { CartProvider } from '@/components/cart-provider';
import { StoreSuspended } from '@/components/store-suspended';

export const dynamic = 'force-dynamic';
export const revalidate = 0;

type Props = {
  params: Promise<{ tenant: string }>;
  children: React.ReactNode;
};

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { tenant } = await params;
  const result = await fetchMenuResult(tenant);

  if (result.status === 'suspended') {
    // noindex: a loja pode voltar, e não convém que o buscador registre esta
    // página como o conteúdo do endereço da loja.
    return { title: 'Loja indisponível', robots: { index: false } };
  }

  if (result.status === 'missing') return { title: 'Loja não encontrada' };

  const { menu } = result;

  return {
    title: `${menu.tenant.name} — Peça online`,
    description: `Confira o cardápio de ${menu.tenant.name} e peça pelo site.`,
    openGraph: {
      title: menu.tenant.name,
      type: 'website',
      images: menu.tenant.coverUrl ? [menu.tenant.coverUrl] : undefined,
    },
  };
}

export default async function TenantLayout({ params, children }: Props) {
  const { tenant } = await params;
  const result = await fetchMenuResult(tenant);

  /*
   * Loja suspensa é renderizada aqui, e não delegada ao children: sem o
   * cardápio não há tema, e o layout inteiro abaixo depende dele. Envolver a
   * tela neutra na moldura da marca também seria contraditório — a loja está
   * fora do ar, não decorada.
   */
  if (result.status === 'suspended') return <StoreSuspended />;

  if (result.status === 'missing') notFound();

  const { menu } = result;
  const { theme } = menu;
  const brandInk = theme.brandInk ?? getContrastInk(theme.brand);

  const themeCss = `
    [data-tenant] {
      --brand: ${theme.brand};
      --brand-soft: ${theme.brandSoft};
      --brand-ink: ${brandInk};
      --surface: ${theme.surface};
      --ink: ${theme.ink};
      --radius: ${theme.radius};
      --ink-muted: rgb(${theme.ink} / 0.55);
      --ink-subtle: rgb(${theme.ink} / 0.38);
      --hairline: rgb(${theme.ink} / 0.12);
      --elevated: rgb(${theme.surface});
      color-scheme: light;
    }
  `;

  return (
    <div
      data-tenant={theme.layout}
      className={fontClassFor(theme.font)}
      style={{
        colorScheme: 'light',
        backgroundColor: `rgb(${theme.surface})`,
        color: `rgb(${theme.ink})`,
      }}
    >
      <style dangerouslySetInnerHTML={{ __html: themeCss }} />
      <CartProvider tenantSlug={tenant}>{children}</CartProvider>
    </div>
  );
}
