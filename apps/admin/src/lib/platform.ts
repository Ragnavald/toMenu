import { API_BASE, ApiError } from '@/lib/api';

/**
 * Sessão do staff da plataforma.
 *
 * Guardada sob uma chave PRÓPRIA, separada da sessão do lojista
 * (`tomenu:admin:token`). As duas convivem no mesmo bundle e, em
 * desenvolvimento, na mesma origem: reaproveitar a chave faria o login no
 * painel da plataforma derrubar a sessão da loja aberta noutra aba — e, pior,
 * faria o `apiFetch` do lojista mandar o token de staff com um X-Tenant
 * qualquer.
 */
const TOKEN_KEY = 'tomenu:platform:token';

export type PlatformSession = {
  token: string;
  name: string;
  email: string;
};

export function loadPlatformSession(): PlatformSession | null {
  try {
    const raw = localStorage.getItem(TOKEN_KEY);
    return raw ? (JSON.parse(raw) as PlatformSession) : null;
  } catch {
    return null;
  }
}

export function savePlatformSession(session: PlatformSession): void {
  localStorage.setItem(TOKEN_KEY, JSON.stringify(session));
}

export function clearPlatformSession(): void {
  localStorage.removeItem(TOKEN_KEY);
}

/**
 * Cliente HTTP do painel da plataforma.
 *
 * Não envia X-Tenant, ao contrário do `apiFetch` do lojista, e isso é
 * proposital: as rotas de /api/platform não passam pelo identify.tenant. Um
 * X-Tenant aqui não teria efeito, mas mandá-lo sugeriria um escopo de loja que
 * estas rotas justamente não têm.
 */
export async function platformFetch<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const session = loadPlatformSession();

  const response = await fetch(`${API_BASE}/api/platform${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(session ? { Authorization: `Bearer ${session.token}` } : {}),
      ...options.headers,
    },
  });

  if (response.status === 204) return undefined as T;

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    // Token expirado ou revogado: limpa para forçar novo login, em vez de
    // deixar o painel preso num loop de 401.
    if (response.status === 401) clearPlatformSession();

    throw new ApiError(
      data?.message ?? 'Erro ao comunicar com o servidor.',
      response.status,
      data?.errors,
    );
  }

  return data as T;
}

export type StoreStatus = 'trial' | 'active' | 'past_due' | 'suspended' | 'deleted';

export type StoreSummary = {
  id: number;
  name: string;
  slug: string;
  status: StoreStatus;
  plan: string | null;
  storefrontUrl: string;
  ordersCount: number;
  productsCount: number;
  lastOrderAt: string | null;
  createdAt: string | null;
  deletedAt: string | null;
};

export type StoreListResponse = {
  data: StoreSummary[];
  meta: { currentPage: number; lastPage: number; perPage: number; total: number };
  totals: {
    live: number;
    active: number;
    trial: number;
    suspended: number;
    deleted: number;
  };
};

export type AuditEntry = {
  id: number;
  action: 'impersonate' | 'suspend' | 'reactivate' | 'purge';
  actor: string | null;
  context: Record<string, unknown> | null;
  createdAt: string | null;
};

export type StoreDetail = {
  store: StoreSummary & {
    trialEndsAt: string | null;
    onboardingCompleted: boolean;
    onboardingStep: number | null;
    stripeConnected: boolean;
    acceptsOnlinePayment: boolean;
    deletionReason: string | null;
    segment: string | null;
    phone: string | null;
    whatsapp: string | null;
    address: string | null;
    logoUrl: string | null;
  };
  owner: { id: number; name: string; email: string; role: string } | null;
  audit: AuditEntry[];
};

export type PurgePreview = {
  store: { name: string; slug: string };
  willDelete: {
    products: number;
    categories: number;
    orders: number;
    customers: number;
    payments: number;
    users: number;
  };
  stripeConnected: boolean;
  slugBecomesAvailable: boolean;
};

/** Rótulos em português para os estados da loja. */
export const STATUS_LABEL: Record<StoreStatus, string> = {
  trial: 'Em teste',
  active: 'Ativa',
  past_due: 'Pagamento pendente',
  suspended: 'Suspensa',
  deleted: 'Excluída',
};

export function formatDate(value: string | null): string {
  if (!value) return '—';

  // O backend devolve ISO 8601; `lastOrderAt` vem do agregado do Postgres, sem
  // fuso. `replace` normaliza para que o Date do navegador aceite os dois.
  const date = new Date(value.includes('T') ? value : value.replace(' ', 'T'));

  return Number.isNaN(date.getTime())
    ? '—'
    : date.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
      });
}
