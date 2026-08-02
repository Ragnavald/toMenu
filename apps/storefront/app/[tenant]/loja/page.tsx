import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { fetchMenuResult } from '@/lib/api';
import { geocodeAddress } from '@/lib/geocode';
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

  if (result.status !== 'ok') return { title: 'Loja não encontrada' };

  const { name, address } = result.menu.tenant;

  return {
    title: `Sobre ${name}`,
    description: address
      ? `Endereço, horário de funcionamento e contato de ${name} — ${address}.`
      : `Endereço, horário de funcionamento e contato de ${name}.`,
  };
}

export default async function StoreProfilePage({ params }: Props) {
  const { tenant } = await params;
  const result = await fetchMenuResult(tenant);

  if (result.status === 'suspended') return <StoreSuspended />;

  if (result.status === 'missing') notFound();

  const { menu } = result;

  // Falha silenciosa: sem coordenadas a página troca o mapa por um cartão de
  // endereço. Geocodificar aqui (e não no cardápio) mantém o custo fora da
  // página mais acessada.
  const coordinates = await geocodeAddress(menu.tenant.address);

  return <StoreProfile tenant={menu.tenant} coordinates={coordinates} />;
}
