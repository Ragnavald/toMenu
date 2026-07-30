'use client';

import { useEffect, useRef, useState } from 'react';
import { fetchOrderStatus, formatMoney } from '@/lib/api';
import {
  getStoredOrders,
  updateStoredOrderStatus,
  type StoredOrder,
} from '@/lib/orders-storage';

const STATUS_LABELS: Record<string, string> = {
  pending_payment: 'Aguardando Pagamento',
  confirmed: 'Pedido Confirmado',
  preparing: 'Em Preparação',
  ready: 'Pronto para Retirada',
  out_for_delivery: 'Saiu para Entrega',
  delivered: 'Entregue',
  cancelled: 'Cancelado',
};

const STEPPER_STAGES = [
  { key: 'confirmed', label: 'Confirmado' },
  { key: 'preparing', label: 'Em Preparo' },
  { key: 'ready_out', label: 'A Caminho / Pronto' },
  { key: 'delivered', label: 'Entregue' },
];

function getActiveStep(status: string): number {
  switch (status) {
    case 'pending_payment':
      return 0;
    case 'confirmed':
      return 1;
    case 'preparing':
      return 2;
    case 'ready':
    case 'out_for_delivery':
      return 3;
    case 'delivered':
      return 4;
    default:
      return 0;
  }
}

export function OrdersSheet({
  tenantSlug,
  onClose,
}: {
  tenantSlug: string;
  onClose: () => void;
}) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const [orders, setOrders] = useState<StoredOrder[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState<'active' | 'history'>('active');

  const refreshOrders = async () => {
    const localList = getStoredOrders(tenantSlug);
    setOrders(localList);

    // Atualiza status dos pedidos ativos via API
    const activeOrders = localList.filter(
      (o) => o.status !== 'delivered' && o.status !== 'cancelled'
    );

    if (activeOrders.length > 0) {
      const updatedList = [...localList];
      let hasChanges = false;

      for (const order of activeOrders) {
        const latest = await fetchOrderStatus(tenantSlug, order.id);
        if (latest && latest.status && latest.status !== order.status) {
          updateStoredOrderStatus(tenantSlug, order.id, {
            status: latest.status,
          });
          const target = updatedList.find((item) => item.id === order.id);
          if (target) {
            target.status = latest.status;
            hasChanges = true;
          }
        }
      }

      if (hasChanges) {
        setOrders([...updatedList]);
      }
    }
    setLoading(false);
  };

  useEffect(() => {
    dialogRef.current?.showModal();
    refreshOrders();

    // Poll status a cada 10 segundos
    const interval = setInterval(() => {
      refreshOrders();
    }, 10_000);

    return () => clearInterval(interval);
  }, [tenantSlug]);

  const activeOrders = orders.filter(
    (o) => o.status !== 'delivered' && o.status !== 'cancelled'
  );
  const historyOrders = orders.filter(
    (o) => o.status === 'delivered' || o.status === 'cancelled'
  );

  return (
    <dialog
      ref={dialogRef}
      onClose={onClose}
      className="m-0 max-h-[94dvh] w-full max-w-lg self-end bg-transparent p-0 backdrop:bg-black/45 sm:m-auto sm:self-center"
    >
      <div
        className="animate-slide-up flex max-h-[94dvh] flex-col overflow-hidden bg-[rgb(var(--surface))] text-[rgb(var(--ink))] sm:animate-rise"
        style={{ borderRadius: 'var(--radius)' }}
      >
        <header className="flex items-center justify-between border-b border-[var(--hairline)] px-5 py-3.5">
          <div className="flex items-center gap-2">
            <h2 className="text-base font-semibold">Meus Pedidos</h2>
            {activeOrders.length > 0 && (
              <span
                className="inline-flex size-5 items-center justify-center rounded-full text-xs font-bold"
                style={{
                  background: 'rgb(var(--brand))',
                  color: 'rgb(var(--brand-ink))',
                }}
              >
                {activeOrders.length}
              </span>
            )}
          </div>

          <button
            type="button"
            onClick={() => dialogRef.current?.close()}
            aria-label="Fechar"
            className="grid size-8 place-items-center rounded-full text-muted transition-colors hover:bg-[var(--hairline)]"
          >
            ✕
          </button>
        </header>

        {/* Abas */}
        <div className="flex border-b border-[var(--hairline)] px-5">
          <button
            type="button"
            onClick={() => setActiveTab('active')}
            className={`border-b-2 px-4 py-2.5 text-xs font-semibold transition-colors ${
              activeTab === 'active'
                ? 'border-[rgb(var(--brand))] text-[rgb(var(--ink))]'
                : 'border-transparent text-subtle hover:text-[rgb(var(--ink))]'
            }`}
          >
            Em Andamento ({activeOrders.length})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('history')}
            className={`border-b-2 px-4 py-2.5 text-xs font-semibold transition-colors ${
              activeTab === 'history'
                ? 'border-[rgb(var(--brand))] text-[rgb(var(--ink))]'
                : 'border-transparent text-subtle hover:text-[rgb(var(--ink))]'
            }`}
          >
            Histórico ({historyOrders.length})
          </button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
          {loading ? (
            <div className="py-10 text-center text-xs text-subtle">
              Carregando seus pedidos...
            </div>
          ) : activeTab === 'active' ? (
            activeOrders.length === 0 ? (
              <div className="py-12 text-center">
                <p className="text-sm font-medium">Nenhum pedido em andamento</p>
                <p className="mt-1 text-xs text-subtle">
                  Seus pedidos ativos aparecerão aqui assim que você concluir o checkout.
                </p>
              </div>
            ) : (
              <div className="grid gap-4">
                {activeOrders.map((order) => {
                  const currentStep = getActiveStep(order.status);
                  const isCancelled = order.status === 'cancelled';

                  return (
                    <div
                      key={order.id}
                      className="rounded-xl border border-[var(--hairline)] bg-surface-raised p-4"
                    >
                      <div className="flex items-center justify-between pb-3 border-b border-[var(--hairline)]">
                        <div>
                          <span className="text-sm font-bold">
                            Pedido #{order.number}
                          </span>
                          <p className="text-[11px] text-subtle">
                            {new Date(order.placedAt).toLocaleTimeString('pt-BR', {
                              hour: '2-digit',
                              minute: '2-digit',
                            })}{' '}
                            · {order.fulfillment === 'delivery' ? 'Entrega' : 'Retirada'}
                          </p>
                        </div>
                        <span
                          className="rounded-full px-2.5 py-1 text-xs font-semibold"
                          style={{
                            background: 'rgb(var(--brand-soft))',
                            color: 'rgb(var(--brand))',
                          }}
                        >
                          {STATUS_LABELS[order.status] ?? order.status}
                        </span>
                      </div>

                      {/* Stepper de progresso */}
                      {!isCancelled && (
                        <div className="my-4">
                          <div className="relative flex items-center justify-between px-2">
                            {/* Barra de progresso */}
                            <div className="absolute left-6 right-6 top-3 h-0.5 bg-[var(--hairline)]" />
                            <div
                              className="absolute left-6 top-3 h-0.5 transition-all duration-500"
                              style={{
                                background: 'rgb(var(--brand))',
                                width: `${Math.min(
                                  100,
                                  ((currentStep - 1) / (STEPPER_STAGES.length - 1)) * 100
                                )}%`,
                              }}
                            />

                            {STEPPER_STAGES.map((stage, idx) => {
                              const stepNum = idx + 1;
                              const isCompleted = currentStep > stepNum;
                              const isCurrent = currentStep === stepNum;

                              return (
                                <div
                                  key={stage.key}
                                  className="relative z-10 flex flex-col items-center gap-1.5"
                                >
                                  <div
                                    className={`grid size-6 place-items-center rounded-full text-[10px] font-bold transition-all ${
                                      isCompleted || isCurrent
                                        ? 'scale-110 shadow-sm'
                                        : 'text-subtle'
                                    }`}
                                    style={{
                                      background:
                                        isCompleted || isCurrent
                                          ? 'rgb(var(--brand))'
                                          : 'var(--hairline)',
                                      color:
                                        isCompleted || isCurrent
                                          ? 'rgb(var(--brand-ink))'
                                          : 'inherit',
                                    }}
                                  >
                                    {isCompleted ? '✓' : stepNum}
                                  </div>
                                  <span
                                    className={`text-[10px] font-medium transition-colors ${
                                      isCurrent
                                        ? 'font-bold text-[rgb(var(--ink))]'
                                        : 'text-subtle'
                                    }`}
                                  >
                                    {stage.label}
                                  </span>
                                </div>
                              );
                            })}
                          </div>
                        </div>
                      )}

                      {/* Itens */}
                      <ul className="mt-3 divide-y divide-[var(--hairline)] pt-2 text-xs">
                        {order.items.map((item, idx) => (
                          <li
                            key={idx}
                            className="flex justify-between py-1.5 text-muted"
                          >
                            <span>
                              {item.quantity}× {item.name}
                            </span>
                            <span className="tabular-nums font-medium">
                              {formatMoney(item.totalCents)}
                            </span>
                          </li>
                        ))}
                      </ul>

                      <div className="mt-3 flex items-center justify-between border-t border-[var(--hairline)] pt-2.5 text-xs font-semibold">
                        <span>Total</span>
                        <span className="tabular-nums">
                          {formatMoney(order.totalCents)}
                        </span>
                      </div>
                    </div>
                  );
                })}
              </div>
            )
          ) : historyOrders.length === 0 ? (
            <div className="py-12 text-center">
              <p className="text-sm font-medium">Nenhum histórico de pedidos</p>
              <p className="mt-1 text-xs text-subtle">
                Pedidos concluídos ou cancelados ficarão guardados aqui.
              </p>
            </div>
          ) : (
            <div className="grid gap-3">
              {historyOrders.map((order) => (
                <div
                  key={order.id}
                  className="rounded-xl border border-[var(--hairline)] bg-surface p-3.5 text-xs"
                >
                  <div className="flex items-center justify-between pb-2 border-b border-[var(--hairline)]">
                    <div>
                      <span className="font-bold text-sm">
                        Pedido #{order.number}
                      </span>
                      <p className="text-[11px] text-subtle">
                        {new Date(order.placedAt).toLocaleDateString('pt-BR')} ·{' '}
                        {order.fulfillment === 'delivery' ? 'Entrega' : 'Retirada'}
                      </p>
                    </div>
                    <span
                      className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                        order.status === 'delivered'
                          ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                          : 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300'
                      }`}
                    >
                      {STATUS_LABELS[order.status] ?? order.status}
                    </span>
                  </div>

                  <ul className="my-2 divide-y divide-[var(--hairline)] text-subtle">
                    {order.items.map((item, idx) => (
                      <li key={idx} className="flex justify-between py-1">
                        <span>
                          {item.quantity}× {item.name}
                        </span>
                        <span className="tabular-nums">
                          {formatMoney(item.totalCents)}
                        </span>
                      </li>
                    ))}
                  </ul>

                  <div className="flex justify-between font-bold pt-1.5 border-t border-[var(--hairline)]">
                    <span>Total</span>
                    <span className="tabular-nums">
                      {formatMoney(order.totalCents)}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </dialog>
  );
}
