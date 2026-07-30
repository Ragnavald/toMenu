'use client';

import { useEffect, useRef, useState } from 'react';
import type { Category, Product } from '@/lib/types';
import { ProductCard } from './product-card';
import { ProductSheet } from './product-sheet';

/**
 * Lista de categorias com navegação por âncora.
 *
 * A trilha superior destaca a seção visível usando IntersectionObserver em vez
 * de listener de scroll: o navegador faz o cálculo fora da main thread, o que
 * mantém a rolagem fluida em aparelhos modestos — a maior parte do tráfego de
 * um cardápio vem de celular.
 */
export function MenuBrowser({
  categories,
  layout = 'classic',
}: {
  categories: Category[];
  layout?: string;
}) {
  const [activeId, setActiveId] = useState<number | null>(
    categories[0]?.id ?? null,
  );
  const [searchQuery, setSearchQuery] = useState('');
  const [selected, setSelected] = useState<Product | null>(null);
  const sectionRefs = useRef(new Map<number, HTMLElement>());

  const gridClass =
    layout === 'grid'
      ? 'grid grid-cols-2 gap-3 sm:grid-cols-2'
      : layout === 'compact'
      ? 'grid gap-2'
      : 'grid gap-3';

  const normalizedQuery = searchQuery
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');

  const filteredCategories = categories
    .map((category) => {
      if (!normalizedQuery) return category;

      const matchingProducts = category.products.filter((product) => {
        const nameNorm = product.name
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '');
        const descNorm = (product.description || '')
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '');

        return nameNorm.includes(normalizedQuery) || descNorm.includes(normalizedQuery);
      });

      return { ...category, products: matchingProducts };
    })
    .filter((category) => category.products.length > 0);

  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries
          .filter((entry) => entry.isIntersecting)
          .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];

        if (visible) {
          setActiveId(Number(visible.target.getAttribute('data-category-id')));
        }
      },
      // Margem superior compensa a trilha fixa; a inferior evita que a última
      // seção curta nunca seja marcada como ativa.
      { rootMargin: '-96px 0px -55% 0px', threshold: 0 },
    );

    sectionRefs.current.forEach((element) => observer.observe(element));

    return () => observer.disconnect();
  }, [filteredCategories]);

  if (categories.length === 0) {
    return (
      <p className="mx-auto max-w-3xl px-4 py-16 text-center text-muted">
        Este cardápio ainda não tem itens publicados.
      </p>
    );
  }

  return (
    <>
      {/* Campo de Busca no Cardápio */}
      <div className="mx-auto max-w-3xl px-4 pt-5">
        <div className="relative">
          <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
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
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder="O que você está procurando hoje?"
            className="w-full rounded-xl border border-[var(--hairline)] bg-[rgb(var(--surface))] py-2.5 pl-10 pr-10 text-sm text-[rgb(var(--ink))] shadow-sm transition-all placeholder:text-muted focus:border-[rgb(var(--brand))] focus:outline-none"
          />
          {searchQuery && (
            <button
              type="button"
              onClick={() => setSearchQuery('')}
              className="absolute inset-y-0 right-0 flex items-center pr-3.5 text-muted hover:text-[rgb(var(--ink))]"
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

      {filteredCategories.length > 0 && (
        <nav
          aria-label="Categorias"
          className="sticky top-0 z-20 mt-4 border-b border-[var(--hairline)] bg-[rgb(var(--surface))]/85 backdrop-blur-md"
        >
          <ul className="no-scrollbar mx-auto flex max-w-3xl gap-1 overflow-x-auto px-3 py-2.5">
            {filteredCategories.map((category) => {
              const isActive = category.id === activeId;
              const anchorId = category.slug || `categoria-${category.id}`;

              return (
                <li key={category.id}>
                  <a
                    href={`#${anchorId}`}
                    aria-current={isActive ? 'true' : undefined}
                    className="block whitespace-nowrap px-3.5 py-1.5 text-sm font-medium transition-colors"
                    style={{
                      borderRadius: 'calc(var(--radius) * 0.6)',
                      background: isActive ? 'rgb(var(--brand-soft))' : 'transparent',
                      color: isActive ? 'rgb(var(--brand))' : 'var(--ink-muted)',
                    }}
                  >
                    {category.name}
                  </a>
                </li>
              );
            })}
          </ul>
        </nav>
      )}

      <main className="mx-auto max-w-3xl px-4 pb-40">
        {filteredCategories.length === 0 ? (
          <div className="py-16 text-center">
            <p className="text-base font-semibold">Nenhum produto encontrado</p>
            <p className="mt-1 text-sm text-muted">
              Não encontramos resultados para &quot;{searchQuery}&quot;.
            </p>
            <button
              type="button"
              onClick={() => setSearchQuery('')}
              className="mt-4 inline-flex items-center rounded-xl px-4 py-2 text-sm font-medium transition-opacity hover:opacity-90"
              style={{
                background: 'rgb(var(--brand-soft))',
                color: 'rgb(var(--brand))',
              }}
            >
              Limpar busca
            </button>
          </div>
        ) : (
          filteredCategories.map((category) => {
            const anchorId = category.slug || `categoria-${category.id}`;

            return (
              <section
                key={category.id}
                id={anchorId}
                data-category-id={category.id}
                ref={(element) => {
                  if (element) sectionRefs.current.set(category.id, element);
                  else sectionRefs.current.delete(category.id);
                }}
                className="scroll-mt-20 pt-7"
              >
                <h2 className="mb-4 text-lg font-semibold tracking-tight">
                  {category.name}
                </h2>

                <ul className={gridClass}>
                  {category.products.map((product) => (
                    <li key={product.id} className={layout === 'grid' ? 'h-full' : undefined}>
                      <ProductCard
                        product={product}
                        layout={layout}
                        onSelect={() => setSelected(product)}
                      />
                    </li>
                  ))}
                </ul>
              </section>
            );
          })
        )}
      </main>

      {selected && (
        <ProductSheet product={selected} onClose={() => setSelected(null)} />
      )}
    </>
  );
}
