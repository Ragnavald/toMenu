import {
  displayPrice,
  WEEKDAYS,
  type BusinessHours,
  type Category,
  type TenantInfo,
} from './types';

/**
 * JSON-LD (schema.org) das páginas da loja.
 *
 * Sem isto o Google enxerga a página como texto solto: sabe que existe, mas não
 * que é um restaurante, onde fica, quando abre nem o que serve. Com o Restaurant
 * marcado, a mesma página passa a poder aparecer com endereço, faixa de preço e
 * horário direto no resultado — que é o que traz o cliente que buscou
 * "pizzaria perto de mim" em vez do que já sabia o nome da loja.
 *
 * O tipo é `Restaurant` (subclasse de LocalBusiness e FoodEstablishment): é o
 * mais específico que descreve todas as lojas da plataforma, e especificidade é
 * o que decide se o rich result aparece.
 */

/** Dia da semana no vocabulário do schema.org. */
const SCHEMA_DAYS: Record<string, string> = {
  sun: 'Sunday',
  mon: 'Monday',
  tue: 'Tuesday',
  wed: 'Wednesday',
  thu: 'Thursday',
  fri: 'Friday',
  sat: 'Saturday',
};

/**
 * Horário no formato OpeningHoursSpecification.
 *
 * Dia desabilitado é omitido, não enviado como fechado: a ausência já significa
 * fechado no vocabulário, e uma faixa 00:00–00:00 seria lida como aberto o dia
 * inteiro — o oposto do pretendido.
 */
function openingHours(hours: BusinessHours) {
  return WEEKDAYS.flatMap((day) => {
    const slot = hours[day];

    if (!slot?.enabled) return [];

    return [
      {
        '@type': 'OpeningHoursSpecification',
        dayOfWeek: `https://schema.org/${SCHEMA_DAYS[day]}`,
        opens: slot.open,
        closes: slot.close,
      },
    ];
  });
}

/**
 * Faixa de preço a partir do cardápio real.
 *
 * O schema aceita tanto "$$" quanto um valor; a faixa em reais é mais útil e
 * não exige adivinhar a convenção de cifrões, que varia por país.
 */
function priceRange(categories: Category[]): string | undefined {
  const prices = categories
    .flatMap((category) => category.products)
    // O mesmo cálculo da vitrine: um formato de pizza custa zero sozinho e o
    // valor real vem do sabor obrigatório. Usar o preço cru aqui descartaria
    // as pizzas por serem zero e anunciaria "R$ 24 - R$ 42" numa pizzaria
    // cujo prato principal custa R$ 84 — faixa errada é pior que faixa
    // nenhuma, porque o cliente decide se clica com base nela.
    .map((product) => displayPrice(product).cents)
    .filter((cents) => cents > 0);

  if (prices.length === 0) return undefined;

  const min = Math.min(...prices) / 100;
  const max = Math.max(...prices) / 100;

  const format = (value: number) =>
    value.toLocaleString('pt-BR', {
      style: 'currency',
      currency: 'BRL',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    });

  return min === max ? format(min) : `${format(min)} - ${format(max)}`;
}

/**
 * O cardápio como schema.org/Menu.
 *
 * Cada seção vira MenuSection e cada item MenuItem com preço. É o que permite
 * ao buscador responder "essa pizzaria tem calabresa?" sem abrir a página.
 *
 * Produtos de categoria-insumo (sabores) não chegam aqui: a API já os omite do
 * payload, então o cardápio estruturado espelha exatamente o que o cliente vê.
 */
function menuSchema(categories: Category[], url: string) {
  if (categories.length === 0) return undefined;

  return {
    '@type': 'Menu',
    url,
    hasMenuSection: categories.map((category) => ({
      '@type': 'MenuSection',
      name: category.name,
      hasMenuItem: category.products.map((product) => {
        // Pizza composta: o preço exibido é o do sabor mais barato ("a partir
        // de"). Publicar o preço cru marcaria a pizza como R$ 0,00.
        const { cents } = displayPrice(product);

        return {
          '@type': 'MenuItem',
          name: product.name,
          ...(product.description ? { description: product.description } : {}),
          ...(product.imageUrl ? { image: product.imageUrl } : {}),
          offers: {
            '@type': 'Offer',
            price: (cents / 100).toFixed(2),
            priceCurrency: 'BRL',
          },
        };
      }),
    })),
  };
}

/**
 * Endereço em PostalAddress.
 *
 * A loja grava o endereço como texto livre, então não há como separar rua,
 * número e bairro com confiança. `streetAddress` recebe a linha inteira: um
 * parser por vírgulas erraria em "Av. Brasil, 100, sala 2" e um endereço errado
 * é pior que um endereço não estruturado.
 */
function postalAddress(address: string | null) {
  if (!address) return undefined;

  return {
    '@type': 'PostalAddress',
    streetAddress: address,
    addressCountry: 'BR',
  };
}

/**
 * JSON-LD do restaurante.
 *
 * `url` é a URL canônica da loja; `menuUrl` aponta para o cardápio. Campos sem
 * dado são omitidos em vez de irem vazios — string vazia é dado inválido para o
 * validador do Google e derruba o rich result inteiro.
 */
export function restaurantSchema({
  tenant,
  categories,
  url,
  coordinates,
}: {
  tenant: TenantInfo;
  categories: Category[];
  url: string;
  coordinates?: { lat: number; lng: number } | null;
}) {
  const hours = openingHours(tenant.businessHours);
  const range = priceRange(categories);
  const address = postalAddress(tenant.address);

  return {
    '@context': 'https://schema.org',
    '@type': 'Restaurant',
    '@id': `${url}#restaurant`,
    name: tenant.name,
    url,
    ...(tenant.description ? { description: tenant.description } : {}),
    ...(tenant.logoUrl ? { image: tenant.logoUrl, logo: tenant.logoUrl } : {}),
    ...(tenant.coverUrl && !tenant.logoUrl ? { image: tenant.coverUrl } : {}),
    ...(tenant.phone ? { telephone: tenant.phone } : {}),
    ...(address ? { address } : {}),
    ...(coordinates
      ? {
          geo: {
            '@type': 'GeoCoordinates',
            latitude: coordinates.lat,
            longitude: coordinates.lng,
          },
        }
      : {}),
    ...(hours.length > 0 ? { openingHoursSpecification: hours } : {}),
    ...(range ? { priceRange: range } : {}),
    // Só anuncia pedido online onde ele existe de verdade: no plano
    // somente-cardápio a página é vitrine, e prometer pedido no resultado de
    // busca levaria o cliente a uma tela que não tem carrinho.
    ...(tenant.acceptsOrders
      ? {
          acceptsReservations: false,
          potentialAction: {
            '@type': 'OrderAction',
            target: {
              '@type': 'EntryPoint',
              urlTemplate: url,
              inLanguage: 'pt-BR',
              actionPlatform: [
                'https://schema.org/DesktopWebPlatform',
                'https://schema.org/MobileWebPlatform',
              ],
            },
          },
        }
      : {}),
    ...(menuSchema(categories, url) ? { hasMenu: menuSchema(categories, url) } : {}),
    servesCuisine: 'Brasileira',
  };
}

/**
 * JSON-LD da plataforma, para a landing.
 *
 * Organization + WebSite. O SearchAction é omitido de propósito: a landing não
 * tem busca de lojas, e declarar uma caixa que não existe é motivo de
 * desqualificação no Search Console.
 */
export function platformSchema(siteUrl: string) {
  return {
    '@context': 'https://schema.org',
    '@graph': [
      {
        '@type': 'Organization',
        '@id': `${siteUrl}#organization`,
        name: 'ToMenu',
        url: siteUrl,
        logo: `${siteUrl}/icon.png`,
        description:
          'Plataforma de cardápio digital e pedidos online para restaurantes, sem comissão por pedido.',
      },
      {
        '@type': 'WebSite',
        '@id': `${siteUrl}#website`,
        url: siteUrl,
        name: 'ToMenu',
        publisher: { '@id': `${siteUrl}#organization` },
        inLanguage: 'pt-BR',
      },
      {
        '@type': 'SoftwareApplication',
        name: 'ToMenu',
        applicationCategory: 'BusinessApplication',
        operatingSystem: 'Web',
        offers: [
          {
            '@type': 'Offer',
            name: 'Cardápio digital',
            price: '29.00',
            priceCurrency: 'BRL',
          },
          {
            '@type': 'Offer',
            name: 'Pro',
            price: '89.00',
            priceCurrency: 'BRL',
          },
        ],
      },
    ],
  };
}

/** Trilha de navegação: ajuda o buscador a exibir "Loja › Sobre". */
export function breadcrumbSchema(items: { name: string; url: string }[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: item.name,
      item: item.url,
    })),
  };
}
