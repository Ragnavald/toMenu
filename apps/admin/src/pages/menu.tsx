import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch, formatMoney } from '@/lib/api';
import type { Category, ModifierGroup, Paginated, Product } from '@/lib/types';
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
  /** Grupos de opções vinculados: bordas, adicionais e sabores. */
  groupIds: number[];
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
    groupIds: [],
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

  const { data: groupsData } = useQuery({
    queryKey: ['modifier-groups'],
    queryFn: () => apiFetch<{ data: ModifierGroup[] }>('/admin/modifier-groups'),
  });

  const categories = categoriesData?.data ?? [];
  const products = productsData?.data ?? [];
  const groups = groupsData?.data ?? [];

  /** Grupos já vinculados a um produto, lidos do índice de grupos. */
  function groupIdsOf(productId: number): number[] {
    return groups
      .filter((group) => group.products?.some((p) => p.id === productId))
      .map((group) => group.id);
  }

  // Agrupa por seção para espelhar o que o cliente final enxerga no cardápio.
  const grouped = useMemo(() => {
    return categories.map((category) => ({
      category,
      items: products.filter((product) => product.category_id === category.id),
    }));
  }, [categories, products]);

  const save = useMutation({
    mutationFn: async (input: Draft) => {
      const body = JSON.stringify({
        category_id: input.category_id,
        name: input.name,
        description: input.description || null,
        price_cents: input.priceCents ?? 0,
        promo_price_cents: input.promoPriceCents,
        image_url: input.imageUrl || null,
        is_available: input.is_available,
      });

      const product = input.id
        ? await apiFetch<Product>(`/admin/products/${input.id}`, {
            method: 'PUT',
            body,
          })
        : await apiFetch<Product>('/admin/products', { method: 'POST', body });

      /*
       * O vínculo com os grupos vive na pivot e é sincronizado por grupo, não
       * por produto. Só os grupos que mudaram são tocados: um PUT em todos
       * reescreveria a lista de produtos de grupos que nada têm a ver com esta
       * edição, e duas abas abertas se sobrescreveriam.
       */
      const before = input.id ? groupIdsOf(input.id) : [];
      const after = input.groupIds;

      const touched = groups.filter(
        (group) =>
          before.includes(group.id) !== after.includes(group.id),
      );

      await Promise.all(
        touched.map((group) => {
          const others = (group.products ?? [])
            .map((p) => p.id)
            .filter((id) => id !== product.id);

          return apiFetch(`/admin/modifier-groups/${group.id}/products`, {
            method: 'POST',
            body: JSON.stringify({
              product_ids: after.includes(group.id)
                ? [...others, product.id]
                : others,
            }),
          });
        }),
      );
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] });
      queryClient.invalidateQueries({ queryKey: ['categories'] });
      queryClient.invalidateQueries({ queryKey: ['modifier-groups'] });
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

  const [categoryError, setCategoryError] = useState<string | null>(null);

  const removeCategory = useMutation({
    mutationFn: (id: number) =>
      apiFetch(`/admin/categories/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] });
      queryClient.invalidateQueries({ queryKey: ['categories'] });
      setCategoryError(null);
    },
    onError: (caught) => {
      setCategoryError(
        caught instanceof ApiError
          ? Object.values(caught.errors ?? {}).flat().join(' ') || caught.message
          : 'Não foi possível excluir a categoria.',
      );
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

          <Field label="Foto do produto" hint="Faça upload do arquivo da foto ou digite a URL.">
            <ProductImageUploader
              imageUrl={draft.imageUrl}
              onUploaded={(url) => setDraft({ ...draft, imageUrl: url })}
            />
          </Field>

          {/*
            Vínculo com os grupos de opções. É aqui que a "Pizza Grande" ganha
            os sabores e a borda — sem isso o produto é só um nome com preço.
          */}
          <Field
            label="Grupos de opções"
            hint={
              groups.length > 0
                ? 'O cliente escolhe estas opções ao abrir o item na loja.'
                : undefined
            }
          >
            {groups.length === 0 ? (
              <p className="text-xs text-muted">
                Nenhum grupo criado ainda.{' '}
                <Link to="/opcoes" className="underline">
                  Criar grupos de opções
                </Link>{' '}
                para oferecer sabores, bordas e adicionais.
              </p>
            ) : (
              <ul className="grid gap-1.5">
                {groups.map((group) => {
                  const checked = draft.groupIds.includes(group.id);

                  return (
                    <li key={group.id}>
                      <label className="flex cursor-pointer items-center gap-2.5 text-sm">
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() =>
                            setDraft({
                              ...draft,
                              groupIds: checked
                                ? draft.groupIds.filter((id) => id !== group.id)
                                : [...draft.groupIds, group.id],
                            })
                          }
                          className="size-4 shrink-0 accent-[rgb(var(--accent))]"
                        />
                        <span>{group.name}</span>
                        <span className="text-xs text-muted">
                          {group.source === 'category'
                            ? `${group.option_products.length} sabores`
                            : `${group.modifiers.length} opções`}
                          {group.min_select > 0 && ' · obrigatório'}
                        </span>
                      </label>
                    </li>
                  );
                })}
              </ul>
            )}
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

          {/* Salvar dispara a invalidação do cardápio no Next e no Cloudflare
              (PurgeMenuCache), então a alteração aparece em segundos. O aviso
              continua porque o purge roda numa fila: não é instantâneo, e sem
              ele o lojista que recarrega a loja no mesmo segundo acha que o
              sistema perdeu o item. */}
          <p className="text-xs text-muted">
            Alterações no cardápio aparecem para os clientes em alguns segundos.
          </p>

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

      {categoryError && (
        <p role="alert" className="mb-4 rounded-lg bg-red-50 px-3.5 py-2.5 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300">
          {categoryError}
        </p>
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

              <div className="flex items-center gap-1.5">
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

                <button
                  type="button"
                  onClick={() => {
                    if (items.length > 0) {
                      setCategoryError(`A seção "${category.name}" possui ${items.length} item(ns). Mova ou remova os produtos antes de excluí-la.`);
                      return;
                    }
                    if (confirm(`Excluir a categoria "${category.name}"?`)) {
                      removeCategory.mutate(category.id);
                    }
                  }}
                  className="rounded-lg px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"
                  title="Excluir seção"
                >
                  Excluir seção
                </button>
              </div>
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
                            groupIds: groupIdsOf(product.id),
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

function ProductImageUploader({
  imageUrl,
  onUploaded,
}: {
  imageUrl: string;
  onUploaded: (url: string) => void;
}) {
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleFileChange(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
      setError('Por favor, selecione um arquivo de imagem válido.');
      return;
    }

    const body = new FormData();
    body.append('image', file);

    setUploading(true);
    setError(null);

    try {
      const res = await apiFetch<{ url: string }>('/admin/products/upload-image', {
        method: 'POST',
        body,
      });
      onUploaded(res.url);
    } catch (caught) {
      setError(
        caught instanceof ApiError
          ? caught.message
          : 'Não foi possível enviar a imagem.',
      );
    } finally {
      setUploading(false);
    }
  }

  return (
    <div className="grid gap-2">
      <div className="flex flex-wrap items-center gap-3">
        {imageUrl ? (
          <div className="relative size-16 shrink-0 overflow-hidden rounded-lg border border-line bg-line/20">
            <img
              src={imageUrl}
              alt="Pré-visualização"
              className="size-full object-cover"
            />
            <button
              type="button"
              onClick={() => onUploaded('')}
              className="absolute top-1 right-1 grid size-5 place-items-center rounded-full bg-black/70 text-[10px] text-white hover:bg-red-600"
              title="Remover imagem"
            >
              ✕
            </button>
          </div>
        ) : (
          <div className="grid size-16 shrink-0 place-items-center rounded-lg border border-dashed border-line bg-line/10 text-muted">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7">
              <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
              <circle cx="8.5" cy="8.5" r="1.5" />
              <polyline points="21 15 16 10 5 21" />
            </svg>
          </div>
        )}

        <div className="flex-1 min-w-[200px] grid gap-1.5">
          <label className="inline-flex cursor-pointer items-center justify-center rounded-lg border border-line bg-panel px-3 py-2 text-xs font-semibold shadow-xs transition-colors hover:bg-line/40">
            {uploading ? 'Enviando foto…' : '📷 Escolher imagem da galeria'}
            <input
              type="file"
              accept="image/*"
              disabled={uploading}
              onChange={handleFileChange}
              className="sr-only"
            />
          </label>

          <input
            value={imageUrl}
            onChange={(e) => onUploaded(e.target.value)}
            className="field text-xs"
            placeholder="Ou cole a URL direta (https://…)"
          />
        </div>
      </div>

      {error && <p className="text-xs text-red-600">{error}</p>}
    </div>
  );
}
