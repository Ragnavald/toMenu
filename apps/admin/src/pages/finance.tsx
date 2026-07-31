import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiDownload, apiFetch, formatMoney } from '@/lib/api';
import {
  PAYMENT_LABELS,
  type FinanceOverview,
  type Order,
  type Paginated,
  type ReportExport,
} from '@/lib/types';
import { PageHeader } from '@/components/ui';
import { PaymentMixBar, RevenueBars } from '@/components/charts';

/** Atalhos cobrem o que o dono pergunta no dia a dia; o resto vai no custom. */
type PresetKey = 'this_month' | 'last_month' | 'last_3_months' | 'year' | 'custom';

const PRESETS: { key: PresetKey; label: string }[] = [
  { key: 'this_month', label: 'Este mês' },
  { key: 'last_month', label: 'Mês passado' },
  { key: 'last_3_months', label: '3 meses' },
  { key: 'year', label: 'Este ano' },
  { key: 'custom', label: 'Personalizado' },
];

function toISO(date: Date): string {
  // Formata em horário local: toISOString() converte para UTC e, à noite no
  // Brasil, devolve a data do dia seguinte.
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${date.getFullYear()}-${month}-${day}`;
}

function rangeFor(preset: PresetKey): { from: string; to: string } {
  const now = new Date();

  switch (preset) {
    case 'last_month': {
      const first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
      const last = new Date(now.getFullYear(), now.getMonth(), 0);
      return { from: toISO(first), to: toISO(last) };
    }
    case 'last_3_months': {
      const first = new Date(now.getFullYear(), now.getMonth() - 2, 1);
      return { from: toISO(first), to: toISO(now) };
    }
    case 'year':
      return { from: toISO(new Date(now.getFullYear(), 0, 1)), to: toISO(now) };
    case 'this_month':
    default: {
      const first = new Date(now.getFullYear(), now.getMonth(), 1);
      const last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
      return { from: toISO(first), to: toISO(last) };
    }
  }
}

export function FinancePage() {
  const [preset, setPreset] = useState<PresetKey>('this_month');
  const [range, setRange] = useState(() => rangeFor('this_month'));
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  // Debounce da busca: sem isso cada tecla dispara duas queries pesadas.
  useEffect(() => {
    const timer = setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 400);

    return () => clearTimeout(timer);
  }, [searchInput]);

  const params = useMemo(() => {
    const query = new URLSearchParams({ from: range.from, to: range.to });
    if (search) query.set('search', search);
    return query;
  }, [range, search]);

  const { data: overview, isLoading } = useQuery({
    queryKey: ['finance', 'overview', range, search],
    queryFn: () => apiFetch<FinanceOverview>(`/admin/finance/overview?${params}`),
  });

  const { data: orders } = useQuery({
    queryKey: ['finance', 'orders', range, search, page],
    queryFn: () =>
      apiFetch<Paginated<Order>>(
        `/admin/finance/orders?${params}&page=${page}&per_page=25`,
      ),
    placeholderData: (previous) => previous, // Evita piscar a tabela ao paginar.
  });

  function applyPreset(next: PresetKey) {
    setPreset(next);
    setPage(1);
    if (next !== 'custom') setRange(rangeFor(next));
  }

  return (
    <div>
      <PageHeader
        title="Financeiro"
        description="Receita, pedidos e relatórios do seu restaurante."
        action={<ExportButton range={range} search={search} />}
      />

      {/* Filtros numa linha só, acima dos gráficos. */}
      <div className="mb-5 grid gap-3">
        <div className="flex flex-wrap items-center gap-1.5">
          {PRESETS.map((item) => (
            <button
              key={item.key}
              type="button"
              onClick={() => applyPreset(item.key)}
              className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                preset === item.key
                  ? 'bg-accent text-white'
                  : 'border border-line text-muted hover:bg-line/50'
              }`}
            >
              {item.label}
            </button>
          ))}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <input
            type="date"
            value={range.from}
            onChange={(e) => {
              setPreset('custom');
              setRange((current) => ({ ...current, from: e.target.value }));
              setPage(1);
            }}
            className="field w-auto text-xs"
            aria-label="Data inicial"
          />
          <span className="text-xs text-muted">até</span>
          <input
            type="date"
            value={range.to}
            onChange={(e) => {
              setPreset('custom');
              setRange((current) => ({ ...current, to: e.target.value }));
              setPage(1);
            }}
            className="field w-auto text-xs"
            aria-label="Data final"
          />

          <input
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="Buscar por pedido, cliente ou item…"
            className="field min-w-52 flex-1 text-xs"
            aria-label="Buscar pedidos"
          />
        </div>
      </div>

      {isLoading && !overview ? (
        <p className="py-16 text-center text-sm text-muted">Carregando…</p>
      ) : (
        <div className="grid gap-4">
          <StatRow summary={overview?.summary} />

          <div className="grid gap-4 lg:grid-cols-[1.6fr_1fr]">
            <section className="panel p-4 sm:p-5">
              <h2 className="text-sm font-semibold">
                Receita por {overview?.range.granularity === 'day' ? 'dia' : 'mês'}
              </h2>
              <p className="mt-0.5 text-xs text-muted">
                Considera apenas pedidos entregues e pagos.
              </p>
              <div className="mt-4">
                <RevenueBars points={overview?.series ?? []} />
              </div>
            </section>

            <section className="panel p-4 sm:p-5">
              <h2 className="text-sm font-semibold">Formas de pagamento</h2>
              <p className="mt-0.5 text-xs text-muted">
                Composição da receita no período.
              </p>
              <div className="mt-4">
                <PaymentMixBar
                  items={overview?.byPaymentMethod ?? []}
                  labels={PAYMENT_LABELS}
                />
              </div>
            </section>
          </div>

          {(overview?.monthly.length ?? 0) > 1 && (
            <section className="panel p-4 sm:p-5">
              <h2 className="text-sm font-semibold">Tendência mensal</h2>
              <p className="mt-0.5 text-xs text-muted">
                Receita mês a mês, para comparar com os anteriores.
              </p>
              <div className="mt-4">
                <RevenueBars points={overview?.monthly ?? []} />
              </div>
            </section>
          )}

          <OrdersTable
            data={orders}
            page={page}
            onPage={setPage}
          />
        </div>
      )}
    </div>
  );
}

function StatRow({ summary }: { summary?: FinanceOverview['summary'] }) {
  const stats = [
    { label: 'Receita total', value: formatMoney(summary?.revenueCents ?? 0) },
    {
      label: 'Pedidos',
      value: (summary?.ordersCount ?? 0).toLocaleString('pt-BR'),
    },
    { label: 'Ticket médio', value: formatMoney(summary?.averageTicketCents ?? 0) },
    { label: 'Taxas de entrega', value: formatMoney(summary?.deliveryCents ?? 0) },
  ];

  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {stats.map((stat) => (
        <div key={stat.label} className="panel p-4">
          <p className="text-[11px] font-medium uppercase tracking-wide text-muted">
            {stat.label}
          </p>
          <p className="mt-1.5 text-xl font-semibold tabular-nums">{stat.value}</p>
        </div>
      ))}
    </div>
  );
}

function OrdersTable({
  data,
  page,
  onPage,
}: {
  data?: Paginated<Order>;
  page: number;
  onPage: (page: number) => void;
}) {
  const orders = data?.data ?? [];

  return (
    <section className="panel overflow-hidden">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3 sm:px-5">
        <h2 className="text-sm font-semibold">
          Registros de pedidos
          {data ? (
            <span className="ml-1.5 font-normal text-muted">
              ({data.total.toLocaleString('pt-BR')})
            </span>
          ) : null}
        </h2>
      </div>

      {orders.length === 0 ? (
        <p className="px-5 py-12 text-center text-sm text-muted">
          Nenhum pedido entregue e pago neste período.
        </p>
      ) : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[680px] text-sm">
              <thead>
                <tr className="border-b border-line text-left text-[11px] uppercase tracking-wide text-muted">
                  <th className="px-4 py-2.5 font-semibold sm:px-5">#</th>
                  <th className="px-4 py-2.5 font-semibold">Data</th>
                  <th className="px-4 py-2.5 font-semibold">Cliente</th>
                  <th className="px-4 py-2.5 font-semibold">Pagamento</th>
                  <th className="px-4 py-2.5 text-right font-semibold">Entrega</th>
                  <th className="px-4 py-2.5 text-right font-semibold sm:px-5">Total</th>
                </tr>
              </thead>
              <tbody>
                {orders.map((order) => (
                  <tr key={order.id} className="border-b border-line/70 last:border-0">
                    <td className="px-4 py-2.5 font-medium tabular-nums sm:px-5">
                      {order.number}
                    </td>
                    <td className="px-4 py-2.5 tabular-nums text-muted">
                      {order.placed_at
                        ? new Date(order.placed_at).toLocaleString('pt-BR', {
                            day: '2-digit',
                            month: '2-digit',
                            year: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                          })
                        : '—'}
                    </td>
                    <td className="px-4 py-2.5">
                      {order.customer?.name ?? 'Não informado'}
                    </td>
                    <td className="px-4 py-2.5 text-muted">
                      {PAYMENT_LABELS[order.payment_method] ?? order.payment_method}
                    </td>
                    <td className="px-4 py-2.5 text-right tabular-nums text-muted">
                      {formatMoney(order.delivery_fee_cents)}
                    </td>
                    <td className="px-4 py-2.5 text-right font-semibold tabular-nums sm:px-5">
                      {formatMoney(order.total_cents)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {(data?.last_page ?? 1) > 1 && (
            <div className="flex items-center justify-between gap-3 border-t border-line px-4 py-3 sm:px-5">
              <button
                type="button"
                disabled={page <= 1}
                onClick={() => onPage(page - 1)}
                className="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition-colors hover:bg-line/50 disabled:opacity-40"
              >
                Anterior
              </button>
              <span className="text-xs text-muted">
                Página {data?.current_page} de {data?.last_page}
              </span>
              <button
                type="button"
                disabled={page >= (data?.last_page ?? 1)}
                onClick={() => onPage(page + 1)}
                className="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition-colors hover:bg-line/50 disabled:opacity-40"
              >
                Próxima
              </button>
            </div>
          )}
        </>
      )}
    </section>
  );
}

/**
 * Botão de exportar PDF.
 *
 * A geração acontece na fila, então o botão não “trava” esperando: dispara o
 * pedido e passa a consultar o estado até o arquivo ficar pronto.
 */
function ExportButton({
  range,
  search,
}: {
  range: { from: string; to: string };
  search: string;
}) {
  const queryClient = useQueryClient();
  const [trackingId, setTrackingId] = useState<number | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const request = useMutation({
    mutationFn: () =>
      apiFetch<ReportExport>('/admin/finance/exports', {
        method: 'POST',
        body: JSON.stringify({ from: range.from, to: range.to, search }),
      }),
    onSuccess: (report) => {
      setMessage(null);
      setTrackingId(report.id);
      queryClient.invalidateQueries({ queryKey: ['finance', 'exports'] });
    },
    onError: (error) => {
      // O limite de linhas volta como erro de validação; mostrar a mensagem do
      // servidor é mais útil que um "erro ao exportar" genérico.
      const detail =
        error instanceof ApiError
          ? (error.errors?.range?.[0] ?? error.message)
          : 'Não foi possível gerar o relatório.';
      setMessage(detail);
    },
  });

  const { data: tracked } = useQuery({
    queryKey: ['finance', 'exports', trackingId],
    queryFn: () => apiFetch<ReportExport>(`/admin/finance/exports/${trackingId}`),
    enabled: trackingId !== null,
    // Consulta enquanto estiver na fila; para assim que houver desfecho.
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return status === 'queued' || status === 'processing' ? 1500 : false;
    },
  });

  // Baixa sozinho assim que o worker termina — o dono pediu o PDF, não um
  // segundo clique.
  useEffect(() => {
    if (tracked?.status !== 'done' || trackingId === null) return;

    apiDownload(
      `/admin/finance/exports/${trackingId}/download`,
      `financeiro-${tracked.from}-a-${tracked.to}.pdf`,
    ).catch(() => setMessage('O relatório ficou pronto, mas o download falhou.'));

    setTrackingId(null);
  }, [tracked, trackingId]);

  useEffect(() => {
    if (tracked?.status === 'failed') {
      setMessage(tracked.error ?? 'Não foi possível gerar o relatório.');
      setTrackingId(null);
    }
  }, [tracked]);

  const working =
    request.isPending ||
    tracked?.status === 'queued' ||
    tracked?.status === 'processing';

  return (
    <div className="text-right">
      <button
        type="button"
        onClick={() => request.mutate()}
        disabled={working}
        className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
      >
        {working ? 'Gerando PDF…' : 'Exportar PDF'}
      </button>

      {working && (
        <p className="mt-1 text-[11px] text-muted">
          Pode fechar esta tela — o arquivo continua sendo gerado.
        </p>
      )}

      {message && (
        <p role="alert" className="mt-1 max-w-xs text-[11px] text-red-600">
          {message}
        </p>
      )}
    </div>
  );
}
