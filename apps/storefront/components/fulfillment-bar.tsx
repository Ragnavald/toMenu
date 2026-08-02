'use client';

import { useEffect, useRef, useState } from 'react';
import { formatMoney } from '@/lib/api';
import { FULFILLMENT_LABELS, type Fulfillment, type TenantInfo } from '@/lib/types';
import { useFulfillment } from './fulfillment-provider';

/** Ícone por modalidade: o cliente reconhece a linha antes de ler o rótulo. */
function FulfillmentIcon({ kind }: { kind: Fulfillment }) {
  const common = {
    className: 'size-5 shrink-0',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.7,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    viewBox: '0 0 24 24',
    'aria-hidden': true,
  };

  if (kind === 'delivery') {
    return (
      <svg {...common}>
        <circle cx="6" cy="17" r="2.6" />
        <circle cx="18" cy="17" r="2.6" />
        <path d="M8.6 17h6.8M6 14.4 9 7h3.6l3 7.4M12.6 7h3.2l2.2 7.4" />
      </svg>
    );
  }

  if (kind === 'pickup') {
    return (
      <svg {...common}>
        <path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z" />
        <path d="M9.5 21v-6h5v6" />
      </svg>
    );
  }

  return (
    <svg {...common}>
      <path d="M5 3v7a2.5 2.5 0 0 0 5 0V3M7.5 10v11" />
      <path d="M17.5 3c-1.7 1.6-2.5 3.6-2.5 6s.8 3.4 2.5 3.4h1V3z" />
      <path d="M18.5 12.4V21" />
    </svg>
  );
}

/**
 * Seletor de modalidade no topo do cardápio.
 *
 * A escolha aparece antes do carrinho porque muda o que o cliente vê: só
 * entrega cobra taxa, e é o único caso em que o endereço pedido é o dele e não
 * o da loja. Deixar isso para o checkout faria a taxa surgir no fim, depois de
 * montado o pedido.
 *
 * Fica oculto quando a loja oferta uma única modalidade — um seletor com uma
 * opção só é ruído.
 */
export function FulfillmentBar({ tenant }: { tenant: TenantInfo }) {
  const { fulfillment, setFulfillment, options } = useFulfillment();
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;

    function handlePointerDown(event: MouseEvent | TouchEvent) {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false);
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false);
    }

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('touchstart', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('touchstart', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [open]);

  if (options.length < 2) return null;

  const feeCents = tenant.deliveryConfig.fee_cents ?? 0;
  const storeAddress = tenant.address;

  /** Linha de apoio: endereço da loja, ou o rótulo de entrega. */
  function subtitleFor(kind: Fulfillment): string | null {
    return kind === 'delivery' ? 'Receba no seu endereço' : storeAddress;
  }

  return (
    <div className="mx-auto max-w-3xl px-4 pt-5">
      <div ref={containerRef} className="relative">
        <button
          type="button"
          onClick={() => setOpen((current) => !current)}
          aria-expanded={open}
          aria-haspopup="listbox"
          className="flex w-full items-center gap-3 border px-4 py-3 text-left transition-colors"
          style={{
            borderColor: 'var(--hairline)',
            borderRadius: 'calc(var(--radius) * 0.75)',
            background: 'rgb(var(--surface))',
          }}
        >
          <span style={{ color: 'rgb(var(--brand))' }}>
            <FulfillmentIcon kind={fulfillment} />
          </span>

          <span className="min-w-0 flex-1">
            <span className="flex items-center gap-2">
              <span className="text-sm font-semibold">
                {FULFILLMENT_LABELS[fulfillment]}
              </span>
              {fulfillment === 'delivery' && feeCents === 0 && (
                <span
                  className="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                  style={{
                    background: 'rgb(var(--brand-soft))',
                    color: 'rgb(var(--brand))',
                  }}
                >
                  Grátis
                </span>
              )}
            </span>
            {subtitleFor(fulfillment) && (
              <span className="mt-0.5 block truncate text-xs text-muted">
                {subtitleFor(fulfillment)}
              </span>
            )}
          </span>

          <svg
            className={`size-4 shrink-0 text-muted transition-transform ${open ? 'rotate-180' : ''}`}
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            viewBox="0 0 24 24"
            aria-hidden
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="m6 9 6 6 6-6" />
          </svg>
        </button>

        {open && (
          <div
            role="listbox"
            aria-label="Como você quer receber"
            className="absolute inset-x-0 top-full z-40 mt-2 border p-2 shadow-soft"
            style={{
              borderColor: 'var(--hairline)',
              borderRadius: 'calc(var(--radius) * 0.75)',
              background: 'rgb(var(--surface))',
            }}
          >
            <p className="px-2 pb-1.5 pt-1 text-[11px] font-semibold uppercase tracking-wide text-muted">
              Como você quer receber
            </p>

            {options.map((option) => {
              const isActive = option === fulfillment;

              return (
                <button
                  key={option}
                  type="button"
                  role="option"
                  aria-selected={isActive}
                  onClick={() => {
                    setFulfillment(option);
                    setOpen(false);
                  }}
                  className="flex w-full items-center gap-3 px-2 py-2.5 text-left transition-colors"
                  style={{
                    borderRadius: 'calc(var(--radius) * 0.55)',
                    background: isActive ? 'rgb(var(--brand-soft) / 0.5)' : 'transparent',
                  }}
                >
                  <span style={{ color: 'rgb(var(--brand))' }}>
                    <FulfillmentIcon kind={option} />
                  </span>

                  <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-2">
                      <span className="text-sm font-semibold">
                        {FULFILLMENT_LABELS[option]}
                      </span>
                      {option === 'delivery' && (
                        <span
                          className="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                          style={{
                            background: 'rgb(var(--brand-soft))',
                            color: 'rgb(var(--brand))',
                          }}
                        >
                          {feeCents === 0 ? 'Grátis' : formatMoney(feeCents)}
                        </span>
                      )}
                    </span>
                    {subtitleFor(option) && (
                      <span className="mt-0.5 block truncate text-xs text-muted">
                        {subtitleFor(option)}
                      </span>
                    )}
                  </span>

                  <span
                    aria-hidden
                    className="grid size-5 shrink-0 place-items-center rounded-full border-2"
                    style={{
                      borderColor: isActive
                        ? 'rgb(var(--brand))'
                        : 'var(--hairline)',
                    }}
                  >
                    {isActive && (
                      <span
                        className="size-2.5 rounded-full"
                        style={{ background: 'rgb(var(--brand))' }}
                      />
                    )}
                  </span>
                </button>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
