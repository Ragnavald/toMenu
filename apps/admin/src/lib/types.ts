export type Product = {
  id: number;
  category_id: number;
  name: string;
  slug: string;
  description: string | null;
  price_cents: number;
  promo_price_cents: number | null;
  image_url: string | null;
  is_available: boolean;
  position: number;
  category?: { id: number; name: string };
};

export type Category = {
  id: number;
  name: string;
  slug: string;
  position: number;
  is_active: boolean;
  /**
   * Categoria-insumo: abastece grupos compostos e não vira seção do cardápio.
   * É o que permite ter "Sabores de Pizza" sem vendê-los soltos.
   */
  is_option_only: boolean;
  products_count?: number;
};

/** 'list' = opções digitadas aqui; 'category' = produtos de uma categoria. */
export type ModifierSource = 'list' | 'category';

/** Como somar quando o cliente escolhe mais de uma opção do mesmo grupo. */
export type PricingRule = 'sum' | 'highest' | 'average';

export type Modifier = {
  id: number;
  name: string;
  price_delta_cents: number;
  is_available: boolean;
  position: number;
};

/** Produto ofertado como opção, com o preço próprio daquele grupo. */
export type OptionProduct = {
  id: number;
  name: string;
  price_cents: number;
  pivot: { price_cents: number | null; position: number };
};

export type ModifierGroup = {
  id: number;
  name: string;
  min_select: number;
  max_select: number;
  is_required: boolean;
  source: ModifierSource;
  source_category_id: number | null;
  pricing_rule: PricingRule;
  modifiers: Modifier[];
  option_products: OptionProduct[];
  /** Produtos que usam este grupo — só o id, para marcar os checkboxes. */
  products?: { id: number }[];
  products_count?: number;
};

export type OrderItem = {
  id: number;
  product_name: string;
  quantity: number;
  total_cents: number;
};

export type Fulfillment = 'delivery' | 'pickup' | 'dine_in';

export type Order = {
  id: number;
  number: number;
  status: string;
  fulfillment: Fulfillment;
  payment_method: string;
  payment_status: string;
  subtotal_cents: number;
  delivery_fee_cents: number;
  total_cents: number;
  notes: string | null;
  placed_at: string | null;
  /**
   * Quando o pedido saiu do painel. Null = ainda no movimento corrente.
   *
   * Separado de `status` de propósito: um pedido arquivado continua
   * `delivered`, e é assim que o financeiro segue contando a venda.
   */
  archived_at: string | null;
  items: OrderItem[];
  customer: { id: number; name: string; phone: string } | null;
};

export type Paginated<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};

/* ------------------------------------------------------------- financeiro */

export type FinanceSummary = {
  revenueCents: number;
  subtotalCents: number;
  deliveryCents: number;
  ordersCount: number;
  averageTicketCents: number;
};

/** Ponto da série temporal. `day` ou `month` conforme a granularidade. */
export type FinancePoint = {
  day?: string;
  month?: string;
  label: string;
  revenueCents: number;
  ordersCount: number;
};

export type FinanceOverview = {
  range: { from: string; to: string; granularity: 'day' | 'month' };
  summary: FinanceSummary;
  series: FinancePoint[];
  monthly: FinancePoint[];
  byPaymentMethod: {
    method: string;
    revenueCents: number;
    ordersCount: number;
  }[];
};

export type ThemeTokens = {
  brand: string;
  brandSoft: string;
  surface: string;
  ink: string;
  brandInk?: string;
  font: string;
  radius: string;
  layout: string;
};

export type DeliveryConfig = {
  feeCents: number;
  minOrderCents: number;
  etaMinutes: number;
  freeAboveCents: number | null;
  radiusKm?: number | null;
  acceptsPickup: boolean;
  acceptsDelivery: boolean;
  acceptsDineIn: boolean;
};

export type DaySchedule = {
  enabled: boolean;
  open: string;
  close: string;
};

export type BusinessHours = Record<string, DaySchedule>;

/**
 * Capacidades contratadas.
 *
 * O painel monta navegação, wizard e telas a partir daqui, e nunca a partir do
 * slug do plano: um plano novo entra pelas mesmas flags, sem varrer o código
 * atrás de comparações de string.
 */
export type PlanInfo = {
  slug: string | null;
  name: string | null;
  priceCents: number | null;
  maxProducts: number | null;
  allowsOrders: boolean;
  allowsDelivery: boolean;
  allowsOnlinePayment: boolean;
};

export type Settings = {
  store: {
    name: string;
    slug: string;
    storefrontUrl: string;
    status: string;
    id: number;
    onboardingStep: number;
    onboardingCompleted: boolean;
    acceptsOnlinePayment: boolean;
    trialEndsAt: string | null;
    /** Estado da assinatura no Stripe — `null` enquanto o lojista não assinou. */
    subscriptionStatus: string | null;
  };
  plan: PlanInfo;
  profile: {
    segment: string | null;
    phone: string | null;
    whatsapp: string | null;
    address: string | null;
    description: string | null;
    logoUrl: string | null;
    coverUrl: string | null;
  };
  theme: ThemeTokens;
  delivery: DeliveryConfig;
  businessHours: BusinessHours;
  paymentMethods: string[];
  isOpenOverride: boolean | null;
  options: {
    fonts: string[];
    layouts: string[];
    paymentMethods: string[];
  };
};

export const ORDER_STATUSES = [
  'pending_payment',
  'confirmed',
  'preparing',
  'ready',
  'out_for_delivery',
  'delivered',
  'cancelled',
] as const;

export const STATUS_LABELS: Record<string, string> = {
  pending_payment: 'Aguardando pagamento',
  confirmed: 'Confirmado',
  preparing: 'Em preparo',
  ready: 'Pronto',
  out_for_delivery: 'Saiu para entrega',
  delivered: 'Entregue',
  cancelled: 'Cancelado',
};

/**
 * Como o cliente recebe o pedido. Espelha Order::FULFILLMENTS na API.
 *
 * Na operação isso separa três filas distintas: entrega sai com o motoboy,
 * retirada espera no balcão e consumo no local vai para o salão.
 */
export const FULFILLMENT_LABELS: Record<Fulfillment, string> = {
  delivery: 'Entrega',
  pickup: 'Retirada',
  dine_in: 'Consumo no local',
};

export const PAYMENT_LABELS: Record<string, string> = {
  cash: 'Dinheiro na entrega',
  card_on_delivery: 'Cartão na entrega',
  pix_on_delivery: 'Pix na entrega',
  stripe_card: 'Cartão pelo site',
  stripe_pix: 'Pix pelo site',
};

/**
 * Rótulo do pagamento no contexto de um pedido já feito.
 *
 * Os rótulos fixos acima descrevem a oferta em abstrato — é o que a tela de
 * configuração mostra. Num pedido concreto a modalidade é conhecida, e "na
 * entrega" seria errado para quem come no salão ou busca no balcão.
 */
export function paymentLabelFor(method: string, fulfillment: Fulfillment): string {
  const presencial = fulfillment === 'delivery' ? 'na entrega' : 'na loja';

  const contextual: Record<string, string> = {
    cash: `Dinheiro ${presencial}`,
    card_on_delivery: `Cartão ${presencial}`,
    pix_on_delivery: `Pix ${presencial}`,
  };

  return contextual[method] ?? PAYMENT_LABELS[method] ?? method;
}

export const DAYS: { key: string; label: string }[] = [
  { key: 'mon', label: 'Segunda' },
  { key: 'tue', label: 'Terça' },
  { key: 'wed', label: 'Quarta' },
  { key: 'thu', label: 'Quinta' },
  { key: 'fri', label: 'Sexta' },
  { key: 'sat', label: 'Sábado' },
  { key: 'sun', label: 'Domingo' },
];
