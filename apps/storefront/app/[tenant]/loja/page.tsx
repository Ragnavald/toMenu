import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { fetchMenuResult } from '@/lib/api';
import { geocodeAddress } from '@/lib/geocode';
import { canonicalStoreUrl } from '@/lib/store-url';
import { breadcrumbSchema, restaurantSchema } from '@/lib/structured-data';
import { JsonLd } from '@/components/json-ld';
import { StoreSuspended } from '@/components/store-suspended';
import { StoreProfile } from '@/components/store-profile';

/**
 * Mesmo racional do cardápio: a informação muda pouco e a API invalida por tag
 * no instante da edição. O TTL longo é a rede de proteção do webhook perdido.
 *
 * O selo aberto/fechado é a exceção — ele vem do payload cacheado e viraria
 * mentira ao longo do dia. Por isso o componente que o exibe é client e
 * reavalia pelo horário; ver `store-status.tsx`.
 */
export const revalidate = 3600;

type Props = { params: Promise<{ tenant: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { tenant } = await params;
  const result = await fetchMenuResult(tenant);

  if (result.status !== 'ok') {
    return { title: 'Loja não encontrada', robots: { index: false } };
  }

  const { name, address } = result.menu.tenant;

  const title = `${name} — Endereço, horário e contato`;
  const description = address
    ? `Endereço, horário de funcionamento e telefone de ${name}: ${address}.`
    : `Endereço, horário de funcionamento e telefone de ${name}.`;

  return {
    title,
    description,
    alternates: { canonical: canonicalStoreUrl(tenant, 'loja') },
    openGraph: {
      title,
      description,
      url: canonicalStoreUrl(tenant, 'loja'),
      siteName: name,
      locale: 'pt_BR',
      type: 'website',
    },
  };
}

export default async function StoreProfilePage({ params }: Props) {
  const { tenant } = await params;
  const result = await fetchMenuResult(tenant);

  if (result.status === 'suspended') return <StoreSuspended />;

  if (result.status === 'missing') notFound();

  const { menu } = result;

  // Só para os dados estruturados: o `geo` do schema.org ajuda a busca local a
  // situar a loja. O mapa desenhado não depende disto — o traçado dele vem
  // pronto da API, em `tenant.streetMap`.
  //
  // Falha silenciosa: sem coordenadas o schema sai sem `geo`, e geocodificar
  // aqui (e não no cardápio) mantém o custo fora da página mais acessada.
  const coordinates = await geocodeAddress(menu.tenant.address);

  const storeUrl = canonicalStoreUrl(tenant);

  return (
    <>
      {/*
        Esta página é a que tem endereço e horário em texto, então é a
        candidata natural a responder "onde fica" e "está aberto agora". As
        coordenadas entram aqui — e não no cardápio — porque é aqui que o
        endereço é publicado.
      */}
      <JsonLd
        data={restaurantSchema({
          tenant: menu.tenant,
          categories: menu.categories,
          url: storeUrl,
          coordinates,
        })}
      />

      <JsonLd
        data={breadcrumbSchema([
          { name: menu.tenant.name, url: storeUrl },
          { name: 'Sobre a loja', url: canonicalStoreUrl(tenant, 'loja') },
        ])}
      />

      <StoreProfile tenant={menu.tenant} />
    </>
  );
}
