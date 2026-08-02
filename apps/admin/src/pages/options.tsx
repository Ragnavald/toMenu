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
 *
 * O lojista não escolhe nada disso diretamente. Ele escolhe um caso de uso
 * ("sabores de pizza", "borda") e o preset abaixo traduz para source,
 * pricing_rule e min/max — três decisões acopladas que, soltas na tela,
 * produziam o meio a meio cobrando duas pizzas.
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

type PresetId = 'flavors' | 'addons' | 'size' | 'single';

type Draft = {
  id?: number;
  /** Preset de origem: define os textos da tela, não é persistido. */
  preset: PresetId;
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

/**
 * Casos de uso oferecidos na primeira tela.
 *
 * Cada um carrega a combinação que o lojista erraria à mão. O caso `flavors`
 * é o único com pricing_rule 'highest': é o que faz duas metades cobrarem o
 * sabor mais caro em vez de somarem duas pizzas.
 */
type Preset = {
  id: PresetId;
  icon: string;
  title: string;
  subtitle: string;
  /** Como a tela chama cada opção depois de escolhido o caso de uso. */
  noun: { one: string; many: string };
  namePlaceholder: string;
  defaults: Omit<Draft, 'id' | 'preset' | 'name'>;
};

const PRESETS: Preset[] = [
  {
    id: 'flavors',
    icon: '🍕',
    title: 'Sabores de pizza',
    subtitle: 'Meio a meio, dois ou mais sabores no mesmo item.',
    noun: { one: 'sabor', many: 'sabores' },
    namePlaceholder: 'Ex.: Escolha os sabores',
    defaults: {
      min_select: 1,
      max_select: 2,
      is_required: true,
      source: 'category',
      source_category_id: null,
      pricing_rule: 'highest',
      options: [],
      modifiers: [],
    },
  },
  {
    id: 'addons',
    icon: '🧀',
    title: 'Bordas e adicionais',
    subtitle: 'Extras opcionais que somam ao preço, como catupiry ou bacon.',
    noun: { one: 'adicional', many: 'adicionais' },
    namePlaceholder: 'Ex.: Borda recheada',
    defaults: {
      min_select: 0,
      max_select: 3,
      is_required: false,
      source: 'list',
      source_category_id: null,
      pricing_rule: 'sum',
      options: [],
      modifiers: [],
    },
  },
  {
    id: 'size',
    icon: '📏',
    title: 'Tamanho',
    subtitle: 'Pequena, média, grande — o cliente escolhe exatamente um.',
    noun: { one: 'tamanho', many: 'tamanhos' },
    namePlaceholder: 'Ex.: Tamanho',
    defaults: {
      min_select: 1,
      max_select: 1,
      is_required: true,
      source: 'list',
      source_category_id: null,
      pricing_rule: 'sum',
      options: [],
      modifiers: [],
    },
  },
  {
    id: 'single',
    icon: '○',
    title: 'Escolha única',
    subtitle: 'Ponto da carne, tipo de pão, nível de açúcar.',
    noun: { one: 'opção', many: 'opções' },
    namePlaceholder: 'Ex.: Ponto da carne',
    defaults: {
      min_select: 1,
      max_select: 1,
      is_required: true,
      source: 'list',
      source_category_id: null,
      pricing_rule: 'sum',
      options: [],
      modifiers: [],
    },
  },
];

function presetOf(id: PresetId): Preset {
  return PRESETS.find((p) => p.id === id) ?? PRESETS[0];
}

function draftFromPreset(preset: Preset): Draft {
  return { preset: preset.id, name: '', ...preset.defaults };
}

/**
 * Deduz o caso de uso de um grupo já salvo.
 *
 * `preset` não vai para o banco: ele é só a lente pela qual a tela fala com o
 * lojista. Ao editar, reconstruímos a partir do que distingue cada caso, para
 * que "Escolha os sabores" volte falando de sabores e não de opções genéricas.
 */
function presetFromGroup(group: ModifierGroup): PresetId {
  if (group.source === 'category') return 'flavors';
  if (group.max_select > 1) return 'addons';
  return group.min_select >= 1 ? 'size' : 'single';
}

const PRICING_LABELS: Record<PricingRule, string> = {
  sum: 'Somar todas as escolhas',
  highest: 'Cobrar a mais cara',
  average: 'Média entre as escolhas',
};

/**
 * Traduz a configuração para uma frase em português.
 *
 * É o antídoto para "mínimo 0, máximo 1": três campos numéricos e um select
 * cujo efeito combinado ninguém consegue simular de cabeça. A frase é montada
 * a partir do mesmo estado que vai para a API, então nunca descreve algo
 * diferente do que será salvo.
 */
function summarize(draft: Draft, optionCount: number): string {
  const noun = presetOf(draft.preset).noun;
  const { min_select: min, max_select: max } = draft;

  const quantity =
    min === max
      ? `exatamente ${max} ${max === 1 ? noun.one : noun.many}`
      : min === 0
        ? `até ${max} ${max === 1 ? noun.one : noun.many}`
        : `de ${min} a ${max} ${noun.many}`;

  const obligation =
    min > 0
      ? 'O cliente precisa escolher'
      : 'O cliente pode escolher';

  const pricing =
    max <= 1
      ? ''
      : draft.pricing_rule === 'highest'
        ? `, e paga o valor do ${noun.one} mais caro`
        : draft.pricing_rule === 'average'
          ? `, e paga a média entre os ${noun.many} escolhidos`
          : ', e cada escolha soma seu valor ao total';

  const base = `${obligation} ${quantity}${pricing}.`;

  if (optionCount === 0) {
    return `${base} Falta cadastrar ${
      draft.source === 'category' ? 'os produtos ofertados' : `${noun.many}`
    }.`;
  }

  return base;
}

export function OptionsPage() {
  const queryClient = useQueryClient();
  const [draft, setDraft] = useState<Draft | null>(null);
  /** Escolha do caso de uso: precede o formulário na criação. */
  const [picking, setPicking] = useState(false);
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

  const optionOnlyCategories = categories.filter((c) => c.is_option_only);
  const menuCategories = categories.filter((c) => !c.is_option_only);

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
    setPicking(false);
    setDraft({
      id: group.id,
      preset: presetFromGroup(group),
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

  /**
   * Ajusta min e max juntos.
   *
   * Eles são interdependentes na API (`max_select` tem `gte:min_select`), e
   * separá-los em dois campos livres deixava o lojista salvar min 3 / max 2
   * para descobrir o erro só no servidor. O campo que o lojista tocou vence e
   * o outro cede: subir o mínimo empurra o máximo, baixar o máximo puxa o
   * mínimo. Assim nenhuma sequência de cliques chega a um estado inválido.
   */
  function setMin(current: Draft, value: number): Draft {
    const min = Math.min(50, Math.max(0, value));

    return {
      ...current,
      min_select: min,
      max_select: Math.max(current.max_select, min, 1),
      is_required: min > 0,
    };
  }

  function setMax(current: Draft, value: number): Draft {
    const max = Math.min(50, Math.max(1, value));
    const min = Math.min(current.min_select, max);

    return {
      ...current,
      min_select: min,
      max_select: max,
      is_required: min > 0,
    };
  }

  const activePreset = draft ? presetOf(draft.preset) : null;

  // Quantas opções o grupo realmente tem, para a frase-resumo e para barrar o
  // 422 de "exige N escolhas, mas só tem M opções" antes de ir à API.
  const optionCount = !draft
    ? 0
    : draft.source === 'category'
      ? draft.options.length
      : draft.modifiers.filter((m) => m.name.trim()).length;

  const blockingIssue = !draft
    ? null
    : !draft.name.trim()
      ? 'Dê um nome ao grupo.'
      : draft.source === 'category' && !draft.source_category_id
        ? 'Escolha a categoria que contém os produtos ofertados.'
        : optionCount === 0
          ? `Cadastre ao menos ${
              draft.source === 'category' ? 'um produto' : 'uma opção'
            }.`
          : draft.min_select > optionCount
            ? `O grupo exige ${draft.min_select} escolhas, mas só tem ${optionCount} ${
                optionCount === 1 ? 'opção' : 'opções'
              }.`
            : null;

  return (
    <div>
      <PageHeader
        title="Opções"
        description="Bordas, adicionais e sabores. Um grupo é criado uma vez e vale para todos os produtos em que for usado."
        action={
          !draft && !picking ? (
            <button
              type="button"
              onClick={() => {
                setPicking(true);
                setFormError(null);
              }}
              className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90"
            >
              Novo grupo
            </button>
          ) : undefined
        }
      />

      {formError && !draft && (
        <p className="mb-4 rounded-lg bg-red-50 px-3.5 py-2.5 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
          {formError}
        </p>
      )}

      {/*
        Escolha do caso de uso.
        Vem antes do formulário porque `source` é imutável na prática: trocá-lo
        depois apaga as opções do lado que deixou de valer (syncOptions no
        controller). Perguntar "o que você quer oferecer?" resolve na linguagem
        do lojista o que "tipo de opção: lista fixa" nunca resolveu.
      */}
      {picking && (
        <div className="mb-5">
          <Section
            title="O que você quer oferecer?"
            description="Escolha o caso mais parecido. Todos os detalhes podem ser ajustados depois."
          >
            <div className="grid gap-2.5 sm:grid-cols-2">
              {PRESETS.map((preset) => (
                <button
                  key={preset.id}
                  type="button"
                  onClick={() => {
                    setDraft(draftFromPreset(preset));
                    setPicking(false);
                  }}
                  className="flex items-start gap-3 rounded-lg border border-line p-3.5 text-left transition-colors hover:border-accent hover:bg-accent/5"
                >
                  <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-line/50 text-base">
                    {preset.icon}
                  </span>
                  <span className="min-w-0">
                    <span className="block text-sm font-medium">
                      {preset.title}
                    </span>
                    <span className="mt-0.5 block text-xs text-muted">
                      {preset.subtitle}
                    </span>
                  </span>
                </button>
              ))}
            </div>

            <button
              type="button"
              onClick={() => setPicking(false)}
              className="mt-3.5 rounded-lg px-3 py-1.5 text-sm font-medium text-muted hover:bg-line"
            >
              Cancelar
            </button>
          </Section>
        </div>
      )}

      {draft && activePreset && (
        <div className="mb-5">
          <Section
            title={
              draft.id
                ? `Editar ${draft.name || 'grupo'}`
                : `Novo grupo · ${activePreset.title}`
            }
            description={activePreset.subtitle}
          >
            <div className="grid gap-4">
              <Field label="Nome do grupo" hint="É o título que o cliente vê ao abrir o item na loja.">
                <input
                  autoFocus
                  className="field"
                  value={draft.name}
                  placeholder={activePreset.namePlaceholder}
                  onChange={(e) => setDraft({ ...draft, name: e.target.value })}
                />
              </Field>

              {draft.source === 'category' && (
                <Field
                  label={`Categoria com os ${activePreset.noun.many}`}
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

                    {/*
                      Categorias "somente opção" primeiro, e separadas.
                      Elas são as que existem justamente para abastecer grupos
                      compostos; misturá-las com as seções do cardápio numa
                      lista plana faz "Entradas" parecer uma escolha tão válida
                      quanto "Sabores de Pizza" — e o lojista acaba ofertando
                      bruschetta como sabor de pizza.
                    */}
                    {optionOnlyCategories.length > 0 && (
                      <optgroup label="Categorias de opção">
                        {optionOnlyCategories.map((category) => (
                          <option key={category.id} value={category.id}>
                            {category.name}
                          </option>
                        ))}
                      </optgroup>
                    )}

                    {menuCategories.length > 0 && (
                      <optgroup label="Seções do cardápio">
                        {menuCategories.map((category) => (
                          <option key={category.id} value={category.id}>
                            {category.name}
                          </option>
                        ))}
                      </optgroup>
                    )}
                  </select>
                </Field>
              )}

              {/*
                Quantidade como steppers.
                O par min/max é a origem do "mínimo 0, máximo 1" ilegível do
                print. Aqui cada botão diz o que faz, e setRange mantém os dois
                coerentes para que a API nunca recuse por gte:min_select.
              */}
              <div className="grid gap-3 sm:grid-cols-2">
                <Stepper
                  label="Mínimo de escolhas"
                  hint={
                    draft.min_select === 0
                      ? 'O cliente pode pular este grupo.'
                      : 'O cliente é obrigado a escolher.'
                  }
                  value={draft.min_select}
                  min={0}
                  onChange={(min) => setDraft(setMin(draft, min))}
                />

                <Stepper
                  label="Máximo de escolhas"
                  hint={`No máximo ${draft.max_select} ${
                    draft.max_select === 1
                      ? activePreset.noun.one
                      : activePreset.noun.many
                  } por item.`}
                  value={draft.max_select}
                  min={1}
                  onChange={(max) => setDraft(setMax(draft, max))}
                />
              </div>

              {/* A regra só muda alguma coisa quando cabe mais de uma escolha. */}
              {draft.max_select > 1 && (
                <Field
                  label="Como cobrar quando o cliente escolhe mais de um"
                  hint={
                    draft.pricing_rule === 'highest'
                      ? 'É o padrão para pizza meio a meio: duas metades custam o preço da mais cara.'
                      : draft.pricing_rule === 'average'
                        ? 'Soma os valores e divide pela quantidade escolhida.'
                        : 'Use para bordas e adicionais, onde cada extra tem seu preço.'
                  }
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
                    {(Object.keys(PRICING_LABELS) as PricingRule[]).map(
                      (rule) => (
                        <option key={rule} value={rule}>
                          {PRICING_LABELS[rule]}
                        </option>
                      ),
                    )}
                  </select>
                </Field>
              )}

              {/*
                Frase-resumo.
                Lê o mesmo estado que o payload, então descreve exatamente o que
                será salvo — inclusive quando a combinação escolhida é estranha.
              */}
              <p className="rounded-lg border border-accent/25 bg-accent/5 px-3.5 py-2.5 text-sm">
                {summarize(draft, optionCount)}
              </p>

              {draft.source === 'category' ? (
                <div>
                  <p className="text-xs font-medium text-muted">
                    {activePreset.noun.many[0].toUpperCase() +
                      activePreset.noun.many.slice(1)}{' '}
                    ofertados
                  </p>

                  {!draft.source_category_id ? (
                    <p className="mt-2 text-sm text-muted">
                      Selecione a categoria acima para listar os{' '}
                      {activePreset.noun.many}.
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
                            className="flex flex-wrap items-center gap-3 rounded-lg border border-line p-2.5"
                          >
                            <label className="flex flex-1 cursor-pointer items-center gap-2.5 text-sm">
                              <input
                                type="checkbox"
                                checked={Boolean(chosen)}
                                onChange={() => toggleOption(product.id)}
                                className="size-4 shrink-0 accent-[rgb(var(--accent))]"
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
                    Deixe o preço em branco para usar o do próprio produto.
                    Preencha para cobrar um valor diferente neste grupo.
                  </p>
                </div>
              ) : (
                <div>
                  <p className="text-xs font-medium text-muted">
                    {activePreset.noun.many[0].toUpperCase() +
                      activePreset.noun.many.slice(1)}
                  </p>

                  {draft.modifiers.length === 0 ? (
                    <p className="mt-2 text-sm text-muted">
                      Nenhuma opção ainda. Cada linha vira uma escolha na loja,
                      com o acréscimo que você definir.
                    </p>
                  ) : (
                    <ul className="mt-2 grid gap-2">
                      {draft.modifiers.map((modifier, index) => (
                        <li key={index} className="flex flex-wrap items-center gap-2">
                          <input
                            className="field min-w-[160px] flex-1"
                            placeholder={`Nome ${
                              activePreset.id === 'addons'
                                ? '(ex.: Catupiry)'
                                : activePreset.id === 'size'
                                  ? '(ex.: Grande)'
                                  : 'da opção'
                            }`}
                            value={modifier.name}
                            onChange={(e) =>
                              setDraft({
                                ...draft,
                                modifiers: draft.modifiers.map((m, i) =>
                                  i === index
                                    ? { ...m, name: e.target.value }
                                    : m,
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
                            className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"
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
                  )}

                  <button
                    type="button"
                    className="mt-2.5 rounded-lg border border-line px-3 py-1.5 text-sm font-medium transition-colors hover:bg-line/40"
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
                    + Adicionar {activePreset.noun.one}
                  </button>
                </div>
              )}

              {formError && (
                <p role="alert" className="rounded-lg bg-red-50 px-3.5 py-2.5 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
                  {formError}
                </p>
              )}

              {/* O que ainda falta, dito antes de o servidor recusar. */}
              {blockingIssue && !formError && (
                <p className="text-xs text-muted">{blockingIssue}</p>
              )}

              <div className="flex gap-2">
                <button
                  type="button"
                  className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-45"
                  disabled={save.isPending || Boolean(blockingIssue)}
                  onClick={() => save.mutate(draft)}
                >
                  {save.isPending ? 'Salvando…' : 'Salvar grupo'}
                </button>

                <button
                  type="button"
                  className="rounded-lg px-4 py-2 text-sm font-medium text-muted hover:bg-line"
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
        !picking &&
        !draft && (
          <EmptyState
            title="Nenhum grupo de opções"
            description="Crie um grupo para oferecer sabores de pizza, bordas, adicionais ou tamanhos."
            action={
              <button
                type="button"
                onClick={() => setPicking(true)}
                className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90"
              >
                Criar primeiro grupo
              </button>
            }
          />
        )
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

                <div className="flex shrink-0 gap-1">
                  <button
                    type="button"
                    className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted hover:bg-line"
                    onClick={() => edit(group)}
                  >
                    Editar
                  </button>

                  <button
                    type="button"
                    className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"
                    onClick={() => {
                      if (confirm(`Excluir o grupo "${group.name}"?`)) {
                        remove.mutate(group.id);
                      }
                    }}
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

/**
 * Contador com botões.
 *
 * Substitui `<input type="number">` porque no celular o teclado numérico
 * abrindo por cima do formulário para digitar "2" custa mais do que tocar em
 * um botão, e porque o valor aqui nunca passa de dezenas.
 */
function Stepper({
  label,
  hint,
  value,
  min,
  onChange,
}: {
  label: string;
  hint?: string;
  value: number;
  min: number;
  onChange: (value: number) => void;
}) {
  return (
    <Field label={label} hint={hint}>
      <div className="flex items-center gap-2">
        <button
          type="button"
          aria-label={`Diminuir ${label.toLowerCase()}`}
          disabled={value <= min}
          onClick={() => onChange(value - 1)}
          className="grid size-9 shrink-0 place-items-center rounded-lg border border-line text-base font-medium transition-colors hover:bg-line/40 disabled:opacity-40"
        >
          −
        </button>

        <input
          type="number"
          inputMode="numeric"
          min={min}
          max={50}
          aria-label={label}
          value={value}
          onChange={(e) => {
            const parsed = Number(e.target.value);
            onChange(
              Number.isNaN(parsed) ? min : Math.min(50, Math.max(min, parsed)),
            );
          }}
          className="field w-16 text-center font-mono"
        />

        <button
          type="button"
          aria-label={`Aumentar ${label.toLowerCase()}`}
          disabled={value >= 50}
          onClick={() => onChange(value + 1)}
          className="grid size-9 shrink-0 place-items-center rounded-lg border border-line text-base font-medium transition-colors hover:bg-line/40 disabled:opacity-40"
        >
          +
        </button>
      </div>
    </Field>
  );
}
