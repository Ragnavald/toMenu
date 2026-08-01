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
  products_count?: number;
};

export type OrderItem = {
  id: number;
  product_name: string;
  quantity: number;
  total_cents: number;
};

export type Order = {
  id: number;
  number: number;
  status: string;
  fulfillment: 'delivery' | 'pickup';
  payment_method: string;
  payment_status: string;
  subtotal_cents: number;
  delivery_fee_cents: number;
  total_cents: number;
  notes: string | null;
  placed_at: string | null;
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

export type ReportExport = {
  id: number;
  status: 'queued' | 'processing' | 'done' | 'failed' | 'expired';
  from: string;
  to: string;
  search: string | null;
  rowCount: number | null;
  fileSize: number | null;
  error: string | null;
  createdAt: string | null;
  finishedAt: string | null;
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
  merchantPhone: string | null;
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
    onboardingStep: number;
    onboardingCompleted: boolean;
    acceptsOnlinePayment: boolean;
    trialEndsAt: string | null;
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

export const PAYMENT_LABELS: Record<string, string> = {
  cash: 'Dinheiro na entrega',
  card_on_delivery: 'Cartão na entrega',
  pix_on_delivery: 'Pix na entrega',
  stripe_card: 'Cartão pelo site',
  stripe_pix: 'Pix pelo site',
};

export const DAYS: { key: string; label: string }[] = [
  { key: 'mon', label: 'Segunda' },
  { key: 'tue', label: 'Terça' },
  { key: 'wed', label: 'Quarta' },
  { key: 'thu', label: 'Quinta' },
  { key: 'fri', label: 'Sexta' },
  { key: 'sat', label: 'Sábado' },
  { key: 'sun', label: 'Domingo' },
];
