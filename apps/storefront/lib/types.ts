export type ThemeTokens = {
  brand: string;
  brandSoft: string;
  surface: string;
  ink: string;
  brandInk?: string;
  font: string;
  radius: string;
  layout: 'classic' | 'grid' | 'compact';
};

export type Modifier = {
  id: number;
  name: string;
  /**
   * Em grupo de lista é um delta sobre o preço do produto (pode ser negativo).
   * Em grupo composto é o preço cheio da opção — quem decide como somar é a
   * `pricingRule` do grupo, nunca este campo sozinho.
   */
  priceDeltaCents: number;
  /** Só vem preenchido em grupo composto, onde a opção é um produto. */
  imageUrl?: string | null;
  description?: string | null;
};

/**
 * De onde saem as opções do grupo.
 *
 * 'list' — cadastradas no grupo (borda, adicional).
 * 'category' — produtos de uma categoria (sabores de pizza), com foto e
 * descrição próprias. Muda a apresentação, não só o dado.
 */
export type ModifierSource = 'list' | 'category';

/** Como somar quando o cliente escolhe mais de uma opção do mesmo grupo. */
export type PricingRule = 'sum' | 'highest' | 'average';

export type ModifierGroup = {
  id: number;
  name: string;
  minSelect: number;
  maxSelect: number;
  isRequired: boolean;
  source: ModifierSource;
  pricingRule: PricingRule;
  modifiers: Modifier[];
};

/**
 * Quanto o grupo acrescenta ao item, dadas as opções escolhidas.
 *
 * Espelha ModifierGroup::applyPricing da API. A conta é refeita no servidor a
 * cada pedido — isto aqui existe só para o preço exibido não mentir enquanto o
 * cliente monta a pizza.
 */
export function priceGroup(group: ModifierGroup, chosen: Modifier[]): number {
  if (chosen.length === 0) return 0;

  const cents = chosen.map((modifier) => modifier.priceDeltaCents);

  switch (group.pricingRule) {
    case 'highest':
      return Math.max(...cents);
    case 'average':
      return Math.round(cents.reduce((sum, c) => sum + c, 0) / cents.length);
    default:
      return cents.reduce((sum, c) => sum + c, 0);
  }
}

export type Product = {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  priceCents: number;
  promoPriceCents: number | null;
  imageUrl: string | null;
  modifierGroups: ModifierGroup[];
};

export type Category = {
  id: number;
  name: string;
  slug: string;
  products: Product[];
};

/**
 * Preço de exibição do produto na vitrine.
 *
 * Um formato de pizza custa zero sozinho: o valor vem do sabor escolhido. O
 * cartão do cardápio precisa então somar o sabor mais barato de cada grupo
 * composto obrigatório e anunciar "a partir de" — senão a Pizza Grande aparece
 * na lista por R$ 0,00.
 *
 * `from` diz ao chamador se o rótulo muda; o preço em si nunca vem nulo.
 */
export function displayPrice(product: Product): {
  cents: number;
  from: boolean;
} {
  const base = product.promoPriceCents ?? product.priceCents;

  const required = product.modifierGroups.filter(
    (group) =>
      group.source === 'category' &&
      group.minSelect > 0 &&
      // Sem opção disponível, Math.min() devolveria Infinity e o cartão
      // exibiria NaN.
      group.modifiers.length > 0,
  );

  if (required.length === 0) return { cents: base, from: false };

  const cents = required.reduce(
    (total, group) =>
      total +
      Math.min(...group.modifiers.map((modifier) => modifier.priceDeltaCents)),
    base,
  );

  return { cents, from: true };
}

/** Como o cliente recebe o pedido. Espelha Order::FULFILLMENTS na API. */
export type Fulfillment = 'delivery' | 'pickup' | 'dine_in';

/** Imperativo: usado onde o cliente escolhe o que quer ("Entregar"). */
export const FULFILLMENT_LABELS: Record<Fulfillment, string> = {
  delivery: 'Entregar',
  pickup: 'Retirar',
  dine_in: 'Consumir no local',
};

/** Substantivo: usado onde já se descreve um pedido feito ("Entrega"). */
export const FULFILLMENT_NOUNS: Record<Fulfillment, string> = {
  delivery: 'Entrega',
  pickup: 'Retirada',
  dine_in: 'Consumo no local',
};

/** Só a entrega leva endereço do cliente e taxa; as outras são na loja. */
export function isDelivery(fulfillment: Fulfillment): boolean {
  return fulfillment === 'delivery';
}

/** Chaves de dia como a API as grava em business_hours. */
export const WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as const;

export type Weekday = (typeof WEEKDAYS)[number];

export const WEEKDAY_LABELS: Record<Weekday, string> = {
  sun: 'Domingo',
  mon: 'Segunda-feira',
  tue: 'Terça-feira',
  wed: 'Quarta-feira',
  thu: 'Quinta-feira',
  fri: 'Sexta-feira',
  sat: 'Sábado',
};

export const WEEKDAY_SHORT: Record<Weekday, string> = {
  sun: 'Dom',
  mon: 'Seg',
  tue: 'Ter',
  wed: 'Qua',
  thu: 'Qui',
  fri: 'Sex',
  sat: 'Sáb',
};

export type DaySchedule = {
  enabled: boolean;
  open: string;
  close: string;
};

/**
 * Horário de funcionamento, por dia da semana.
 *
 * Parcial de propósito: a loja que nunca abriu a tela de horários não tem as
 * chaves salvas, e a API repassa o JSON como está.
 */
export type BusinessHours = Partial<Record<Weekday, DaySchedule>>;

/**
 * Formas de pagamento, sem amarrar à modalidade.
 *
 * O checkout usa rótulos próprios porque lá a modalidade já é conhecida e
 * "na entrega" vira mentira para quem vai comer no salão. Aqui a listagem é
 * institucional — a loja aceita estes meios, em qualquer modalidade — então o
 * rótulo tem que ser neutro. As chaves são as mesmas da API.
 */
export const PAYMENT_LABELS: Record<string, string> = {
  cash: 'Dinheiro',
  card_on_delivery: 'Cartão presencial',
  pix_on_delivery: 'Pix presencial',
  stripe_card: 'Cartão pelo site',
  stripe_pix: 'Pix pelo site',
};

export type TenantInfo = {
  name: string;
  slug: string;
  logoUrl: string | null;
  coverUrl: string | null;
  /**
   * A loja recebe pedidos pelo site?
   *
   * `false` no plano somente-cardápio: a página vira vitrine — sem carrinho,
   * sem checkout, sem acompanhamento de pedido. A API recusa o POST de
   * qualquer forma, então isto é o que evita oferecer o que não funciona.
   */
  acceptsOrders: boolean;
  acceptsOnlinePayment: boolean;
  description: string | null;
  phone: string | null;
  whatsapp: string | null;
  address: string | null;
  paymentMethods: string[];
  deliveryConfig: {
    fee_cents?: number;
    min_order_cents?: number;
    eta_minutes?: number;
  };
  /**
   * Modalidades que a loja oferta, na ordem de exibição.
   *
   * Vem normalizada da API: uma loja que nunca abriu a tela de entrega não tem
   * as flags salvas, e reproduzir os defaults do backend aqui daria divergência
   * entre o que o cardápio oferece e o que o checkout aceita.
   */
  fulfillments: Fulfillment[];
  /**
   * Horário de funcionamento declarado pela loja.
   *
   * Serve para exibição. Quem decide se a loja está aberta agora é `isOpen`.
   */
  businessHours: BusinessHours;
  /**
   * A loja está aberta neste momento?
   *
   * Calculado na API: o relógio do visitante não é confiável, e o override
   * manual ("fechar agora") não é dedutível a partir dos horários.
   */
  isOpen: boolean;
};

export type Menu = {
  tenant: TenantInfo;
  theme: ThemeTokens;
  categories: Category[];
  version: string;
};

export type CartLine = {
  /** Identidade da linha: mesmo produto com modificadores distintos são linhas distintas. */
  key: string;
  productId: number;
  name: string;
  unitPriceCents: number;
  quantity: number;
  /**
   * `groupName` acompanha cada opção para que o carrinho consiga escrever
   * "Sabores: Margherita, Diavola · Borda: Catupiry". Sem ele a linha vira uma
   * enumeração sem hierarquia, e no meio a meio o cliente não distingue o que
   * é metade do que é adicional.
   */
  modifiers: {
    id: number;
    name: string;
    priceDeltaCents: number;
    groupName?: string;
  }[];
};
