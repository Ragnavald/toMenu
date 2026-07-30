import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { fetchMenu } from '@/lib/api';
import { fontClassFor } from '@/lib/fonts';
import { CartProvider } from '@/components/cart-provider';

type Props = {
  params: Promise<{ tenant: string }>;
  children: React.ReactNode;
};

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { tenant } = await params;
  const menu = await fetchMenu(tenant);

  if (!menu) return { title: 'Loja não encontrada' };

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
  const menu = await fetchMenu(tenant);

  if (!menu) notFound();

  const { theme } = menu;

  /*
   * Tema aplicado no HTML enviado pelo servidor.
   *
   * Injetar aqui — e não em um useEffect — elimina o flash de tema padrão
   * antes da hidratação: o primeiro paint já sai com as cores da loja.
   *
   * Os valores vêm do ThemeSanitizer no backend, que só aceita formatos
   * fechados ("R G B", enum de fontes, radius limitado). Essa validação é o
   * que torna a interpolação abaixo segura: sem ela, um tenant poderia
   * escapar do bloco e injetar CSS arbitrário contra todos os visitantes.
   */
  const themeCss = `
    [data-tenant="${theme.layout}"] {
      --brand: ${theme.brand};
      --brand-soft: ${theme.brandSoft};
      --surface: ${theme.surface};
      --ink: ${theme.ink};
      --radius: ${theme.radius};
    }
  `;

  return (
    // A fonte entra por className (next/font) e as cores pelo bloco <style>:
    // só o className faz o @font-face ser emitido e o arquivo pré-carregado.
    <div data-tenant={theme.layout} className={fontClassFor(theme.font)}>
      <style dangerouslySetInnerHTML={{ __html: themeCss }} />
      <CartProvider tenantSlug={tenant}>{children}</CartProvider>
    </div>
  );
}
