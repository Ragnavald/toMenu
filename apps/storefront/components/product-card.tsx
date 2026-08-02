'use client';

import { formatMoney } from '@/lib/api';
import { displayPrice, type Product } from '@/lib/types';

export function ProductCard({
  product,
  onSelect,
  layout = 'classic',
}: {
  product: Product;
  onSelect: () => void;
  layout?: string;
}) {
  const { cents: price, from } = displayPrice(product);

  // Promoção riscada não faz sentido junto de "a partir de": o valor cheio ali
  // seria o do produto (zero, num formato de pizza), não o do sabor.
  const hasPromo = product.promoPriceCents != null && !from;

  if (layout === 'grid') {
    return (
      <button
        type="button"
        onClick={onSelect}
        className="group flex h-full w-full flex-col justify-between p-3 text-left transition-all duration-200 hover:-translate-y-px surface-card hover:shadow-soft"
      >
        <div className="w-full">
          {product.imageUrl ? (
            <div
              className="mb-2.5 aspect-square w-full overflow-hidden"
              style={{ borderRadius: 'calc(var(--radius) * 0.6)' }}
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
            <div
              aria-hidden
              className="mb-2.5 grid aspect-square w-full place-items-center"
              style={{
                borderRadius: 'calc(var(--radius) * 0.6)',
                background: 'rgb(var(--brand-soft))',
                color: 'rgb(var(--brand) / 0.55)',
              }}
            >
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden>
                <path
                  d="M4 7h16M4 12h16M4 17h10"
                  stroke="currentColor"
                  strokeWidth="1.6"
                  strokeLinecap="round"
                />
              </svg>
            </div>
          )}

          <h3 className="font-medium text-sm leading-snug line-clamp-2">{product.name}</h3>

          {product.description && (
            <p className="mt-1 line-clamp-2 text-xs leading-relaxed text-muted">
              {product.description}
            </p>
          )}
        </div>

        <p className="mt-2 flex items-baseline gap-1.5">
          {from && <span className="text-[11px] text-muted">a partir de</span>}

          <span
            className="font-semibold text-sm tabular-nums"
            style={{ color: hasPromo ? 'rgb(var(--brand))' : undefined }}
          >
            {formatMoney(price)}
          </span>

          {hasPromo && (
            <span className="text-xs tabular-nums text-subtle line-through">
              {formatMoney(product.priceCents)}
            </span>
          )}
        </p>
      </button>
    );
  }

  if (layout === 'compact') {
    return (
      <button
        type="button"
        onClick={onSelect}
        className="group flex w-full items-center justify-between gap-3 px-3.5 py-2.5 text-left transition-all duration-200 hover:-translate-y-px surface-card hover:shadow-soft"
      >
        <div className="min-w-0 flex-1">
          <h3 className="font-medium text-sm leading-snug truncate">{product.name}</h3>
          {product.description && (
            <p className="line-clamp-1 text-xs text-muted">
              {product.description}
            </p>
          )}
        </div>

        <div className="flex items-center gap-2 shrink-0">
          <p className="flex items-baseline gap-1.5">
            {from && <span className="text-[11px] text-muted">a partir de</span>}

            <span
              className="font-semibold text-sm tabular-nums"
              style={{ color: hasPromo ? 'rgb(var(--brand))' : undefined }}
            >
              {formatMoney(price)}
            </span>

            {hasPromo && (
              <span className="text-xs tabular-nums text-subtle line-through">
                {formatMoney(product.priceCents)}
              </span>
            )}
          </p>
        </div>
      </button>
    );
  }

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
          {from && <span className="text-xs text-muted">a partir de</span>}

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
