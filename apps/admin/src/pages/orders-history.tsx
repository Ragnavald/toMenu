import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch, formatMoney } from '@/lib/api';
import {
  FULFILLMENT_LABELS,
  STATUS_LABELS,
  paymentLabelFor,
  type Fulfillment,
  type Order,
  type Paginated,
} from '@/lib/types';

/** Data de hoje em `YYYY-MM-DD`, formato do `<input type="date">`. */
function today(): string {
  return new Date().toISOString().slice(0, 10);
}

/** Mesma coisa, `days` dias atrás — usado no atalho de período. */
function daysAgo(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() - days);

  return date.toISOString().slice(0, 10);
}

/**
 * Pedidos que já saíram do painel.
 *
 * É o outro lado do botão "Finalizar": nada é apagado ao arquivar, só sai de
 * vista. Aqui o lojista consulta por período o que passou pela loja, e é o
 * único lugar em que a exclusão definitiva é oferecida.
 */
export function OrdersHistoryPage() {
  const queryClient = useQueryClient();

  /*
   * Começa nos últimos 30 dias em vez de "tudo".
   *
   * Uma loja com um ano de movimento tem milhares de pedidos arquivados, e
   * abrir a aba não deveria puxar todos eles. O mês corrente é o recorte que
   * responde à pergunta mais comum, e o filtro está logo acima para ampliar.
   */
  const [from, setFrom] = useState<string>(daysAgo(30));
  const [to, setTo] = useState<string>(today());
  const [search, setSearch] = useState<string>('');

  // Confirmação da limpeza. Em duas etapas porque a ação apaga linhas do banco
  // e não tem desfazer — um clique acidental levaria o histórico junto.
  const [confirmingClear, setConfirmingClear] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['orders', 'archived', from, to, search],
    queryFn: () => {
      const params = new URLSearchParams();
      if (from) params.set('from', from);
      if (to) params.set('to', to);
      if (search.trim()) params.set('search', search.trim());
      params.set('per_page', '100');

      return apiFetch<Paginated<Order>>(
        `/admin/orders/archived?${params.toString()}`,
      );
    },
  });

  const clearHistory = useMutation({
    mutationFn: () => {
      const params = new URLSearchParams();
      if (from) params.set('from', from);
      if (to) params.set('to', to);

      return apiFetch<{ deleted: number }>(
        `/admin/orders/archived?${params.toString()}`,
        { method: 'DELETE' },
      );
    },
    onMutate: () => setActionError(null),
    onSuccess: () => {
      setConfirmingClear(false);
      queryClient.invalidateQueries({ queryKey: ['orders'] });
      // O financeiro lê a mesma tabela: apagar pedidos muda a receita do
      // período, e a tela dele precisa reconsultar.
      queryClient.invalidateQueries({ queryKey: ['finance'] });
    },
    onError: (caught) =>
      setActionError(
        caught instanceof ApiError
          ? caught.message
          : 'Não foi possível limpar o histórico.',
      ),
  });

  const orders = data?.data ?? [];
  const total = data?.total ?? 0;

  return (
    <div className="space-y-5">
      <div className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-lg font-semibold tracking-tight">
              Histórico de pedidos
            </h1>
            <p className="mt-0.5 text-sm text-muted">
              Pedidos finalizados, que já saíram do painel.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {[
              { label: 'Últimos 7 dias', days: 7 },
              { label: 'Últimos 30 dias', days: 30 },
              { label: 'Últimos 90 dias', days: 90 },
            ].map((preset) => (
              <button
                key={preset.days}
                type="button"
                onClick={() => {
                  setFrom(daysAgo(preset.days));
                  setTo(today());
                }}
                className="rounded-lg border border-line px-3 py-1.5 text-xs font-medium text-muted transition-colors hover:bg-line hover:text-foreground"
              >
                {preset.label}
              </button>
            ))}
          </div>
        </div>

        {/* Filtros */}
        <div className="panel flex flex-wrap items-end gap-3 p-3">
          <div className="flex flex-col gap-1">
            <label htmlFor="history-from" className="text-xs text-muted">
              De
            </label>
            <input
              id="history-from"
              type="date"
              value={from}
              max={to || undefined}
              onChange={(e) => setFrom(e.target.value)}
              className="rounded-lg border border-line bg-panel px-2.5 py-1.5 text-sm text-foreground focus:border-accent focus:outline-none"
            />
          </div>

          <div className="flex flex-col gap-1">
            <label htmlFor="history-to" className="text-xs text-muted">
              Até
            </label>
            <input
              id="history-to"
              type="date"
              value={to}
              min={from || undefined}
              onChange={(e) => setTo(e.target.value)}
              className="rounded-lg border border-line bg-panel px-2.5 py-1.5 text-sm text-foreground focus:border-accent focus:outline-none"
            />
          </div>

          <div className="flex min-w-[220px] flex-1 flex-col gap-1">
            <label htmlFor="history-search" className="text-xs text-muted">
              Buscar
            </label>
            <input
              id="history-search"
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Nº do pedido, cliente, telefone ou item…"
              className="rounded-lg border border-line bg-panel px-2.5 py-1.5 text-sm text-foreground placeholder:text-muted focus:border-accent focus:outline-none"
            />
          </div>

          <button
            type="button"
            onClick={() => {
              setFrom('');
              setTo('');
              setSearch('');
            }}
            className="rounded-lg border border-line px-3 py-1.5 text-xs font-medium text-muted transition-colors hover:bg-line hover:text-foreground"
          >
            Ver tudo
          </button>
        </div>
      </div>

      {actionError && (
        <p role="alert" className="panel p-3 text-sm text-red-600">
          {actionError}
        </p>
      )}

      {/* Zona de limpeza */}
      {orders.length > 0 && (
        <div className="panel flex flex-wrap items-center justify-between gap-3 border-red-500/30 p-3">
          {confirmingClear ? (
            <>
              <p className="text-sm text-red-600">
                Isso apaga definitivamente {total}{' '}
                {total === 1 ? 'pedido' : 'pedidos'}
                {from || to ? ' do período filtrado' : ''}. Os valores também
                saem do relatório financeiro e não há como desfazer.
              </p>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={clearHistory.isPending}
                  onClick={() => clearHistory.mutate()}
                  className="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
                >
                  {clearHistory.isPending
                    ? 'Apagando…'
                    : 'Sim, apagar definitivamente'}
                </button>
                <button
                  type="button"
                  onClick={() => setConfirmingClear(false)}
                  className="rounded-lg border border-line px-3 py-1.5 text-xs font-medium text-muted transition-colors hover:bg-line hover:text-foreground"
                >
                  Cancelar
                </button>
              </div>
            </>
          ) : (
            <>
              <p className="text-sm text-muted">
                Exporte o financeiro antes de limpar: os pedidos apagados também
                somem dos relatórios.
              </p>
              <button
                type="button"
                onClick={() => setConfirmingClear(true)}
                className="rounded-lg border border-red-500/40 px-3 py-1.5 text-xs font-medium text-red-600 transition-colors hover:bg-red-50 dark:hover:bg-red-950/30"
              >
                Limpar histórico
              </button>
            </>
          )}
        </div>
      )}

      {isLoading && <p className="text-sm text-muted">Carregando histórico…</p>}

      {isError && (
        <p className="panel p-4 text-sm text-red-600">
          Não foi possível carregar o histórico.
        </p>
      )}

      {!isLoading && orders.length === 0 && (
        <div className="panel grid place-items-center px-6 py-16 text-center">
          <p className="text-sm font-medium">Nenhum pedido no histórico</p>
          <p className="mt-1 text-sm text-muted">
            Os pedidos aparecem aqui depois de finalizados no painel.
          </p>
        </div>
      )}

      {!isLoading && orders.length > 0 && (
        <>
          <p className="text-xs text-muted">
            {total} {total === 1 ? 'pedido' : 'pedidos'} no período.
          </p>

          <ul className="grid gap-3">
            {orders.map((order) => (
              <li key={order.id} className="panel p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-semibold tabular-nums">
                        #{order.number}
                      </span>
                      <span className="rounded-md bg-zinc-100 px-1.5 py-0.5 text-[11px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                        {STATUS_LABELS[order.status] ?? order.status}
                      </span>
                      <span className="rounded-md bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-950/40 dark:text-sky-300">
                        {FULFILLMENT_LABELS[order.fulfillment as Fulfillment] ??
                          order.fulfillment}
                      </span>
                    </div>

                    <p className="mt-1 text-sm text-muted">
                      {order.customer?.name ?? 'Cliente'} ·{' '}
                      {order.customer?.phone ?? '—'} ·{' '}
                      {paymentLabelFor(order.payment_method, order.fulfillment)}
                    </p>

                    {order.archived_at && (
                      <p className="mt-0.5 text-xs text-muted">
                        Finalizado em{' '}
                        {new Date(order.archived_at).toLocaleString('pt-BR')}
                      </p>
                    )}
                  </div>

                  <span className="text-sm font-semibold tabular-nums">
                    {formatMoney(order.total_cents)}
                  </span>
                </div>

                <ul className="mt-3 grid gap-0.5 border-t border-line pt-3">
                  {order.items.map((item) => (
                    <li key={item.id} className="flex justify-between text-sm">
                      <span className="text-muted">
                        {item.quantity}× {item.product_name}
                      </span>
                      <span className="tabular-nums text-muted">
                        {formatMoney(item.total_cents)}
                      </span>
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}
