import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch, formatMoney } from '@/lib/api';
import {
  ORDER_STATUSES,
  PAYMENT_LABELS,
  STATUS_LABELS,
  type Order,
  type Paginated,
} from '@/lib/types';

/** Próximo passo natural do fluxo, para virar o status com um clique só. */
const NEXT_STATUS: Record<string, string> = {
  confirmed: 'preparing',
  preparing: 'ready',
  ready: 'out_for_delivery',
  out_for_delivery: 'delivered',
};

const KANBAN_COLUMNS: { key: string; label: string; tone: string }[] = [
  {
    key: 'confirmed',
    label: 'Confirmados',
    tone: 'border-blue-500/30 bg-blue-50/30 dark:bg-blue-950/10',
  },
  {
    key: 'preparing',
    label: 'Em Preparo',
    tone: 'border-indigo-500/30 bg-indigo-50/30 dark:bg-indigo-950/10',
  },
  {
    key: 'ready',
    label: 'Prontos',
    tone: 'border-emerald-500/30 bg-emerald-50/30 dark:bg-emerald-950/10',
  },
  {
    key: 'out_for_delivery',
    label: 'Em Entrega',
    tone: 'border-cyan-500/30 bg-cyan-50/30 dark:bg-cyan-950/10',
  },
  {
    key: 'delivered',
    label: 'Entregues',
    tone: 'border-zinc-500/30 bg-zinc-50/20 dark:bg-zinc-900/20',
  },
];

export function OrdersPage() {
  const queryClient = useQueryClient();
  const [filter, setFilter] = useState<string>('');
  const [search, setSearch] = useState<string>('');
  const [viewMode, setViewMode] = useState<'kanban' | 'list'>('kanban');

  // Drag and drop state
  const [draggingId, setDraggingId] = useState<number | null>(null);
  const [dragOverColumn, setDragOverColumn] = useState<string | null>(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['orders', filter, search],
    queryFn: () => {
      const params = new URLSearchParams();
      if (filter) params.set('status', filter);
      if (search.trim()) params.set('search', search.trim());
      params.set('per_page', '100'); // Traz até 100 pedidos recentes para fluidez no Kanban
      const queryString = params.toString();
      return apiFetch<Paginated<Order>>(
        `/admin/orders${queryString ? `?${queryString}` : ''}`,
      );
    },
    refetchInterval: 15_000,
  });

  const updateStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) =>
      apiFetch(`/admin/orders/${id}/status`, {
        method: 'PATCH',
        body: JSON.stringify({ status }),
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['orders'] }),
  });

  const orders = data?.data ?? [];

  // Handlers do HTML5 Drag and Drop (Kanban)
  const handleDragStart = (e: React.DragEvent, orderId: number) => {
    e.dataTransfer.setData('text/plain', String(orderId));
    setDraggingId(orderId);
  };

  const handleDragOver = (e: React.DragEvent, columnKey: string) => {
    e.preventDefault();
    if (dragOverColumn !== columnKey) {
      setDragOverColumn(columnKey);
    }
  };

  const handleDragLeave = (e: React.DragEvent) => {
    e.preventDefault();
    setDragOverColumn(null);
  };

  const handleDrop = (e: React.DragEvent, targetStatus: string) => {
    e.preventDefault();
    setDragOverColumn(null);
    setDraggingId(null);

    const idStr = e.dataTransfer.getData('text/plain');
    const orderId = Number(idStr);

    if (orderId && !isNaN(orderId)) {
      const targetOrder = orders.find((o) => o.id === orderId);
      if (targetOrder && targetOrder.status !== targetStatus) {
        updateStatus.mutate({ id: orderId, status: targetStatus });
      }
    }
  };

  return (
    <div className="space-y-5">
      {/* Top Header & Controles */}
      <div className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <h1 className="text-lg font-semibold tracking-tight">Pedidos</h1>
            {/* Visual Switcher para Desktop */}
            <div className="hidden items-center rounded-lg border border-line bg-panel p-0.5 sm:flex">
              <button
                type="button"
                onClick={() => setViewMode('kanban')}
                className={`flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                  viewMode === 'kanban'
                    ? 'bg-accent text-white'
                    : 'text-muted hover:text-foreground'
                }`}
              >
                <svg className="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" />
                </svg>
                Kanban
              </button>
              <button
                type="button"
                onClick={() => setViewMode('list')}
                className={`flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                  viewMode === 'list'
                    ? 'bg-accent text-white'
                    : 'text-muted hover:text-foreground'
                }`}
              >
                <svg className="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                </svg>
                Lista
              </button>
            </div>
          </div>

          <div className="flex flex-wrap gap-1">
            {[
              { value: '', label: 'Todos' },
              ...ORDER_STATUSES.filter((s) => s !== 'pending_payment').map(
                (s) => ({
                  value: s,
                  label: STATUS_LABELS[s] ?? s,
                }),
              ),
            ].map((option) => (
              <button
                key={option.value}
                type="button"
                onClick={() => {
                  setFilter(option.value);
                  if (option.value === '') {
                    setViewMode('kanban');
                  }
                }}
                className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                  filter === option.value
                    ? 'bg-accent text-white'
                    : 'text-muted hover:bg-line'
                }`}
              >
                {option.label}
              </button>
            ))}
          </div>
        </div>

        <div className="relative">
          <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
            <svg
              className="size-4 text-muted"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
              />
            </svg>
          </div>
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Pesquisar por nº do pedido (#1001), cliente, telefone ou item..."
            className="w-full rounded-lg border border-line bg-panel py-2 pl-9 pr-9 text-sm text-foreground transition-colors placeholder:text-muted focus:border-accent focus:outline-none"
          />
          {search && (
            <button
              type="button"
              onClick={() => setSearch('')}
              className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted hover:text-foreground"
              title="Limpar pesquisa"
            >
              <svg
                className="size-4"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>
          )}
        </div>
      </div>

      {isLoading && <p className="text-sm text-muted">Carregando pedidos…</p>}

      {isError && (
        <p className="panel p-4 text-sm text-red-600">
          Não foi possível carregar os pedidos.
        </p>
      )}

      {!isLoading && orders.length === 0 && (
        <div className="panel grid place-items-center px-6 py-16 text-center">
          <p className="text-sm font-medium">
            {search
              ? `Nenhum pedido encontrado para "${search}"`
              : 'Nenhum pedido por aqui'}
          </p>
          <p className="mt-1 text-sm text-muted">
            {search
              ? 'Tente buscar por outro número, nome de cliente ou telefone.'
              : 'Os pedidos aparecem automaticamente assim que chegarem.'}
          </p>
          {search && (
            <button
              type="button"
              onClick={() => setSearch('')}
              className="mt-3 rounded-lg border border-line px-3 py-1.5 text-xs font-medium text-muted transition-colors hover:bg-line hover:text-foreground"
            >
              Limpar pesquisa
            </button>
          )}
        </div>
      )}

      {/* KANBAN VIEW (Exibe Kanban quando estiver no modo Kanban E sem filtro de status específico selecionado) */}
      {!isLoading && orders.length > 0 && viewMode === 'kanban' && !filter && (
        <div className="flex overflow-x-auto pb-4 gap-4 scrollbar-thin">
          {KANBAN_COLUMNS.map((col) => {
            const columnOrders = orders.filter((o) => o.status === col.key);
            const isTarget = dragOverColumn === col.key;

            return (
              <div
                key={col.key}
                onDragOver={(e) => handleDragOver(e, col.key)}
                onDragLeave={handleDragLeave}
                onDrop={(e) => handleDrop(e, col.key)}
                className={`flex flex-col min-w-[280px] lg:min-w-[300px] flex-1 min-h-[680px] max-h-[calc(100vh-170px)] rounded-xl border p-3.5 transition-all ${
                  col.tone
                } ${
                  isTarget
                    ? 'ring-2 ring-accent border-accent bg-accent/10 shadow-lg scale-[1.01]'
                    : 'border-line/70'
                }`}
              >
                {/* Header da Coluna */}
                <div className="mb-3 flex items-center justify-between border-b border-line/60 pb-2.5">
                  <span className="text-xs font-bold uppercase tracking-wider text-foreground">
                    {col.label}
                  </span>
                  <span className="rounded-full bg-panel px-2.5 py-0.5 text-xs font-bold tabular-nums text-muted border border-line">
                    {columnOrders.length}
                  </span>
                </div>

                {/* Cards na Coluna */}
                <div className="flex-1 space-y-3 overflow-y-auto pr-0.5">
                  {columnOrders.length === 0 ? (
                    <div className="flex h-36 items-center justify-center rounded-lg border border-dashed border-line/60 text-center text-xs text-muted">
                      Arraste um pedido para cá
                    </div>
                  ) : (
                    columnOrders.map((order) => {
                      const isDragging = draggingId === order.id;

                      return (
                        <div
                          key={order.id}
                          draggable
                          onDragStart={(e) => handleDragStart(e, order.id)}
                          className={`panel cursor-grab active:cursor-grabbing p-3.5 shadow-xs transition-all hover:shadow-md ${
                            isDragging ? 'opacity-40 scale-95 border-dashed border-accent' : ''
                          }`}
                        >
                          <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                              <div className="flex items-center gap-1.5">
                                <span className="font-bold tabular-nums text-sm">
                                  #{order.number}
                                </span>
                                {order.payment_status === 'paid' && (
                                  <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                    Pago
                                  </span>
                                )}
                              </div>
                              <p className="mt-0.5 text-xs font-medium text-foreground truncate">
                                {order.customer?.name ?? 'Cliente'}
                              </p>
                              <p className="text-[11px] text-muted truncate">
                                {order.customer?.phone ?? '—'}
                              </p>
                            </div>
                            <span className="text-xs font-bold tabular-nums text-foreground">
                              {formatMoney(order.total_cents)}
                            </span>
                          </div>

                          {/* Itens */}
                          <ul className="mt-2.5 divide-y divide-line/40 border-t border-line/40 pt-2 text-xs">
                            {order.items.map((item) => (
                              <li key={item.id} className="flex justify-between py-0.5 text-muted">
                                <span className="truncate">
                                  {item.quantity}× {item.product_name}
                                </span>
                              </li>
                            ))}
                          </ul>

                          {order.notes && (
                            <p className="mt-2 rounded bg-amber-50 p-1.5 text-[11px] text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                              {order.notes}
                            </p>
                          )}

                          {/* Status Select para alteração rápida sem drag */}
                          <div className="mt-3 flex items-center justify-between gap-2 border-t border-line/40 pt-2">
                            <span className="text-[10px] text-muted">Status:</span>
                            <select
                              value={order.status}
                              disabled={updateStatus.isPending}
                              onChange={(e) =>
                                updateStatus.mutate({
                                  id: order.id,
                                  status: e.target.value,
                                })
                              }
                              className="rounded border border-line bg-panel px-1.5 py-0.5 text-[11px] font-medium text-foreground focus:outline-none"
                            >
                              {ORDER_STATUSES.map((st) => (
                                <option key={st} value={st}>
                                  {STATUS_LABELS[st] ?? st}
                                </option>
                              ))}
                            </select>
                          </div>
                        </div>
                      );
                    })
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* LISTA VIEW (Exibida no modo lista OU quando há filtro de status específico selecionado) */}
      {!isLoading && orders.length > 0 && (viewMode === 'list' || Boolean(filter)) && (
        <ul className="grid gap-3">
          {orders.map((order) => (
            <li key={order.id} className="panel p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="font-semibold tabular-nums">
                      #{order.number}
                    </span>
                    <StatusBadge status={order.status} />
                    {order.payment_status === 'paid' && (
                      <span className="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                        Pago
                      </span>
                    )}
                  </div>

                  <p className="mt-1 text-sm text-muted">
                    {order.customer?.name ?? 'Cliente'} ·{' '}
                    {order.customer?.phone ?? '—'} ·{' '}
                    {order.fulfillment === 'delivery' ? 'Entrega' : 'Retirada'} ·{' '}
                    {PAYMENT_LABELS[order.payment_method] ?? order.payment_method}
                  </p>
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

              {order.notes && (
                <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                  {order.notes}
                </p>
              )}

              {/* Controles de Status Mobile / Lista */}
              <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
                <div className="flex flex-wrap items-center gap-2">
                  {NEXT_STATUS[order.status] && (
                    <button
                      type="button"
                      disabled={updateStatus.isPending}
                      onClick={() =>
                        updateStatus.mutate({
                          id: order.id,
                          status: NEXT_STATUS[order.status],
                        })
                      }
                      className="rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
                    >
                      Avançar para {STATUS_LABELS[NEXT_STATUS[order.status]]}
                    </button>
                  )}

                  {order.status !== 'cancelled' && order.status !== 'delivered' && (
                    <button
                      type="button"
                      disabled={updateStatus.isPending}
                      onClick={() =>
                        updateStatus.mutate({ id: order.id, status: 'cancelled' })
                      }
                      className="rounded-lg border border-line px-3 py-1.5 text-xs font-medium text-muted transition-colors hover:bg-line hover:text-foreground"
                    >
                      Cancelar
                    </button>
                  )}
                </div>

                {/* Selector Responsivo de Status */}
                <div className="flex items-center gap-1.5">
                  <label htmlFor={`status-select-${order.id}`} className="text-xs text-muted">
                    Status:
                  </label>
                  <select
                    id={`status-select-${order.id}`}
                    value={order.status}
                    disabled={updateStatus.isPending}
                    onChange={(e) =>
                      updateStatus.mutate({
                        id: order.id,
                        status: e.target.value,
                      })
                    }
                    className="rounded-lg border border-line bg-panel px-2 py-1 text-xs font-medium text-foreground focus:border-accent focus:outline-none"
                  >
                    {ORDER_STATUSES.map((st) => (
                      <option key={st} value={st}>
                        {STATUS_LABELS[st] ?? st}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const tones: Record<string, string> = {
    pending_payment:
      'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    confirmed: 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
    preparing:
      'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300',
    ready:
      'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    out_for_delivery:
      'bg-cyan-50 text-cyan-700 dark:bg-cyan-950/40 dark:text-cyan-300',
    delivered: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
    cancelled: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
  };

  return (
    <span
      className={`rounded-md px-1.5 py-0.5 text-[11px] font-medium ${
        tones[status] ?? tones.delivered
      }`}
    >
      {STATUS_LABELS[status] ?? status}
    </span>
  );
}
