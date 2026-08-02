'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { formatMoney } from '@/lib/api';
import { displayPrice, priceGroup, type Product } from '@/lib/types';
import { useCart, type Selection } from './cart-provider';

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
  acceptsOrders = true,
}: {
  product: Product;
  onClose: () => void;
  /**
   * Vitrine (false): sem rodapé de quantidade e "Adicionar". Os grupos de
   * opções continuam visíveis — eles explicam o que o prato tem e quanto cada
   * adicional custa, e isso é informação de cardápio, não de pedido.
   */
  acceptsOrders?: boolean;
}) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const { add } = useCart();
  const [selected, setSelected] = useState<Record<number, number[]>>({});
  const [quantity, setQuantity] = useState(1);
  const [showErrors, setShowErrors] = useState(false);

  useEffect(() => {
    dialogRef.current?.showModal();
  }, []);

  // Escolhas mantidas por grupo: a regra de preço do meio a meio ('highest')
  // só existe dentro de um grupo, então achatar antes de precificar somaria
  // dois sabores inteiros.
  const selections = useMemo<Selection[]>(() => {
    return product.modifierGroups.map((group) => ({
      groupId: group.id,
      modifiers: group.modifiers.filter((modifier) =>
        (selected[group.id] ?? []).includes(modifier.id),
      ),
    }));
  }, [product.modifierGroups, selected]);

  // Grupos obrigatórios que ainda não atingiram o mínimo de escolhas.
  const missing = product.modifierGroups.filter(
    (group) => (selected[group.id] ?? []).length < group.minSelect,
  );

  // Mesma conta do cartão do cardápio: na vitrine (sem pedido) o rodapé mostra
  // "a partir de" em vez do preço do formato, que é zero.
  const showcasePrice = useMemo(() => displayPrice(product), [product]);

  const unitPrice = useMemo(() => {
    const base = product.promoPriceCents ?? product.priceCents;

    return selections.reduce((total, selection) => {
      const group = product.modifierGroups.find((g) => g.id === selection.groupId);

      return group ? total + priceGroup(group, selection.modifiers) : total;
    }, base);
  }, [product, selections]);

  function toggle(groupId: number, modifierId: number, maxSelect: number) {
    setSelected((current) => {
      const list = current[groupId] ?? [];

      if (list.includes(modifierId)) {
        return { ...current, [groupId]: list.filter((id) => id !== modifierId) };
      }

      // Grupo de escolha única: a nova seleção substitui a anterior.
      if (maxSelect === 1) return { ...current, [groupId]: [modifierId] };

      /*
       * Grupo cheio: a escolha nova empurra a mais antiga para fora.
       *
       * Ignorar o clique — o comportamento anterior — deixa o cliente preso no
       * meio a meio: com dois sabores marcados, todo clique num terceiro não
       * faz nada visível, e não é óbvio que é preciso desmarcar antes. Rodar a
       * fila mantém a escolha sempre possível.
       */
      if (list.length >= maxSelect) {
        return { ...current, [groupId]: [...list.slice(1), modifierId] };
      }

      return { ...current, [groupId]: [...list, modifierId] };
    });
  }

  function handleAdd() {
    if (missing.length > 0) {
      setShowErrors(true);
      return;
    }

    add(product, selections, quantity);
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
              const isComposed = group.source === 'category';

              return (
                <fieldset key={group.id} className="mt-6">
                  <legend className="flex w-full items-center justify-between gap-3 pb-2">
                    <span className="text-sm font-semibold">{group.name}</span>

                    <span
                      className="text-xs font-medium tabular-nums"
                      style={{
                        color: isMissing ? '#dc2626' : 'var(--ink-subtle)',
                      }}
                    >
                      {/*
                        Grupo de múltipla escolha mostra o progresso ("1 de 2")
                        em vez de "até 2": no meio a meio o cliente precisa
                        saber quantas metades ainda faltam, e um rótulo estático
                        não responde isso.
                      */}
                      {group.maxSelect > 1
                        ? `${list.length} de ${group.maxSelect}`
                        : group.isRequired
                          ? 'Obrigatório'
                          : 'Opcional'}
                    </span>
                  </legend>

                  {/*
                    No grupo composto com mais de uma escolha, cada opção é uma
                    fração da pizza. Dizer isso explicitamente evita a leitura
                    de que o cliente está pedindo duas pizzas inteiras — a
                    dúvida mais comum nessa tela.
                  */}
                  {isComposed && group.maxSelect > 1 && (
                    <p className="pb-2 text-xs text-muted">
                      Cada sabor ocupa 1/{group.maxSelect} da pizza. Cobramos o
                      valor do sabor mais caro.
                    </p>
                  )}

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

                          {/* Sabor vem de um produto e tem foto própria. */}
                          {modifier.imageUrl && (
                            // eslint-disable-next-line @next/next/no-img-element
                            <img
                              src={modifier.imageUrl}
                              alt=""
                              className="size-11 shrink-0 rounded object-cover"
                            />
                          )}

                          <span className="flex-1">
                            <span className="block">{modifier.name}</span>

                            {modifier.description && (
                              <span className="mt-0.5 block text-xs leading-snug text-muted">
                                {modifier.description}
                              </span>
                            )}
                          </span>

                          {isComposed ? (
                            // Preço cheio do sabor, não um acréscimo: exibir
                            // "+R$ 72,00" ao lado de uma metade sugeriria que
                            // o valor soma ao da pizza.
                            <span className="shrink-0 tabular-nums text-muted">
                              {formatMoney(modifier.priceDeltaCents)}
                            </span>
                          ) : (
                            modifier.priceDeltaCents !== 0 && (
                              <span className="shrink-0 tabular-nums text-muted">
                                {modifier.priceDeltaCents > 0 ? '+' : '−'}
                                {formatMoney(Math.abs(modifier.priceDeltaCents))}
                              </span>
                            )
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

        {!acceptsOrders && (
          <div className="flex items-baseline justify-between gap-3 border-t border-[var(--hairline)] bg-[rgb(var(--surface))] p-4">
            <span className="text-sm text-muted">
              {showcasePrice.from ? 'A partir de' : 'Preço'}
            </span>
            <span className="text-lg font-semibold tabular-nums">
              {formatMoney(showcasePrice.cents)}
            </span>
          </div>
        )}

        {acceptsOrders && (
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
            className="flex flex-1 items-center justify-center gap-2 px-4 py-3 text-sm font-semibold transition-opacity hover:opacity-90"
            style={{
              background: 'rgb(var(--brand))',
              color: 'rgb(var(--brand-ink))',
              borderRadius: 'calc(var(--radius) * 0.6)',
            }}
          >
            <span>Adicionar</span>
            <span className="tabular-nums opacity-90">
              {formatMoney(unitPrice * quantity)}
            </span>
          </button>
        </div>
        )}
      </div>
    </dialog>
  );
}
