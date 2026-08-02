import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { fetchMenuResult } from '@/lib/api';
import { canonicalStoreUrl } from '@/lib/store-url';
import { fontClassFor } from '@/lib/fonts';
import { getContrastInk } from '@/lib/contrast';
import { CartProvider } from '@/components/cart-provider';
import { FulfillmentProvider } from '@/components/fulfillment-provider';
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

  if (result.status === 'missing') {
    // Sem o noindex, um slug digitado errado e compartilhado vira uma página
    // 404 indexada no lugar do endereço real da loja.
    return { title: 'Loja não encontrada', robots: { index: false } };
  }

  const { menu } = result;
  const { name, description, address, acceptsOrders } = menu.tenant;
  const canonical = canonicalStoreUrl(tenant);

  /*
   * O título carrega a cidade quando ela existe.
   *
   * Busca de restaurante é local — "pizzaria em Pinheiros", não "pizzaria". Sem
   * nenhum termo geográfico no título, a página só é encontrada por quem já
   * sabe o nome da loja, que é o visitante que menos precisa de busca.
   */
  const city = cityFrom(address);
  const title = city ? `${name} — Cardápio e delivery em ${city}` : `${name} — Cardápio online`;

  // A descrição do lojista é sempre melhor que texto gerado: fala da comida.
  // O fallback só entra quando ela não existe.
  const summary =
    description?.trim() ||
    (acceptsOrders
      ? `Veja o cardápio de ${name}${city ? ` em ${city}` : ''} e peça online com entrega ou retirada.`
      : `Veja o cardápio, o horário de funcionamento e o endereço de ${name}${city ? ` em ${city}` : ''}.`);

  const image = menu.tenant.coverUrl ?? menu.tenant.logoUrl ?? undefined;

  return {
    title,
    description: summary,
    // Canônica absoluta: a loja responde em dois endereços (subdomínio e
    // caminho) e sem isto o buscador trata os dois como páginas concorrentes.
    alternates: { canonical },
    openGraph: {
      title,
      description: summary,
      url: canonical,
      siteName: name,
      locale: 'pt_BR',
      type: 'website',
      images: image ? [image] : undefined,
    },
    twitter: {
      card: image ? 'summary_large_image' : 'summary',
      title,
      description: summary,
      images: image ? [image] : undefined,
    },
  };
}

/**
 * Cidade a partir do endereço em texto livre.
 *
 * O lojista digita o endereço numa linha só, no formato que quiser. O padrão
 * que se repete é "rua, número — bairro, Cidade", então o último trecho depois
 * de vírgula ou travessão é a melhor aposta de cidade.
 *
 * Devolve null quando o palpite é ruim (trecho com dígito é CEP ou número, não
 * cidade). Errar aqui coloca lixo no título de toda página da loja, então o
 * critério é conservador: na dúvida, omite.
 */
function cityFrom(address: string | null): string | null {
  if (!address) return null;

  const last = address
    .split(/[—–,]/)
    .map((part) => part.trim())
    .filter(Boolean)
    .pop();

  if (!last || /\d/.test(last) || last.length < 3 || last.length > 40) {
    return null;
  }

  return last;
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
      <CartProvider tenantSlug={tenant}>
        <FulfillmentProvider
          tenantSlug={tenant}
          options={menu.tenant.fulfillments ?? ['delivery', 'pickup']}
        >
          {children}
        </FulfillmentProvider>
      </CartProvider>
    </div>
  );
}
