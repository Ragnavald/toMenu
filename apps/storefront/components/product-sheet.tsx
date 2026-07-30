'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { formatMoney } from '@/lib/api';
import type { Modifier, Product } from '@/lib/types';
import { useCart } from './cart-provider';

/**
 * Folha de detalhe do produto.
 *
 * Renderiza como <dialog> nativo para herdar do navegador o comportamento de
 * modal: foco preso dentro da caixa, fechamento por Esc e inerte no conteúdo
 * de trás. Reimplementar isso em React costuma deixar buracos de acessibilidade.
 */
export function ProductSheet({
  product,
  onClose,
}: {
  product: Product;
  onClose: () => void;
}) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const { add } = useCart();
  const [selected, setSelected] = useState<Record<number, number[]>>({});
  const [quantity, setQuantity] = useState(1);
  const [showErrors, setShowErrors] = useState(false);

  useEffect(() => {
    dialogRef.current?.showModal();
  }, []);

  const chosen = useMemo<Modifier[]>(() => {
    return product.modifierGroups.flatMap((group) =>
      group.modifiers.filter((modifier) =>
        (selected[group.id] ?? []).includes(modifier.id),
      ),
    );
  }, [product.modifierGroups, selected]);

  // Grupos obrigatórios que ainda não atingiram o mínimo de escolhas.
  const missing = product.modifierGroups.filter(
    (group) => (selected[group.id] ?? []).length < group.minSelect,
  );

  const unitPrice =
    (product.promoPriceCents ?? product.priceCents) +
    chosen.reduce((sum, modifier) => sum + modifier.priceDeltaCents, 0);

  function toggle(groupId: number, modifierId: number, maxSelect: number) {
    setSelected((current) => {
      const list = current[groupId] ?? [];

      if (list.includes(modifierId)) {
        return { ...current, [groupId]: list.filter((id) => id !== modifierId) };
      }

      // Grupo de escolha única: a nova seleção substitui a anterior.
      if (maxSelect === 1) return { ...current, [groupId]: [modifierId] };

      if (list.length >= maxSelect) return current;

      return { ...current, [groupId]: [...list, modifierId] };
    });
  }

  function handleAdd() {
    if (missing.length > 0) {
      setShowErrors(true);
      return;
    }

    add(product, chosen, quantity);
    dialogRef.current?.close();
    onClose();
  }

  return (
    <dialog
      ref={dialogRef}
      onClose={onClose}
      onClick={(event) => {
        // Clique no backdrop (fora do conteúdo) fecha a folha.
        if (event.target === dialogRef.current) {
          dialogRef.current?.close();
        }
      }}
      className="m-0 max-h-[92dvh] w-full max-w-lg self-end bg-transparent p-0 backdrop:bg-black/45 sm:m-auto sm:self-center"
    >
      <div
        className="animate-slide-up flex max-h-[92dvh] flex-col overflow-hidden bg-[rgb(var(--surface))] text-[rgb(var(--ink))] sm:animate-rise"
        style={{
          borderTopLeftRadius: 'var(--radius)',
          borderTopRightRadius: 'var(--radius)',
          borderBottomLeftRadius: 'var(--radius)',
          borderBottomRightRadius: 'var(--radius)',
        }}
      >
        <div className="overflow-y-auto">
          {product.imageUrl && (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={product.imageUrl}
              alt=""
              className="h-52 w-full object-cover"
            />
          )}

          <div className="p-5">
            <h2 className="text-xl font-semibold tracking-tight">
              {product.name}
            </h2>

            {product.description && (
              <p className="mt-1.5 text-sm leading-relaxed text-muted">
                {product.description}
              </p>
            )}

            {product.modifierGroups.map((group) => {
              const list = selected[group.id] ?? [];
              const isMissing = showErrors && list.length < group.minSelect;

              return (
                <fieldset key={group.id} className="mt-6">
                  <legend className="flex w-full items-center justify-between gap-3 pb-2">
                    <span className="text-sm font-semibold">{group.name}</span>

                    <span
                      className="text-xs font-medium"
                      style={{
                        color: isMissing ? '#dc2626' : 'var(--ink-subtle)',
                      }}
                    >
                      {group.isRequired ? 'Obrigatório' : 'Opcional'}
                      {group.maxSelect > 1 && ` · até ${group.maxSelect}`}
                    </span>
                  </legend>

                  <div className="grid gap-1.5">
                    {group.modifiers.map((modifier) => {
                      const isChecked = list.includes(modifier.id);

                      return (
                        <label
                          key={modifier.id}
                          className="flex cursor-pointer items-center gap-3 border p-3 text-sm transition-colors"
                          style={{
                            borderRadius: 'calc(var(--radius) * 0.6)',
                            borderColor: isChecked
                              ? 'rgb(var(--brand))'
                              : 'var(--hairline)',
                            background: isChecked
                              ? 'rgb(var(--brand-soft) / 0.6)'
                              : 'transparent',
                          }}
                        >
                          <input
                            type={group.maxSelect === 1 ? 'radio' : 'checkbox'}
                            name={`group-${group.id}`}
                            checked={isChecked}
                            onChange={() =>
                              toggle(group.id, modifier.id, group.maxSelect)
                            }
                            className="size-4 shrink-0 accent-[rgb(var(--brand))]"
                          />

                          <span className="flex-1">{modifier.name}</span>

                          {modifier.priceDeltaCents !== 0 && (
                            <span className="tabular-nums text-muted">
                              {modifier.priceDeltaCents > 0 ? '+' : '−'}
                              {formatMoney(Math.abs(modifier.priceDeltaCents))}
                            </span>
                          )}
                        </label>
                      );
                    })}
                  </div>
                </fieldset>
              );
            })}
          </div>
        </div>

        <div className="flex items-center gap-3 border-t border-[var(--hairline)] bg-[rgb(var(--surface))] p-4">
          <div
            className="flex items-center gap-1 border"
            style={{
              borderRadius: 'calc(var(--radius) * 0.6)',
              borderColor: 'var(--hairline)',
            }}
          >
            <button
              type="button"
              onClick={() => setQuantity((q) => Math.max(1, q - 1))}
              aria-label="Diminuir quantidade"
              className="grid size-10 place-items-center text-lg text-muted disabled:opacity-40"
              disabled={quantity <= 1}
            >
              −
            </button>
            <span className="w-6 text-center text-sm font-semibold tabular-nums">
              {quantity}
            </span>
            <button
              type="button"
              onClick={() => setQuantity((q) => Math.min(99, q + 1))}
              aria-label="Aumentar quantidade"
              className="grid size-10 place-items-center text-lg text-muted"
            >
              +
            </button>
          </div>

          <button
            type="button"
            onClick={handleAdd}
            className="flex flex-1 items-center justify-center gap-2 px-4 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90"
            style={{
              background: 'rgb(var(--brand))',
              borderRadius: 'calc(var(--radius) * 0.6)',
            }}
          >
            <span>Adicionar</span>
            <span className="tabular-nums opacity-90">
              {formatMoney(unitPrice * quantity)}
            </span>
          </button>
        </div>
      </div>
    </dialog>
  );
}
