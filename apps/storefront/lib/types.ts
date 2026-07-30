export type ThemeTokens = {
  brand: string;
  brandSoft: string;
  surface: string;
  ink: string;
  font: string;
  radius: string;
  layout: 'classic' | 'grid' | 'compact';
};

export type Modifier = {
  id: number;
  name: string;
  priceDeltaCents: number;
};

export type ModifierGroup = {
  id: number;
  name: string;
  minSelect: number;
  maxSelect: number;
  isRequired: boolean;
  modifiers: Modifier[];
};

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

export type TenantInfo = {
  name: string;
  slug: string;
  logoUrl: string | null;
  coverUrl: string | null;
  acceptsOnlinePayment: boolean;
  paymentMethods: string[];
  deliveryConfig: {
    fee_cents?: number;
    min_order_cents?: number;
    eta_minutes?: number;
  };
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
  modifiers: { id: number; name: string; priceDeltaCents: number }[];
};
