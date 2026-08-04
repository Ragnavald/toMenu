'use client';

import { useState } from 'react';
import { formatMoney } from '@/lib/api';
import type { TenantInfo } from '@/lib/types';
import { useCart } from './cart-provider';
import { CheckoutSheet } from './checkout-sheet';

/** Barra flutuante com o resumo do carrinho; só aparece quando há itens. */
export function CartBar({
  tenant,
  tenantSlug,
}: {
  tenant: TenantInfo;
  tenantSlug: string;
}) {
  const { itemCount, subtotalCents } = useCart();
  const [open, setOpen] = useState(false);

  const minimum = tenant.deliveryConfig.min_order_cents ?? 0;
  const belowMinimum = subtotalCents < minimum;

  return (
    <>
      {/*
        A barra some com o carrinho vazio, mas o checkout NÃO pode sumir junto:
        o sucesso do pedido zera o carrinho e a tela "Pedido confirmado" vive
        dentro do modal. Desmontá-lo aqui também engoliria o `onClose` do
        <dialog>, deixando `open` preso em true — e o pedido seguinte abriria o
        modal já montado, com o endereço antigo preenchido e a etapa de revisão
        efetivamente pulada.
      */}
      {itemCount > 0 && (
        <div className="fixed inset-x-0 bottom-0 z-30 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <div className="mx-auto max-w-3xl">
            {belowMinimum && (
              <p
                className="mb-2 px-4 py-2 text-center text-xs font-medium shadow-soft"
                style={{
                  background: 'rgb(var(--brand-soft))',
                  color: 'rgb(var(--brand))',
                  borderRadius: 'calc(var(--radius) * 0.6)',
                }}
              >
                Faltam {formatMoney(minimum - subtotalCents)} para o pedido
                mínimo
              </p>
            )}

            <button
              type="button"
              onClick={() => setOpen(true)}
              disabled={belowMinimum}
              className="animate-rise flex w-full items-center gap-3 px-4 py-3.5 shadow-soft transition-opacity hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-55"
              style={{
                background: 'rgb(var(--brand))',
                color: 'rgb(var(--brand-ink))',
                borderRadius: 'var(--radius)',
              }}
            >
              <span
                className="grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold tabular-nums"
                style={{ background: 'rgb(var(--brand-ink) / 0.22)' }}
              >
                {itemCount}
              </span>

              <span className="flex-1 text-left text-sm font-semibold">
                Ver carrinho
              </span>

              <span className="text-sm font-semibold tabular-nums">
                {formatMoney(subtotalCents)}
              </span>
            </button>
          </div>
        </div>
      )}

      {open && (
        <CheckoutSheet
          tenant={tenant}
          tenantSlug={tenantSlug}
          onClose={() => setOpen(false)}
        />
      )}
    </>
  );
}
