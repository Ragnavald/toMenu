import { useMutation } from '@tanstack/react-query';
import { ApiError } from '@/lib/api';
import { platformFetch } from '@/lib/platform';

type ImpersonationResponse = {
  token: string;
  expiresAt: string;
  tenant: { slug: string; name: string };
  user: { name: string; email: string; role: string };
};

/**
 * Origem do painel do LOJISTA, que é para onde a impersonação leva.
 *
 * Não pode ser um caminho relativo no host atual: o painel da plataforma é
 * servido de `admin.{domínio}`, e abrir `/#token=...` ali cairia de volta neste
 * mesmo painel, porque o `isPlatformHost()` do App.tsx escolhe pelo host.
 *
 * Em produção o destino é `app.{domínio}`, o irmão do host corrente. Em
 * desenvolvimento não existe `app.localhost:5173` — o painel do lojista é o
 * próprio `localhost:5173` —, então ali a troca é por `localhost`, e não por
 * `app.`. Sem esta distinção a aba aberta pela impersonação morreria em
 * ERR_CONNECTION_REFUSED com o token já emitido.
 */
function storeAdminUrl(): string {
  const configured = import.meta.env.VITE_STORE_ADMIN_URL;

  if (configured !== undefined) return configured.replace(/\/$/, '');

  const { protocol, host } = window.location;

  // `admin.localhost:5173` -> `localhost:5173`; `admin.to-menu.com` -> `app.to-menu.com`.
  const target = host.startsWith('admin.localhost')
    ? host.slice('admin.'.length)
    : host.replace(/^admin\./, 'app.');

  return `${protocol}//${target}`;
}

/**
 * Abre o painel da loja autenticado como o dono dela.
 *
 * O token vai no FRAGMENTO da URL (`#`), nunca na query string: o fragmento
 * não é enviado ao servidor nem gravado em log de acesso, e o painel o consome
 * e limpa da barra de endereço. É o mesmo mecanismo do handoff do cadastro
 * (`consumeHandoff` em App.tsx), reaproveitado aqui de propósito — token de
 * acesso em `?query=` acabaria no histórico e no Referer.
 *
 * `_blank` para que a sessão da plataforma continue aberta na aba original: o
 * staff quase sempre volta para ela ao terminar o atendimento.
 */
export function ImpersonateButton({
  slug,
  disabled,
  onError,
}: {
  slug: string;
  disabled?: boolean;
  onError: (message: string) => void;
}) {
  const impersonate = useMutation({
    mutationFn: () =>
      platformFetch<ImpersonationResponse>(`/stores/${slug}/impersonate`, {
        method: 'POST',
        body: JSON.stringify({}),
      }),
    onSuccess: (data) => {
      const handoff = new URLSearchParams({
        token: data.token,
        tenant: data.tenant.slug,
        name: data.tenant.name,
        user: data.user.name,
        role: data.user.role,
      });

      window.open(`${storeAdminUrl()}/#${handoff.toString()}`, '_blank', 'noopener');
    },
    onError: (error) =>
      onError(
        error instanceof ApiError
          ? error.message
          : 'Não foi possível acessar o painel da loja.',
      ),
  });

  return (
    <div>
      <button
        type="button"
        disabled={disabled || impersonate.isPending}
        onClick={() => impersonate.mutate()}
        className="w-full rounded-lg bg-accent px-3 py-2 text-xs font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
      >
        {impersonate.isPending ? 'Abrindo…' : 'Acessar como admin'}
      </button>

      <p className="mt-1.5 text-[11px] leading-relaxed text-muted">
        {disabled
          ? 'Disponível apenas para lojas ativas.'
          : 'Abre o painel da loja em outra aba. O acesso expira em 30 minutos e fica registrado no histórico.'}
      </p>
    </div>
  );
}
