const TOKEN_KEY = 'tomenu:admin:token';
const TENANT_KEY = 'tomenu:admin:tenant';

/**
 * Origem da API.
 *
 * Vazia em desenvolvimento: o proxy do vite.config.ts encaminha `/api` para o
 * backend local, mantendo tudo na mesma origem e dispensando CORS.
 *
 * Em produção o admin é build estático servido de outro domínio
 * (app.to-menu.com), onde `/api` apontaria para o próprio site e o login
 * falharia — por isso a URL absoluta precisa entrar no build via
 * VITE_API_URL. Como toda VITE_*, ela é inlined no bundle: mudar exige
 * rebuild, não basta reconfigurar o host.
 *
 * Sem barra no fim, para não gerar `//api` ao concatenar.
 */
export const API_BASE = (import.meta.env.VITE_API_URL ?? '').replace(/\/$/, '');

export type Session = {
  token: string;
  tenantSlug: string;
  userName: string;
  tenantName: string;
  /**
   * Papel do usuário. Opcional porque sessões salvas antes deste campo existir
   * continuam no localStorage — tratar como não-owner é o padrão seguro, e o
   * backend rejeita de qualquer forma.
   */
  role?: string;
};

export function loadSession(): Session | null {
  try {
    const raw = localStorage.getItem(TOKEN_KEY);
    return raw ? (JSON.parse(raw) as Session) : null;
  } catch {
    return null;
  }
}

/** Só o dono pode excluir a loja; o backend confirma isso de qualquer forma. */
export function isOwner(): boolean {
  return loadSession()?.role === 'owner';
}

export function saveSession(session: Session): void {
  localStorage.setItem(TOKEN_KEY, JSON.stringify(session));
  localStorage.setItem(TENANT_KEY, session.tenantSlug);
}

export function clearSession(): void {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(TENANT_KEY);
}

/**
 * Campos declarados e atribuídos explicitamente: o tsconfig do Vite ativa
 * `erasableSyntaxOnly`, que proíbe parameter properties no construtor.
 */
export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;

  constructor(
    message: string,
    status: number,
    errors?: Record<string, string[]>,
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }
}

/**
 * Cliente HTTP do Admin.
 *
 * Envia sempre o header X-Tenant junto do bearer token. Os dois são
 * necessários e cumprem papéis distintos: o token diz *quem* é o usuário e o
 * X-Tenant diz *qual loja* ele quer operar. O backend cruza os dois em
 * EnsureUserBelongsToTenant — token válido para outra loja é rejeitado com 403.
 */
export async function apiFetch<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const session = loadSession();

  const isFormData = options.body instanceof FormData;

  const response = await fetch(`${API_BASE}/api${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
      ...(session ? { Authorization: `Bearer ${session.token}` } : {}),
      ...(session ? { 'X-Tenant': session.tenantSlug } : {}),
      ...options.headers,
    },
  });

  if (response.status === 204) return undefined as T;

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    // Token expirado ou revogado: limpa a sessão para forçar novo login em vez
    // de deixar o usuário preso num loop de 401.
    if (response.status === 401) clearSession();

    throw new ApiError(
      data?.message ?? 'Erro ao comunicar com o servidor.',
      response.status,
      data?.errors,
    );
  }

  return data as T;
}

/**
 * Baixa um arquivo protegido por autenticação.
 *
 * Um <a href> comum não serve: o navegador não manda o bearer token nem o
 * X-Tenant numa navegação, e a rota responderia 401. Busca como blob e dispara
 * o download a partir da memória.
 */
export async function apiDownload(path: string, filename: string): Promise<void> {
  const session = loadSession();

  const response = await fetch(`${API_BASE}/api${path}`, {
    headers: {
      ...(session ? { Authorization: `Bearer ${session.token}` } : {}),
      ...(session ? { 'X-Tenant': session.tenantSlug } : {}),
    },
  });

  if (!response.ok) {
    throw new ApiError('Não foi possível baixar o arquivo.', response.status);
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);

  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();

  // Sem revoke o blob fica retido na memória da aba até o reload.
  URL.revokeObjectURL(url);
}

export function formatMoney(cents: number): string {
  return (cents / 100).toLocaleString('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  });
}

/** Converte "45,90" ou "45.90" em 4590 centavos, sem erro de ponto flutuante. */
export function parseMoneyToCents(input: string): number {
  const normalized = input.replace(/[^\d,.-]/g, '').replace(',', '.');
  return Math.round(parseFloat(normalized || '0') * 100);
}
