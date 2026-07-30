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
export function MenuBrowser({ categories }: { categories: Category[] }) {
  const [activeId, setActiveId] = useState<number | null>(
    categories[0]?.id ?? null,
  );
  const [selected, setSelected] = useState<Product | null>(null);
  const sectionRefs = useRef(new Map<number, HTMLElement>());

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
  }, [categories]);

  if (categories.length === 0) {
    return (
      <p className="mx-auto max-w-3xl px-4 py-16 text-center text-muted">
        Este cardápio ainda não tem itens publicados.
      </p>
    );
  }

  return (
    <>
      <nav
        aria-label="Categorias"
        className="sticky top-0 z-20 mt-6 border-b border-[var(--hairline)] bg-[rgb(var(--surface))]/85 backdrop-blur-md"
      >
        <ul className="no-scrollbar mx-auto flex max-w-3xl gap-1 overflow-x-auto px-3 py-2.5">
          {categories.map((category) => {
            const isActive = category.id === activeId;

            return (
              <li key={category.id}>
                <a
                  href={`#categoria-${category.id}`}
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

      <main className="mx-auto max-w-3xl px-4 pb-40">
        {categories.map((category) => (
          <section
            key={category.id}
            id={`categoria-${category.id}`}
            data-category-id={category.id}
            ref={(element) => {
              if (element) sectionRefs.current.set(category.id, element);
              else sectionRefs.current.delete(category.id);
            }}
            className="scroll-mt-20 pt-9"
          >
            <h2 className="mb-4 text-lg font-semibold tracking-tight">
              {category.name}
            </h2>

            <ul className="grid gap-3">
              {category.products.map((product) => (
                <li key={product.id}>
                  <ProductCard
                    product={product}
                    onSelect={() => setSelected(product)}
                  />
                </li>
              ))}
            </ul>
          </section>
        ))}
      </main>

      {selected && (
        <ProductSheet product={selected} onClose={() => setSelected(null)} />
      )}
    </>
  );
}
