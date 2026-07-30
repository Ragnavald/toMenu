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

const ACTIVE = ['confirmed', 'preparing', 'ready', 'out_for_delivery'];

export function OrdersPage() {
  const queryClient = useQueryClient();
  const [filter, setFilter] = useState<string>('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['orders', filter],
    queryFn: () =>
      apiFetch<Paginated<Order>>(
        `/admin/orders${filter ? `?status=${filter}` : ''}`,
      ),
    // Em produção o alerta primário é o WebSocket (Laravel Reverb). Este
    // polling é a rede de segurança para quando a conexão cai — um pedido não
    // visto é receita perdida, então vale o custo da requisição extra.
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

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-lg font-semibold tracking-tight">Pedidos</h1>

        <div className="flex flex-wrap gap-1">
          {[
            { value: '', label: 'Todos' },
            ...ORDER_STATUSES.filter((s) => ACTIVE.includes(s)).map((s) => ({
              value: s,
              label: STATUS_LABELS[s],
            })),
          ].map((option) => (
            <button
              key={option.value}
              type="button"
              onClick={() => setFilter(option.value)}
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

      {isLoading && <p className="text-sm text-muted">Carregando pedidos…</p>}

      {isError && (
        <p className="panel p-4 text-sm text-red-600">
          Não foi possível carregar os pedidos.
        </p>
      )}

      {!isLoading && orders.length === 0 && (
        <div className="panel grid place-items-center px-6 py-16 text-center">
          <p className="text-sm font-medium">Nenhum pedido por aqui</p>
          <p className="mt-1 text-sm text-muted">
            Os pedidos aparecem automaticamente assim que chegarem.
          </p>
        </div>
      )}

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

            <div className="mt-3 flex flex-wrap gap-2">
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
                  Marcar como {STATUS_LABELS[NEXT_STATUS[order.status]]}
                </button>
              )}

              {order.status !== 'cancelled' && order.status !== 'delivered' && (
                <button
                  type="button"
                  disabled={updateStatus.isPending}
                  onClick={() =>
                    updateStatus.mutate({ id: order.id, status: 'cancelled' })
                  }
                  className="rounded-lg px-3 py-1.5 text-xs font-medium text-muted transition-colors hover:bg-line"
                >
                  Cancelar
                </button>
              )}
            </div>
          </li>
        ))}
      </ul>
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
