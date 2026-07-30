'use client';

import { formatMoney } from '@/lib/api';
import type { Product } from '@/lib/types';

export function ProductCard({
  product,
  onSelect,
}: {
  product: Product;
  onSelect: () => void;
}) {
  const hasPromo = product.promoPriceCents != null;
  const price = product.promoPriceCents ?? product.priceCents;

  return (
    <button
      type="button"
      onClick={onSelect}
      className="group flex w-full items-start gap-4 p-3.5 text-left transition-all duration-200 hover:-translate-y-px surface-card hover:shadow-soft"
    >
      <div className="min-w-0 flex-1">
        <h3 className="font-medium leading-snug">{product.name}</h3>

        {product.description && (
          <p className="mt-1 line-clamp-2 text-sm leading-relaxed text-muted">
            {product.description}
          </p>
        )}

        <p className="mt-2.5 flex items-baseline gap-2">
          <span
            className="font-semibold tabular-nums"
            style={{ color: hasPromo ? 'rgb(var(--brand))' : undefined }}
          >
            {formatMoney(price)}
          </span>

          {hasPromo && (
            <span className="text-sm tabular-nums text-subtle line-through">
              {formatMoney(product.priceCents)}
            </span>
          )}
        </p>
      </div>

      {product.imageUrl ? (
        <div
          className="size-24 shrink-0 overflow-hidden"
          style={{ borderRadius: 'calc(var(--radius) * 0.7)' }}
        >
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={product.imageUrl}
            alt=""
            loading="lazy"
            className="size-full object-cover transition-transform duration-300 group-hover:scale-105"
          />
        </div>
      ) : (
        // Placeholder na cor da marca mantém o ritmo visual da lista quando
        // o tenant ainda não subiu fotos — situação comum no onboarding.
        <div
          aria-hidden
          className="grid size-24 shrink-0 place-items-center"
          style={{
            borderRadius: 'calc(var(--radius) * 0.7)',
            background: 'rgb(var(--brand-soft))',
            color: 'rgb(var(--brand) / 0.55)',
          }}
        >
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" aria-hidden>
            <path
              d="M4 7h16M4 12h16M4 17h10"
              stroke="currentColor"
              strokeWidth="1.6"
              strokeLinecap="round"
            />
          </svg>
        </div>
      )}
    </button>
  );
}
