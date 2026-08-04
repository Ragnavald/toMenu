import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch, formatMoney } from '@/lib/api';
import type {
  Category,
  Modifier,
  ModifierGroup,
  Paginated,
  Product,
} from '@/lib/types';
import {
  EmptyState,
  Field,
  Modal,
  MoneyInput,
  PageHeader,
  textToCents,
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
  const [flavorHelpOpen, setFlavorHelpOpen] = useState(false);

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
    return groupsOf(productId).map((group) => group.id);
  }

  /**
   * Os grupos inteiros, e não só os ids, para a linha expansível listar as
   * opções sem uma segunda ida à API: o índice de grupos já traz `modifiers` e
   * `option_products` embutidos.
   */
  function groupsOf(productId: number): ModifierGroup[] {
    return groups.filter((group) =>
      group.products?.some((p) => p.id === productId),
    );
  }

  /*
   * Qual item está aberto — um por vez, e não um Set.
   *
   * A lista já é longa e cada painel aberto empurra o resto para baixo; deixar
   * vários abertos faria a linha em que se estava clicando saltar da tela.
   */
  const [expandedId, setExpandedId] = useState<number | null>(null);

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

  const [optionError, setOptionError] = useState<string | null>(null);

  /**
   * Grava a lista de opções de um grupo de lista, a partir da linha expandida.
   *
   * Manda o grupo INTEIRO, e não só o que mudou: o `syncOptions` do backend
   * apaga os modifiers e recria a partir do que chegou, então um corpo parcial
   * não removeria uma opção — apagaria todas as outras. Os demais campos vão
   * junto pelo mesmo motivo, já que a validação do PUT exige todos eles.
   *
   * A ordem do array é a posição salva (`position` = índice), então preservá-la
   * aqui é o que mantém as opções na mesma ordem da tela de Opções.
   */
  const saveOptions = useMutation({
    mutationFn: ({
      group,
      modifiers,
    }: {
      group: ModifierGroup;
      modifiers: Pick<Modifier, 'name' | 'price_delta_cents' | 'is_available'>[];
    }) =>
      apiFetch(`/admin/modifier-groups/${group.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          name: group.name,
          min_select: group.min_select,
          max_select: group.max_select,
          is_required: group.is_required,
          source: group.source,
          source_category_id: group.source_category_id,
          pricing_rule: group.pricing_rule,
          modifiers,
          options: [],
        }),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['modifier-groups'] });
      setOptionError(null);
    },
    onError: (caught) => {
      /*
       * O erro mais provável aqui não é falha de rede: é a regra de que o
       * mínimo não pode passar do número de opções. Quem remove a última opção
       * de um grupo obrigatório esbarra nela, e a mensagem do backend explica
       * o que fazer — por isso ela é mostrada, e não uma genérica.
       */
      setOptionError(
        caught instanceof ApiError
          ? Object.values(caught.errors ?? {}).flat().join(' ') || caught.message
          : 'Não foi possível salvar as opções.',
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
          {/*
            Cabeçalho fora do <Field>: o Field envolve tudo num <label>, e um
            <button> ali dentro faria o clique de "Como inserir sabores?"
            alcançar também o primeiro checkbox da lista.
          */}
          <div className="grid gap-1.5">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
              <span className="text-xs font-medium text-muted">
                Grupos de opções
              </span>

              <button
                type="button"
                onClick={() => setFlavorHelpOpen(true)}
                className="text-xs font-medium text-accent underline"
              >
                Como inserir sabores para esse item?
              </button>
            </div>

            {groups.length === 0 ? (
              <p className="text-xs text-muted">
                Nenhum grupo criado ainda.{' '}
                <Link to="/opcoes" className="underline">
                  Criar grupos de opções
                </Link>{' '}
                para oferecer sabores, bordas e adicionais.
              </p>
            ) : (
              <>
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
                                  ? draft.groupIds.filter(
                                      (id) => id !== group.id,
                                    )
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

                {/* Sem esta saída, quem já tem um grupo criado não encontra o
                    caminho para criar o segundo: o link só existia no estado
                    vazio da lista. */}
                <Link
                  to="/opcoes"
                  className="mt-1 inline-block text-xs font-medium text-accent underline"
                >
                  Criar outro grupo de opções
                </Link>

                <span className="text-xs text-muted">
                  O cliente escolhe estas opções ao abrir o item na loja.
                </span>
              </>
            )}
          </div>

          <FlavorHelpModal
            open={flavorHelpOpen}
            onClose={() => setFlavorHelpOpen(false)}
          />

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
                {items.map((product) => {
                  const productGroups = groupsOf(product.id);
                  const expanded = expandedId === product.id;

                  return (
                    <li key={product.id} className="panel">
                      <div className="flex flex-wrap items-center gap-3 p-3.5">
                      {/*
                        Só vira botão quem tem opção para mostrar. Um item sem
                        grupo nenhum abriria um painel vazio — o clique
                        prometeria conteúdo e não entregaria nada.
                      */}
                      {productGroups.length > 0 ? (
                        <button
                          type="button"
                          onClick={() =>
                            setExpandedId(expanded ? null : product.id)
                          }
                          aria-expanded={expanded}
                          className="-m-1 flex min-w-0 flex-1 items-center gap-2 rounded-lg p-1 text-left hover:bg-line"
                        >
                          <Chevron open={expanded} />

                          <span className="min-w-0 flex-1">
                            <span className="flex items-center gap-2">
                              <span className="truncate text-sm font-medium">
                                {product.name}
                              </span>
                              {!product.is_available && (
                                <span className="shrink-0 rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                  Esgotado
                                </span>
                              )}
                              <span className="shrink-0 rounded-md bg-zinc-100 px-1.5 py-0.5 text-[11px] font-normal text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                {countOptions(productGroups)}
                              </span>
                            </span>
                            {product.description && (
                              <span className="block truncate text-xs text-muted">
                                {product.description}
                              </span>
                            )}
                          </span>
                        </button>
                      ) : (
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
                      )}

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
                    </div>

                    {expanded && (
                      <div className="border-t border-line px-3.5 py-3">
                        {optionError && (
                          <p
                            role="alert"
                            className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300"
                          >
                            {optionError}
                          </p>
                        )}

                        <div className="grid gap-4">
                          {productGroups.map((group) => (
                            <GroupOptions
                              key={group.id}
                              group={group}
                              saving={saveOptions.isPending}
                              onSave={(modifiers) =>
                                saveOptions.mutate({ group, modifiers })
                              }
                            />
                          ))}
                        </div>
                      </div>
                      )}
                    </li>
                  );
                })}
              </ul>
            )}
          </section>
        ))}
      </div>
    </div>
  );
}

/** Quantas opções o item oferece somando todos os grupos, para o selo da linha. */
function countOptions(groups: ModifierGroup[]): string {
  const total = groups.reduce(
    (sum, group) =>
      sum +
      (group.source === 'category'
        ? group.option_products.length
        : group.modifiers.length),
    0,
  );

  return total === 1 ? '1 opção' : `${total} opções`;
}

function Chevron({ open }: { open: boolean }) {
  return (
    <svg
      width="14"
      height="14"
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden
      className={`shrink-0 text-muted transition-transform ${open ? 'rotate-90' : ''}`}
    >
      <path
        d="m9 6 6 6-6 6"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

/**
 * Um grupo de opções dentro da linha expandida.
 *
 * Grupo de lista (bordas, adicionais) é editável aqui mesmo: são opções que só
 * existem dentro do grupo, então adicionar e remover não afeta mais nada.
 *
 * Grupo composto (sabores de pizza) é somente leitura, e essa diferença é
 * deliberada. As opções dele são PRODUTOS de uma categoria — "remover" seria
 * ambíguo entre tirar o sabor deste grupo e apagar o produto do cardápio, e a
 * lista é a categoria inteira, que muda sozinha quando um produto novo entra.
 * Editar isso pela linha do item esconderia um efeito que atinge outros itens.
 */
function GroupOptions({
  group,
  saving,
  onSave,
}: {
  group: ModifierGroup;
  saving: boolean;
  onSave: (
    modifiers: Pick<Modifier, 'name' | 'price_delta_cents' | 'is_available'>[],
  ) => void;
}) {
  const [adding, setAdding] = useState(false);
  const [name, setName] = useState('');
  const [priceText, setPriceText] = useState('');

  const composed = group.source === 'category';

  /** A lista atual no formato que o PUT espera — a base de qualquer alteração. */
  const current = group.modifiers.map((modifier) => ({
    name: modifier.name,
    price_delta_cents: modifier.price_delta_cents,
    is_available: modifier.is_available,
  }));

  function handleAdd() {
    const trimmed = name.trim();
    if (!trimmed) return;

    onSave([
      ...current,
      {
        name: trimmed,
        // Vazio é acréscimo zero: a opção que não muda o preço é a mais comum,
        // e obrigar a digitar "0" seria atrito à toa.
        price_delta_cents: textToCents(priceText) ?? 0,
        is_available: true,
      },
    ]);

    setName('');
    setPriceText('');
    setAdding(false);
  }

  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between gap-2">
        <p className="text-xs font-semibold">
          {group.name}
          <span className="ml-1.5 font-normal text-muted">
            {group.is_required ? 'obrigatório' : 'opcional'}
            {group.max_select > 1 && ` · até ${group.max_select}`}
          </span>
        </p>

        {!composed && !adding && (
          <button
            type="button"
            onClick={() => setAdding(true)}
            className="rounded-lg px-2 py-1 text-xs font-medium text-accent hover:bg-accent/10"
          >
            + Opção
          </button>
        )}
      </div>

      {composed ? (
        <p className="text-xs text-muted">
          {group.option_products.length > 0
            ? group.option_products.map((o) => o.name).join(' · ')
            : 'Nenhum sabor nesta categoria ainda.'}
          {' — '}
          {/* Os sabores vêm de uma categoria; o lugar de mexer neles é lá. */}
          <Link to="/opcoes" className="text-accent hover:underline">
            editar em Opções
          </Link>
        </p>
      ) : (
        <ul className="grid gap-1">
          {group.modifiers.length === 0 && !adding && (
            <li className="text-xs text-muted">Nenhuma opção ainda.</li>
          )}

          {group.modifiers.map((modifier, index) => (
            <li
              key={modifier.id}
              className="flex items-center gap-2 rounded-lg bg-line/40 px-2.5 py-1.5"
            >
              <span className="min-w-0 flex-1 truncate text-xs">
                {modifier.name}
              </span>

              {modifier.price_delta_cents !== 0 && (
                <span className="shrink-0 text-xs tabular-nums text-muted">
                  {modifier.price_delta_cents > 0 ? '+' : '−'}
                  {formatMoney(Math.abs(modifier.price_delta_cents))}
                </span>
              )}

              <button
                type="button"
                disabled={saving}
                onClick={() =>
                  onSave(current.filter((_, i) => i !== index))
                }
                aria-label={`Remover ${modifier.name}`}
                className="shrink-0 rounded-md px-1.5 py-0.5 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-60 dark:hover:bg-red-950/30"
              >
                Remover
              </button>
            </li>
          ))}

          {adding && (
            <li className="flex flex-wrap items-center gap-2">
              <input
                autoFocus
                value={name}
                onChange={(e) => setName(e.target.value)}
                onKeyDown={(e) => {
                  // Enter salva e Esc desiste: o formulário do item envolve
                  // esta lista, e sem o preventDefault o Enter submeteria ele.
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    handleAdd();
                  }
                  if (e.key === 'Escape') setAdding(false);
                }}
                placeholder="Nome da opção"
                maxLength={80}
                // py menor que o .field padrão: a linha da opção é mais baixa
                // que um campo de formulário e precisa caber junto do botão.
                className="field min-w-0 flex-1 !py-1.5 text-xs"
              />

              <input
                value={priceText}
                onChange={(e) => setPriceText(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    handleAdd();
                  }
                  if (e.key === 'Escape') setAdding(false);
                }}
                inputMode="decimal"
                placeholder="+ R$ 0,00"
                // O !w vence o width:100% do .field, senão o campo de preço
                // ocuparia a linha inteira e empurraria os botões para baixo.
                className="field !w-24 shrink-0 !py-1.5 text-xs"
              />

              <button
                type="button"
                onClick={handleAdd}
                disabled={saving || !name.trim()}
                className="rounded-lg bg-accent px-2.5 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
              >
                {saving ? 'Salvando…' : 'Adicionar'}
              </button>

              <button
                type="button"
                onClick={() => setAdding(false)}
                className="rounded-lg px-2 py-1.5 text-xs font-medium text-muted hover:bg-line"
              >
                Cancelar
              </button>
            </li>
          )}
        </ul>
      )}
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

/**
 * Explica como um item ganha sabores.
 *
 * O passo que não é adivinhável: o sabor não é digitado dentro do produto, é
 * um produto próprio numa categoria à parte. Quem procura um campo "sabores"
 * aqui no editor não encontra, porque a ligação é feita por um grupo do tipo
 * "Sabores de pizza", que aponta para aquela categoria inteira.
 *
 * O texto descreve o caminho em ordem e nomeia as telas como elas aparecem no
 * menu lateral — sem isso o lojista lê a explicação e ainda não sabe onde
 * clicar.
 */
function FlavorHelpModal({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  return (
    <Modal open={open} onClose={onClose} title="Como inserir sabores para esse item?">
      <div className="grid gap-4 text-sm">
        <p className="text-muted">
          No ToMenu o sabor não é digitado dentro do produto: cada sabor é um
          item do cardápio, com preço e foto próprios. Assim, reajustar a
          Calabresa muda o preço dela em todas as pizzas de uma vez.
        </p>

        <ol className="grid gap-3">
          <li className="grid gap-0.5">
            <span className="font-medium">
              1. Crie uma categoria para os sabores
            </span>
            <span className="text-muted">
              Em <strong>Categorias</strong>, algo como “Sabores de pizza”.
              Depois use o botão <strong>“Só opção”</strong> nela: os sabores
              passam a existir apenas como escolha dentro da pizza, sem virar
              uma seção à parte no cardápio.
            </span>
          </li>

          <li className="grid gap-0.5">
            <span className="font-medium">
              2. Cadastre cada sabor como um item dessa categoria
            </span>
            <span className="text-muted">
              Calabresa, Margherita, Portuguesa… com o preço que a pizza inteira
              daquele sabor custa.
            </span>
          </li>

          <li className="grid gap-0.5">
            <span className="font-medium">
              3. Em Opções, crie um grupo “Sabores de pizza”
            </span>
            <span className="text-muted">
              Escolha a categoria do passo 1 como origem e defina quantos
              sabores o cliente pode combinar (2 para meio a meio, 3 para um
              terço cada).
            </span>
          </li>

          <li className="grid gap-0.5">
            <span className="font-medium">4. Volte aqui e marque o grupo</span>
            <span className="text-muted">
              Na lista <strong>Grupos de opções</strong> acima. É isso que faz o
              seletor de sabores aparecer para o cliente neste item.
            </span>
          </li>
        </ol>

        {/*
          A regra de preço é a dúvida que chega depois: o lojista teme que meia
          calabresa + meia portuguesa vire a soma das duas.
        */}
        <p className="rounded-lg bg-accent/10 px-3 py-2.5 text-xs text-muted">
          <strong className="text-ink">Sobre o preço:</strong> ao combinar
          sabores, o cliente paga o valor do sabor mais caro — nunca a soma. Uma
          meia calabresa (R$ 50) com meia portuguesa (R$ 60) sai por R$ 60.
        </p>

        <div className="flex flex-wrap gap-2">
          <Link
            to="/categorias"
            className="rounded-lg border border-line px-3 py-2 text-xs font-semibold transition-colors hover:bg-line/40"
          >
            Ir para Categorias
          </Link>
          <Link
            to="/opcoes"
            className="rounded-lg bg-accent px-3 py-2 text-xs font-semibold text-white transition-opacity hover:opacity-90"
          >
            Ir para Opções
          </Link>
        </div>
      </div>
    </Modal>
  );
}
