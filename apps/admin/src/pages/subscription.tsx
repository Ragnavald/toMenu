import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch, formatMoney, isOwner } from '@/lib/api';
import { PageHeader, Section } from '@/components/ui';

type Coupon = {
  /** O código como o Stripe o guarda, não como foi digitado. */
  code: string;
  description: string;
  /** Mensalidade já com o desconto; null se o plano não tem preço. */
  priceCents: number | null;
};

type Billing = {
  plan: { slug: string | null; name: string | null; priceCents: number | null };
  status: string | null;
  subscribed: boolean;
  active: boolean;
  trialEndsAt: string | null;
  trialDaysLeft: number | null;
  trialExpired: boolean;
  currentPeriodEndsAt: string | null;
  mode: 'test' | 'live';
  available: boolean;
};

function formatDate(iso: string | null): string {
  if (!iso) return '—';

  return new Date(iso).toLocaleDateString('pt-BR', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  });
}

/**
 * Assinatura da loja na plataforma (a mensalidade do ToMenu).
 *
 * O cartão é coletado pelo Checkout hospedado do Stripe: nenhum dado de cartão
 * passa por aqui. Depois de assinar, tudo — trocar cartão, ver faturas,
 * cancelar — acontece no portal do Stripe, que já entrega essas telas prontas
 * (inclusive as faturas em PDF, que o lojista precisa para a contabilidade).
 */
export function SubscriptionPage() {
  const queryClient = useQueryClient();
  const [error, setError] = useState<string | null>(null);
  const owner = isOwner();

  /*
   * O redirect não termina quando a mutation resolve.
   *
   * `isPending` volta a false no instante em que a API responde, mas aí começa
   * a parte mais lenta e mais visível: o navegador ainda vai carregar a página
   * do Stripe, em outro domínio. Sem este estado o botão pisca de volta para
   * "Assinar agora" justamente durante essa espera, e o lojista clica de novo.
   *
   * Nunca volta para false de propósito — a página inteira está de saída.
   */
  const [redirecting, setRedirecting] = useState(false);

  function redirectTo(url: string) {
    setRedirecting(true);
    // Link de uso único e que expira; navegar na hora, sem guardar.
    window.location.assign(url);
  }

  // Cupom aplicado (validado pela API) e o que está sendo digitado.
  const [coupon, setCoupon] = useState<Coupon | null>(null);
  const [couponInput, setCouponInput] = useState('');
  const [couponError, setCouponError] = useState<string | null>(null);

  const { data: billing, isLoading } = useQuery({
    queryKey: ['billing'],
    queryFn: () => apiFetch<Billing>('/admin/billing'),
  });

  /*
   * Volta do Checkout. O `status=sucesso` NÃO significa assinatura ativa: quem
   * ativa é o webhook, que costuma chegar depois do redirect. Reconsultar aqui
   * evita que o lojista veja "sem assinatura" logo após pagar; o aviso abaixo
   * cobre o caso do webhook ainda estar a caminho.
   */
  const [justReturned, setJustReturned] = useState(false);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const status = params.get('status');
    if (!status) return;

    if (status === 'sucesso') {
      setJustReturned(true);
      queryClient.invalidateQueries({ queryKey: ['billing'] });
      queryClient.invalidateQueries({ queryKey: ['settings'] });
    }

    // Limpa a URL para que um F5 não repita o aviso.
    window.history.replaceState({}, '', window.location.pathname);
  }, [queryClient]);

  const checkout = useMutation({
    mutationFn: () =>
      apiFetch<{ url: string }>('/admin/billing/checkout', {
        method: 'POST',
        // Só o cupom já validado segue para o checkout. O backend revalida:
        // entre aplicar e assinar, o código pode esgotar.
        body: JSON.stringify({ coupon: coupon?.code ?? null }),
      }),
    onSuccess: ({ url }) => redirectTo(url),
    onError: (caught) =>
      setError(
        // O 422 do cupom traz mensagem própria; usá-la evita dizer "tente
        // novamente" a quem precisa é trocar o código.
        caught instanceof ApiError
          ? caught.message
          : 'Não foi possível abrir o pagamento. Tente novamente.',
      ),
  });

  const applyCoupon = useMutation({
    mutationFn: (code: string) =>
      apiFetch<Coupon>('/admin/billing/coupon', {
        method: 'POST',
        body: JSON.stringify({ coupon: code }),
      }),
    onSuccess: (applied) => {
      setCoupon(applied);
      setCouponError(null);
    },
    onError: (caught) => {
      setCoupon(null);
      setCouponError(
        caught instanceof ApiError ? caught.message : 'Não foi possível validar o cupom.',
      );
    },
  });

  const portal = useMutation({
    mutationFn: () =>
      apiFetch<{ url: string }>('/admin/billing/portal', { method: 'POST' }),
    onSuccess: ({ url }) => redirectTo(url),
    onError: () => setError('Não foi possível abrir o portal de cobrança.'),
  });

  if (isLoading || !billing) {
    return <p className="text-sm text-muted">Carregando…</p>;
  }

  return (
    <div>
      <PageHeader
        title="Assinatura"
        description="Seu plano e a cobrança mensal do ToMenu."
      />

      <div className="grid gap-4">
        {justReturned && !billing.active && (
          <p className="rounded-lg border border-line bg-accent/5 p-3.5 text-sm">
            Pagamento recebido. A confirmação chega em alguns segundos — pode
            atualizar a página.
          </p>
        )}

        {billing.mode === 'test' && (
          <p className="rounded-lg border border-line p-3.5 text-xs font-medium text-amber-600">
            Ambiente de testes — nenhuma cobrança real é feita.
          </p>
        )}

        <Section title="Plano atual">
          <div className="grid gap-3">
            <div className="flex items-baseline justify-between gap-3">
              <span className="text-sm font-medium">
                {billing.plan.name ?? '—'}
              </span>
              {billing.plan.priceCents !== null && (
                <span className="text-sm text-muted">
                  {/* Com cupom, o valor cheio fica riscado ao lado do novo:
                      ver o desconto acontecer é o que dá confiança de que o
                      código pegou. */}
                  {coupon?.priceCents != null && (
                    <span className="mr-1.5 line-through opacity-60">
                      {formatMoney(billing.plan.priceCents)}
                    </span>
                  )}
                  {formatMoney(coupon?.priceCents ?? billing.plan.priceCents)}/mês
                </span>
              )}
            </div>

            <StatusLine billing={billing} />
          </div>
        </Section>

        <Section
          title="Cobrança"
          description={
            billing.subscribed
              ? 'Cartão, faturas e cancelamento ficam no portal do Stripe.'
              : 'O pagamento é feito em uma página segura do Stripe.'
          }
        >
          {!billing.available ? (
            <p className="text-sm text-muted">
              A cobrança não está configurada nesta instalação.
            </p>
          ) : !owner ? (
            // O backend também recusa; esconder o botão evita o 403 na cara
            // de quem não pode agir.
            <p className="text-sm text-muted">
              Apenas o dono da loja pode gerenciar a assinatura.
            </p>
          ) : (
            <div className="grid gap-4">
              {/* O cupom só faz sentido antes de assinar: para quem já tem
                  assinatura, quem aplica desconto é o portal do Stripe. */}
              {!billing.active && (
                <CouponField
                  coupon={coupon}
                  input={couponInput}
                  error={couponError}
                  pending={applyCoupon.isPending}
                  onInput={(value) => {
                    setCouponInput(value);
                    setCouponError(null);
                  }}
                  onApply={() => {
                    const code = couponInput.trim();
                    if (code) applyCoupon.mutate(code);
                  }}
                  onRemove={() => {
                    setCoupon(null);
                    setCouponInput('');
                    setCouponError(null);
                  }}
                />
              )}

              <div className="flex flex-wrap gap-2">
              {billing.active ? (
                <button
                  type="button"
                  onClick={() => {
                    setError(null);
                    portal.mutate();
                  }}
                  disabled={portal.isPending || redirecting}
                  className="rounded-lg border border-line px-4 py-2 text-sm font-medium transition-colors hover:bg-line/40 disabled:opacity-50"
                >
                  {portal.isPending || redirecting
                    ? 'Abrindo…'
                    : 'Gerenciar assinatura'}
                </button>
              ) : (
                <button
                  type="button"
                  onClick={() => {
                    setError(null);
                    checkout.mutate();
                  }}
                  disabled={checkout.isPending || redirecting}
                  className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                >
                  {checkout.isPending || redirecting
                    ? 'Abrindo pagamento seguro…'
                    : billing.subscribed
                      ? 'Reativar assinatura'
                      : 'Assinar agora'}
                </button>
              )}

              {/* Quem já assinou chega ao portal mesmo com a assinatura
                  inativa: é lá que se troca o cartão recusado. */}
              {!billing.active && billing.subscribed && (
                <button
                  type="button"
                  onClick={() => {
                    setError(null);
                    portal.mutate();
                  }}
                  disabled={portal.isPending || redirecting}
                  className="rounded-lg border border-line px-4 py-2 text-sm font-medium transition-colors hover:bg-line/40 disabled:opacity-50"
                >
                  {portal.isPending || redirecting ? 'Abrindo…' : 'Atualizar cartão'}
                </button>
              )}
              </div>
            </div>
          )}

          {error && (
            <p role="alert" className="mt-3 text-xs text-red-600">
              {error}
            </p>
          )}
        </Section>
      </div>
    </div>
  );
}

/**
 * Campo de cupom.
 *
 * Aplicado, vira um resumo com o desconto em vez de um input editável: deixar o
 * campo aberto sugere que dá para acumular códigos, e só um vale por assinatura.
 */
function CouponField({
  coupon,
  input,
  error,
  pending,
  onInput,
  onApply,
  onRemove,
}: {
  coupon: Coupon | null;
  input: string;
  error: string | null;
  pending: boolean;
  onInput: (value: string) => void;
  onApply: () => void;
  onRemove: () => void;
}) {
  if (coupon) {
    return (
      <div className="flex items-start justify-between gap-3 rounded-lg border border-accent bg-accent/5 p-3">
        <div>
          <p className="text-sm font-medium">Cupom {coupon.code} aplicado</p>
          <p className="mt-0.5 text-xs text-muted">{coupon.description}</p>
        </div>

        <button
          type="button"
          onClick={onRemove}
          className="shrink-0 text-xs font-medium text-muted underline transition-colors hover:text-red-600"
        >
          Remover
        </button>
      </div>
    );
  }

  return (
    <div>
      <label
        htmlFor="cupom"
        className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-muted"
      >
        Cupom de desconto
      </label>

      <div className="flex gap-2">
        <input
          id="cupom"
          value={input}
          onChange={(e) => onInput(e.target.value)}
          // Enter aplica em vez de submeter formulário nenhum — o campo vive
          // fora de um <form> e sem isto a tecla não faria nada.
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              onApply();
            }
          }}
          placeholder="Tem um código? Digite aqui"
          // Códigos são maiúsculos no Stripe; a comparação lá ignora caixa,
          // mas exibir em maiúsculas evita a impressão de que digitou errado.
          className="field flex-1 uppercase placeholder:normal-case"
          autoComplete="off"
          spellCheck={false}
        />

        <button
          type="button"
          onClick={onApply}
          disabled={pending || input.trim() === ''}
          className="shrink-0 rounded-lg border border-line px-4 py-2 text-sm font-medium transition-colors hover:bg-line/40 disabled:opacity-50"
        >
          {pending ? 'Validando…' : 'Aplicar'}
        </button>
      </div>

      {error && (
        <p role="alert" className="mt-1.5 text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}

/** Uma linha de estado, escrita para o lojista e não para o sistema. */
function StatusLine({ billing }: { billing: Billing }) {
  if (billing.active) {
    return (
      <p className="text-sm text-muted">
        Assinatura ativa.
        {billing.currentPeriodEndsAt &&
          ` Próxima cobrança em ${formatDate(billing.currentPeriodEndsAt)}.`}
      </p>
    );
  }

  if (billing.trialExpired) {
    return (
      <p className="text-sm text-red-600">
        Seu período de teste terminou. Assine para manter a loja funcionando.
      </p>
    );
  }

  if (billing.trialDaysLeft !== null && billing.trialDaysLeft > 0) {
    return (
      <p className="text-sm text-muted">
        Teste grátis: {billing.trialDaysLeft}{' '}
        {billing.trialDaysLeft === 1 ? 'dia restante' : 'dias restantes'}.
        Assinando agora, você só é cobrado em{' '}
        {formatDate(billing.trialEndsAt)}.
      </p>
    );
  }

  return <p className="text-sm text-muted">Sem assinatura ativa.</p>;
}
