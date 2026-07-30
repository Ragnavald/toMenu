import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch, formatMoney } from '@/lib/api';
import type { Category, Paginated, Product } from '@/lib/types';
import {
  EmptyState,
  Field,
  MoneyInput,
  PageHeader,
  Toggle,
} from '@/components/ui';

type Draft = {
  id?: number;
  category_id: number | null;
  name: string;
  description: string;
  priceCents: number | null;
  promoPriceCents: number | null;
  imageUrl: string;
  is_available: boolean;
};

function emptyDraft(categoryId: number | null): Draft {
  return {
    category_id: categoryId,
    name: '',
    description: '',
    priceCents: null,
    promoPriceCents: null,
    imageUrl: '',
    is_available: true,
  };
}

export function MenuPage() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [draft, setDraft] = useState<Draft | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const { data: categoriesData } = useQuery({
    queryKey: ['categories'],
    queryFn: () => apiFetch<{ data: Category[] }>('/admin/categories'),
  });

  const { data: productsData, isLoading } = useQuery({
    queryKey: ['products', search],
    queryFn: () =>
      apiFetch<Paginated<Product>>(
        `/admin/products?per_page=200${search ? `&search=${encodeURIComponent(search)}` : ''}`,
      ),
    staleTime: 30_000,
  });

  const categories = categoriesData?.data ?? [];
  const products = productsData?.data ?? [];

  // Agrupa por seção para espelhar o que o cliente final enxerga no cardápio.
  const grouped = useMemo(() => {
    return categories.map((category) => ({
      category,
      items: products.filter((product) => product.category_id === category.id),
    }));
  }, [categories, products]);

  const save = useMutation({
    mutationFn: (input: Draft) => {
      const body = JSON.stringify({
        category_id: input.category_id,
        name: input.name,
        description: input.description || null,
        price_cents: input.priceCents ?? 0,
        promo_price_cents: input.promoPriceCents,
        image_url: input.imageUrl || null,
        is_available: input.is_available,
      });

      return input.id
        ? apiFetch(`/admin/products/${input.id}`, { method: 'PUT', body })
        : apiFetch('/admin/products', { method: 'POST', body });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] });
      queryClient.invalidateQueries({ queryKey: ['categories'] });
      setDraft(null);
      setFormError(null);
    },
    onError: (caught) => {
      setFormError(
        caught instanceof ApiError
          ? Object.values(caught.errors ?? {}).flat().join(' ') || caught.message
          : 'Não foi possível salvar o item.',
      );
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) =>
      apiFetch(`/admin/products/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] });
      queryClient.invalidateQueries({ queryKey: ['categories'] });
    },
  });

  const toggleAvailability = useMutation({
    mutationFn: (product: Product) =>
      apiFetch(`/admin/products/${product.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          category_id: product.category_id,
          name: product.name,
          description: product.description,
          price_cents: product.price_cents,
          promo_price_cents: product.promo_price_cents,
          is_available: !product.is_available,
        }),
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['products'] }),
  });

  if (categories.length === 0 && !isLoading) {
    return (
      <div>
        <PageHeader title="Cardápio" />
        <EmptyState
          title="Crie uma seção primeiro"
          description="Os itens do cardápio ficam dentro de seções como Entradas, Pratos principais ou Bebidas."
          action={
            <Link
              to="/categorias"
              className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white"
            >
              Criar seções
            </Link>
          }
        />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Cardápio"
        description="Os itens aparecem na loja na ordem das seções."
        action={
          <button
            type="button"
            onClick={() => {
              setDraft(emptyDraft(categories[0]?.id ?? null));
              setFormError(null);
            }}
            className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90"
          >
            Novo item
          </button>
        }
      />

      <input
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        placeholder="Buscar item…"
        aria-label="Buscar item"
        className="field mb-4"
      />

      {draft && (
        <form
          onSubmit={(event) => {
            event.preventDefault();
            save.mutate(draft);
          }}
          className="panel mb-4 grid gap-3.5 p-4"
        >
          <p className="text-sm font-semibold">
            {draft.id ? 'Editar item' : 'Novo item'}
          </p>

          <div className="grid gap-3.5 sm:grid-cols-2">
            <Field label="Nome">
              <input
                required
                autoFocus
                value={draft.name}
                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                className="field"
                placeholder="Pizza Margherita"
              />
            </Field>

            <Field label="Seção">
              <select
                required
                value={draft.category_id ?? ''}
                onChange={(e) =>
                  setDraft({ ...draft, category_id: Number(e.target.value) })
                }
                className="field"
              >
                {categories.map((category) => (
                  <option key={category.id} value={category.id}>
                    {category.name}
                  </option>
                ))}
              </select>
            </Field>
          </div>

          <Field label="Descrição">
            <textarea
              value={draft.description}
              onChange={(e) =>
                setDraft({ ...draft, description: e.target.value })
              }
              className="field min-h-20 resize-none"
              placeholder="Molho de tomate, mozzarella de búfala e manjericão."
            />
          </Field>

          <div className="grid gap-3.5 sm:grid-cols-2">
            <Field label="Preço">
              <MoneyInput
                valueCents={draft.priceCents}
                onChange={(cents) => setDraft({ ...draft, priceCents: cents })}
              />
            </Field>

            <Field
              label="Preço promocional"
              hint="Opcional. Precisa ser menor que o preço normal."
            >
              <MoneyInput
                valueCents={draft.promoPriceCents}
                onChange={(cents) =>
                  setDraft({ ...draft, promoPriceCents: cents })
                }
              />
            </Field>
          </div>

          <Field label="Foto (URL)" hint="Opcional.">
            <input
              value={draft.imageUrl}
              onChange={(e) => setDraft({ ...draft, imageUrl: e.target.value })}
              className="field"
              placeholder="https://…/pizza.jpg"
            />
          </Field>

          <Toggle
            checked={draft.is_available}
            onChange={(value) => setDraft({ ...draft, is_available: value })}
            label="Disponível para venda"
            description="Desmarque quando o item acabar, sem precisar excluí-lo."
          />

          {formError && (
            <p role="alert" className="text-xs text-red-600">
              {formError}
            </p>
          )}

          <div className="flex gap-2">
            <button
              type="submit"
              disabled={save.isPending}
              className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
            >
              {save.isPending ? 'Salvando…' : 'Salvar item'}
            </button>
            <button
              type="button"
              onClick={() => setDraft(null)}
              className="rounded-lg px-4 py-2 text-sm font-medium text-muted hover:bg-line"
            >
              Cancelar
            </button>
          </div>
        </form>
      )}

      {isLoading && <p className="text-sm text-muted">Carregando…</p>}

      <div className="grid gap-5">
        {grouped.map(({ category, items }) => (
          <section key={category.id}>
            <div className="mb-2 flex items-center justify-between gap-3">
              <h2 className="text-sm font-semibold">
                {category.name}
                {!category.is_active && (
                  <span className="ml-2 rounded-md bg-zinc-100 px-1.5 py-0.5 text-[11px] font-normal text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                    seção oculta
                  </span>
                )}
              </h2>

              <button
                type="button"
                onClick={() => {
                  setDraft(emptyDraft(category.id));
                  setFormError(null);
                }}
                className="rounded-lg px-2.5 py-1 text-xs font-medium text-accent hover:bg-accent/10"
              >
                + Item
              </button>
            </div>

            {items.length === 0 ? (
              <p className="panel px-4 py-5 text-center text-xs text-muted">
                Nenhum item nesta seção ainda.
              </p>
            ) : (
              <ul className="grid gap-2">
                {items.map((product) => (
                  <li
                    key={product.id}
                    className="panel flex flex-wrap items-center gap-3 p-3.5"
                  >
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <p className="truncate text-sm font-medium">
                          {product.name}
                        </p>
                        {!product.is_available && (
                          <span className="shrink-0 rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                            Esgotado
                          </span>
                        )}
                      </div>
                      {product.description && (
                        <p className="truncate text-xs text-muted">
                          {product.description}
                        </p>
                      )}
                    </div>

                    <span className="shrink-0 text-sm font-semibold tabular-nums">
                      {product.promo_price_cents ? (
                        <>
                          <span className="mr-1.5 text-xs font-normal text-muted line-through">
                            {formatMoney(product.price_cents)}
                          </span>
                          {formatMoney(product.promo_price_cents)}
                        </>
                      ) : (
                        formatMoney(product.price_cents)
                      )}
                    </span>

                    <div className="flex shrink-0 gap-1">
                      <button
                        type="button"
                        onClick={() => toggleAvailability.mutate(product)}
                        className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted hover:bg-line"
                      >
                        {product.is_available ? 'Esgotar' : 'Repor'}
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          setDraft({
                            id: product.id,
                            category_id: product.category_id,
                            name: product.name,
                            description: product.description ?? '',
                            priceCents: product.price_cents,
                            promoPriceCents: product.promo_price_cents,
                            imageUrl: product.image_url ?? '',
                            is_available: product.is_available,
                          });
                          setFormError(null);
                          window.scrollTo({ top: 0, behavior: 'smooth' });
                        }}
                        className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted hover:bg-line"
                      >
                        Editar
                      </button>
                      <button
                        type="button"
                        onClick={() => {
                          if (confirm(`Remover "${product.name}"?`)) {
                            remove.mutate(product.id);
                          }
                        }}
                        className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"
                      >
                        Remover
                      </button>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        ))}
      </div>
    </div>
  );
}
