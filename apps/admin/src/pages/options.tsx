import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch, formatMoney } from '@/lib/api';
import type {
  Category,
  ModifierGroup,
  Paginated,
  PricingRule,
  Product,
} from '@/lib/types';
import {
  EmptyState,
  Field,
  MoneyInput,
  PageHeader,
  Section,
  Toggle,
  centsToText,
} from '@/components/ui';

/**
 * Grupos de opções.
 *
 * Dois tipos convivem aqui e a diferença não é cosmética:
 *
 * - Lista: as opções são digitadas no próprio grupo, com um acréscimo cada
 *   (borda, adicional). É o que a plataforma sempre teve.
 * - Composto: as opções são produtos de uma categoria. Serve para sabor de
 *   pizza, onde o sabor já é um produto com preço e descrição próprios e
 *   duplicá-lo à mão faria as duas cópias divergirem no primeiro reajuste.
 *
 * A regra de preço é o que torna o meio a meio possível: com 'highest', dois
 * sabores escolhidos cobram o mais caro em vez de somar duas pizzas.
 */

type OptionDraft = {
  product_id: number;
  /** Nulo = usa o preço do próprio produto, promoção incluída. */
  price_cents: number | null;
};

type ModifierDraft = {
  name: string;
  price_delta_cents: number;
};

type Draft = {
  id?: number;
  name: string;
  min_select: number;
  max_select: number;
  is_required: boolean;
  source: 'list' | 'category';
  source_category_id: number | null;
  pricing_rule: PricingRule;
  options: OptionDraft[];
  modifiers: ModifierDraft[];
};

function emptyDraft(): Draft {
  return {
    name: '',
    min_select: 0,
    max_select: 1,
    is_required: false,
    source: 'list',
    source_category_id: null,
    pricing_rule: 'sum',
    options: [],
    modifiers: [],
  };
}

const PRICING_LABELS: Record<PricingRule, string> = {
  sum: 'Somar todas as escolhas',
  highest: 'Cobrar a mais cara',
  average: 'Média entre as escolhas',
};

const PRICING_HINTS: Record<PricingRule, string> = {
  sum: 'Cada opção acrescenta seu valor. Use para bordas e adicionais.',
  highest:
    'Duas metades cobram o sabor mais caro. É o padrão para pizza meio a meio.',
  average: 'Soma os sabores e divide pela quantidade escolhida.',
};

export function OptionsPage() {
  const queryClient = useQueryClient();
  const [draft, setDraft] = useState<Draft | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['modifier-groups'],
    queryFn: () => apiFetch<{ data: ModifierGroup[] }>('/admin/modifier-groups'),
  });

  const { data: categoriesData } = useQuery({
    queryKey: ['categories'],
    queryFn: () => apiFetch<{ data: Category[] }>('/admin/categories'),
  });

  const { data: productsData } = useQuery({
    queryKey: ['products', ''],
    queryFn: () => apiFetch<Paginated<Product>>('/admin/products?per_page=200'),
  });

  const groups = data?.data ?? [];
  const categories = categoriesData?.data ?? [];
  const products = productsData?.data ?? [];

  // Produtos da categoria escolhida como origem — são eles que viram opção.
  const sourceProducts = useMemo(() => {
    if (!draft?.source_category_id) return [];
    return products.filter((p) => p.category_id === draft.source_category_id);
  }, [draft?.source_category_id, products]);

  const save = useMutation({
    mutationFn: (input: Draft) => {
      const body = JSON.stringify({
        name: input.name,
        min_select: input.min_select,
        max_select: input.max_select,
        is_required: input.is_required,
        source: input.source,
        source_category_id:
          input.source === 'category' ? input.source_category_id : null,
        pricing_rule: input.pricing_rule,
        options: input.source === 'category' ? input.options : [],
        modifiers: input.source === 'list' ? input.modifiers : [],
      });

      return input.id
        ? apiFetch(`/admin/modifier-groups/${input.id}`, { method: 'PUT', body })
        : apiFetch('/admin/modifier-groups', { method: 'POST', body });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['modifier-groups'] });
      setDraft(null);
      setFormError(null);
    },
    onError: (caught) => {
      setFormError(
        caught instanceof ApiError
          ? Object.values(caught.errors ?? {}).flat().join(' ') || caught.message
          : 'Não foi possível salvar o grupo.',
      );
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) =>
      apiFetch(`/admin/modifier-groups/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['modifier-groups'] });
      setFormError(null);
    },
    onError: (caught) => {
      setFormError(
        caught instanceof ApiError
          ? caught.message
          : 'Não foi possível remover o grupo.',
      );
    },
  });

  function edit(group: ModifierGroup) {
    setDraft({
      id: group.id,
      name: group.name,
      min_select: group.min_select,
      max_select: group.max_select,
      is_required: group.is_required,
      source: group.source,
      source_category_id: group.source_category_id,
      pricing_rule: group.pricing_rule,
      options: group.option_products.map((option) => ({
        product_id: option.id,
        price_cents: option.pivot.price_cents,
      })),
      modifiers: group.modifiers.map((modifier) => ({
        name: modifier.name,
        price_delta_cents: modifier.price_delta_cents,
      })),
    });
    setFormError(null);
  }

  function toggleOption(productId: number) {
    setDraft((current) => {
      if (!current) return current;

      const exists = current.options.some((o) => o.product_id === productId);

      return {
        ...current,
        options: exists
          ? current.options.filter((o) => o.product_id !== productId)
          : [...current.options, { product_id: productId, price_cents: null }],
      };
    });
  }

  return (
    <div>
      <PageHeader
        title="Opções"
        description="Bordas, adicionais e sabores. Um grupo é criado uma vez e vale para todos os produtos em que for usado."
        action={
          <button
            type="button"
            onClick={() => {
              setDraft(emptyDraft());
              setFormError(null);
            }}
            className="btn-primary"
          >
            Novo grupo
          </button>
        }
      />

      {formError && !draft && (
        <p className="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-700">
          {formError}
        </p>
      )}

      {draft && (
        <div className="mb-5">
          <Section
            title={draft.id ? 'Editar grupo' : 'Novo grupo'}
            description="O tipo define de onde vêm as opções e não pode ser deduzido depois — escolha antes de cadastrar."
          >
            <div className="grid gap-4">
              <Field label="Nome do grupo">
                <input
                  className="field"
                  value={draft.name}
                  placeholder="Ex.: Escolha 2 sabores"
                  onChange={(e) =>
                    setDraft({ ...draft, name: e.target.value })
                  }
                />
              </Field>

              <Field
                label="Tipo de opção"
                hint={
                  draft.source === 'category'
                    ? 'As opções são produtos de uma categoria — o sabor mantém preço, foto e descrição dele.'
                    : 'As opções são digitadas aqui, cada uma com seu acréscimo.'
                }
              >
                <select
                  className="field"
                  value={draft.source}
                  onChange={(e) => {
                    const source = e.target.value as 'list' | 'category';

                    setDraft({
                      ...draft,
                      source,
                      // A regra default acompanha o tipo: adicional soma,
                      // sabor cobra o mais caro. Deixar 'sum' num grupo de
                      // sabores faria o meio a meio cobrar duas pizzas.
                      pricing_rule: source === 'category' ? 'highest' : 'sum',
                    });
                  }}
                >
                  <option value="list">Lista fixa (borda, adicional)</option>
                  <option value="category">
                    Produtos de uma categoria (sabores)
                  </option>
                </select>
              </Field>

              {draft.source === 'category' && (
                <Field
                  label="Categoria dos sabores"
                  hint="Marque a categoria como “somente opção” na tela de Categorias para que ela não apareça como seção do cardápio."
                >
                  <select
                    className="field"
                    value={draft.source_category_id ?? ''}
                    onChange={(e) =>
                      setDraft({
                        ...draft,
                        source_category_id: e.target.value
                          ? Number(e.target.value)
                          : null,
                        // Trocar a categoria invalida as opções escolhidas:
                        // elas são produtos da categoria anterior.
                        options: [],
                      })
                    }
                  >
                    <option value="">Selecione…</option>
                    {categories.map((category) => (
                      <option key={category.id} value={category.id}>
                        {category.name}
                      </option>
                    ))}
                  </select>
                </Field>
              )}

              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Mínimo de escolhas">
                  <input
                    type="number"
                    min={0}
                    className="field"
                    value={draft.min_select}
                    onChange={(e) =>
                      setDraft({
                        ...draft,
                        min_select: Math.max(0, Number(e.target.value)),
                      })
                    }
                  />
                </Field>

                <Field
                  label="Máximo de escolhas"
                  hint={
                    draft.max_select > 1
                      ? `O cliente escolhe até ${draft.max_select} opções deste grupo.`
                      : undefined
                  }
                >
                  <input
                    type="number"
                    min={1}
                    className="field"
                    value={draft.max_select}
                    onChange={(e) =>
                      setDraft({
                        ...draft,
                        max_select: Math.max(1, Number(e.target.value)),
                      })
                    }
                  />
                </Field>
              </div>

              {/* A regra só muda alguma coisa quando cabe mais de uma escolha. */}
              {draft.max_select > 1 && (
                <Field
                  label="Como cobrar várias escolhas"
                  hint={PRICING_HINTS[draft.pricing_rule]}
                >
                  <select
                    className="field"
                    value={draft.pricing_rule}
                    onChange={(e) =>
                      setDraft({
                        ...draft,
                        pricing_rule: e.target.value as PricingRule,
                      })
                    }
                  >
                    {(
                      Object.keys(PRICING_LABELS) as PricingRule[]
                    ).map((rule) => (
                      <option key={rule} value={rule}>
                        {PRICING_LABELS[rule]}
                      </option>
                    ))}
                  </select>
                </Field>
              )}

              <Toggle
                checked={draft.is_required}
                onChange={(is_required) =>
                  setDraft({
                    ...draft,
                    is_required,
                    // Obrigatório sem mínimo não obriga nada; alinhar aqui
                    // evita o grupo "obrigatório" que o cliente pula.
                    min_select:
                      is_required && draft.min_select === 0
                        ? 1
                        : draft.min_select,
                  })
                }
                label="Obrigatório"
                description="O cliente não consegue adicionar o item sem escolher."
              />

              {draft.source === 'category' ? (
                <div>
                  <p className="text-xs font-medium text-muted">
                    Sabores ofertados
                  </p>

                  {!draft.source_category_id ? (
                    <p className="mt-2 text-sm text-muted">
                      Selecione a categoria acima para listar os sabores.
                    </p>
                  ) : sourceProducts.length === 0 ? (
                    <p className="mt-2 text-sm text-muted">
                      Esta categoria ainda não tem produtos.
                    </p>
                  ) : (
                    <ul className="mt-2 grid gap-2">
                      {sourceProducts.map((product) => {
                        const chosen = draft.options.find(
                          (o) => o.product_id === product.id,
                        );

                        return (
                          <li
                            key={product.id}
                            className="flex flex-wrap items-center gap-3 rounded-md border border-[var(--hairline)] p-2.5"
                          >
                            <label className="flex flex-1 items-center gap-2.5 text-sm">
                              <input
                                type="checkbox"
                                checked={Boolean(chosen)}
                                onChange={() => toggleOption(product.id)}
                              />
                              <span>{product.name}</span>
                              <span className="text-xs text-muted">
                                {formatMoney(product.price_cents)}
                              </span>
                            </label>

                            {chosen && (
                              <div className="flex items-center gap-2">
                                <span className="text-xs text-muted">
                                  Preço neste grupo
                                </span>
                                <div className="w-32">
                                  <MoneyInput
                                    valueCents={chosen.price_cents}
                                    placeholder={centsToText(
                                      product.price_cents,
                                    )}
                                    onChange={(cents) =>
                                      setDraft({
                                        ...draft,
                                        options: draft.options.map((o) =>
                                          o.product_id === product.id
                                            ? { ...o, price_cents: cents }
                                            : o,
                                        ),
                                      })
                                    }
                                  />
                                </div>
                              </div>
                            )}
                          </li>
                        );
                      })}
                    </ul>
                  )}

                  <p className="mt-2 text-xs text-muted">
                    Deixe o preço em branco para usar o do próprio produto. Preencha
                    para cobrar um valor diferente neste tamanho.
                  </p>
                </div>
              ) : (
                <div>
                  <p className="text-xs font-medium text-muted">Opções</p>

                  <ul className="mt-2 grid gap-2">
                    {draft.modifiers.map((modifier, index) => (
                      <li key={index} className="flex items-center gap-2">
                        <input
                          className="field flex-1"
                          placeholder="Nome da opção"
                          value={modifier.name}
                          onChange={(e) =>
                            setDraft({
                              ...draft,
                              modifiers: draft.modifiers.map((m, i) =>
                                i === index ? { ...m, name: e.target.value } : m,
                              ),
                            })
                          }
                        />

                        <div className="w-32">
                          <MoneyInput
                            valueCents={modifier.price_delta_cents}
                            onChange={(cents) =>
                              setDraft({
                                ...draft,
                                modifiers: draft.modifiers.map((m, i) =>
                                  i === index
                                    ? { ...m, price_delta_cents: cents ?? 0 }
                                    : m,
                                ),
                              })
                            }
                          />
                        </div>

                        <button
                          type="button"
                          aria-label={`Remover ${modifier.name || 'opção'}`}
                          className="btn-ghost"
                          onClick={() =>
                            setDraft({
                              ...draft,
                              modifiers: draft.modifiers.filter(
                                (_, i) => i !== index,
                              ),
                            })
                          }
                        >
                          Remover
                        </button>
                      </li>
                    ))}
                  </ul>

                  <button
                    type="button"
                    className="btn-ghost mt-2"
                    onClick={() =>
                      setDraft({
                        ...draft,
                        modifiers: [
                          ...draft.modifiers,
                          { name: '', price_delta_cents: 0 },
                        ],
                      })
                    }
                  >
                    Adicionar opção
                  </button>
                </div>
              )}

              {formError && (
                <p className="rounded-md bg-red-50 p-3 text-sm text-red-700">
                  {formError}
                </p>
              )}

              <div className="flex gap-2">
                <button
                  type="button"
                  className="btn-primary"
                  disabled={save.isPending || !draft.name}
                  onClick={() => save.mutate(draft)}
                >
                  {save.isPending ? 'Salvando…' : 'Salvar grupo'}
                </button>

                <button
                  type="button"
                  className="btn-ghost"
                  onClick={() => {
                    setDraft(null);
                    setFormError(null);
                  }}
                >
                  Cancelar
                </button>
              </div>
            </div>
          </Section>
        </div>
      )}

      {isLoading ? (
        <p className="text-sm text-muted">Carregando…</p>
      ) : groups.length === 0 ? (
        <EmptyState
          title="Nenhum grupo de opções"
          description="Crie um grupo para oferecer bordas, adicionais ou sabores de pizza."
        />
      ) : (
        <ul className="grid gap-2.5">
          {groups.map((group) => (
            <li key={group.id} className="panel p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="font-medium">{group.name}</p>

                  <p className="mt-0.5 text-xs text-muted">
                    {group.source === 'category'
                      ? `${group.option_products.length} sabores`
                      : `${group.modifiers.length} opções`}
                    {' · '}
                    {group.min_select === group.max_select
                      ? `escolhe ${group.max_select}`
                      : `de ${group.min_select} a ${group.max_select}`}
                    {group.max_select > 1 &&
                      ` · ${PRICING_LABELS[group.pricing_rule].toLowerCase()}`}
                    {group.products_count
                      ? ` · em ${group.products_count} ${
                          group.products_count === 1 ? 'produto' : 'produtos'
                        }`
                      : ' · não usado em nenhum produto'}
                  </p>

                  <p className="mt-1.5 text-xs text-subtle">
                    {(group.source === 'category'
                      ? group.option_products.map((o) => o.name)
                      : group.modifiers.map((m) => m.name)
                    )
                      .slice(0, 6)
                      .join(' · ')}
                  </p>
                </div>

                <div className="flex shrink-0 gap-2">
                  <button
                    type="button"
                    className="btn-ghost"
                    onClick={() => edit(group)}
                  >
                    Editar
                  </button>

                  <button
                    type="button"
                    className="btn-ghost text-red-600"
                    onClick={() => remove.mutate(group.id)}
                  >
                    Excluir
                  </button>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
