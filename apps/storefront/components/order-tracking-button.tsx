'use client';

import { useEffect, useState } from 'react';
import { getStoredOrders, type StoredOrder } from '@/lib/orders-storage';
import { OrdersSheet } from './orders-sheet';

export function OrderTrackingButton({ tenantSlug }: { tenantSlug: string }) {
  const [open, setOpen] = useState(false);
  const [activeCount, setActiveCount] = useState(0);

  const checkOrders = () => {
    const list = getStoredOrders(tenantSlug);
    const active = list.filter(
      (o) => o.status !== 'delivered' && o.status !== 'cancelled'
    );
    setActiveCount(active.length);
  };

  useEffect(() => {
    checkOrders();
    const interval = setInterval(checkOrders, 4000);
    return () => clearInterval(interval);
  }, [tenantSlug]);

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="inline-flex items-center gap-2 rounded-xl border border-[var(--hairline)] bg-[rgb(var(--surface))] px-3 py-2 text-xs font-semibold shadow-xs transition-all hover:bg-[var(--hairline)]"
      >
        <svg
          className="size-4 text-[rgb(var(--brand))]"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
          />
        </svg>
        <span>Meus Pedidos</span>
        {activeCount > 0 && (
          <span
            className="grid size-4 place-items-center rounded-full text-[10px] font-bold"
            style={{
              background: 'rgb(var(--brand))',
              color: 'rgb(var(--brand-ink))',
            }}
          >
            {activeCount}
          </span>
        )}
      </button>

      {open && (
        <OrdersSheet tenantSlug={tenantSlug} onClose={() => setOpen(false)} />
      )}
    </>
  );
}
