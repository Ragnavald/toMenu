/**
 * Coordenadas de um endereço, para o mapa da página da loja.
 *
 * O endereço da loja é texto livre digitado no painel — não há lat/lng em
 * `tenant_settings`. O embed do OpenStreetMap, por sua vez, exige uma bbox
 * numérica: não aceita busca por texto como o Google Maps. Daí a geocodificação
 * aqui, no servidor.
 *
 * Roda apenas no render server-side e nunca no caminho do cardápio: se o
 * Nominatim estiver fora do ar ou não reconhecer o endereço, a página cai no
 * cartão de endereço sem mapa, que continua útil.
 */

export type Coordinates = { lat: number; lng: number };

/**
 * Política de uso do Nominatim: exige User-Agent identificável e no máximo
 * 1 req/s. O cache longo abaixo é o que mantém a segunda condição — o endereço
 * de uma loja praticamente não muda, e sem ele cada visita ao cardápio de uma
 * loja movimentada bateria no serviço público.
 */
const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
const USER_AGENT = 'ToMenu/1.0 (+https://to-menu.com)';

/** 30 dias: o endereço de um restaurante é estável. */
const CACHE_TTL_SECONDS = 60 * 60 * 24 * 30;

export async function geocodeAddress(
  address: string | null,
): Promise<Coordinates | null> {
  if (!address?.trim()) return null;

  const query = new URLSearchParams({
    q: address,
    format: 'jsonv2',
    limit: '1',
    // O produto é brasileiro; restringir evita que "Rua Oratório, 344" case
    // com uma via homônima em Portugal.
    countrycodes: 'br',
  });

  try {
    const response = await fetch(`${NOMINATIM_URL}?${query}`, {
      headers: { 'User-Agent': USER_AGENT, Accept: 'application/json' },
      next: { revalidate: CACHE_TTL_SECONDS },
    });

    if (!response.ok) return null;

    const results = (await response.json()) as { lat: string; lon: string }[];
    const [first] = results;

    if (!first) return null;

    const lat = Number(first.lat);
    const lng = Number(first.lon);

    return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
  } catch {
    // Mapa é enfeite: o endereço em texto já resolve a necessidade do cliente.
    return null;
  }
}

/**
 * URL do embed do OpenStreetMap centrado no ponto.
 *
 * O `bbox` define o enquadramento; o delta pequeno equivale a um zoom de
 * quarteirão, suficiente para reconhecer a esquina sem expor o ponto exato
 * como se fosse precisão de GPS.
 */
export function osmEmbedUrl({ lat, lng }: Coordinates): string {
  const delta = 0.004;

  const params = new URLSearchParams({
    bbox: `${lng - delta},${lat - delta},${lng + delta},${lat + delta}`,
    layer: 'mapnik',
    marker: `${lat},${lng}`,
  });

  return `https://www.openstreetmap.org/export/embed.html?${params}`;
}

/**
 * Link para abrir o endereço no app de mapas do aparelho.
 *
 * Usa busca por texto em vez das coordenadas: o app do celular resolve o
 * endereço melhor do que um pin solto, e o link continua funcionando quando a
 * geocodificação falhou.
 */
export function mapsSearchUrl(address: string): string {
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`;
}
