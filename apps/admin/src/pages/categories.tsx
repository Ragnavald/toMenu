import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch } from '@/lib/api';
import type { Category, Settings } from '@/lib/types';
import { EmptyState, PageHeader } from '@/components/ui';
import {
  SEGMENT_OPTIONS,
  templateCategorias,
  menuTemplates,
  getSegmentLabel,
  type MenuTemplate,
} from '@/lib/templates';

export function CategoriesPage() {
  const queryClient = useQueryClient();
  const [name, setName] = useState('');
  const [editing, setEditing] = useState<{ id: number; name: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [openMenuId, setOpenMenuId] = useState<number | null>(null);

  const { data: settings } = useQuery({
    queryKey: ['settings'],
    queryFn: () => apiFetch<Settings>('/admin/settings'),
  });

  const [selectedSegment, setSelectedSegment] = useState<string>('');

  const { data, isLoading } = useQuery({
    queryKey: ['categories'],
    queryFn: () => apiFetch<{ data: Category[] }>('/admin/categories'),
  });

  const categories = data?.data ?? [];

  const activeSegment = selectedSegment || settings?.profile?.segment || '';
  const activeTemplates = activeSegment ? templateCategorias[activeSegment] : null;
  const activeMenuTemplate = activeSegment ? menuTemplates[activeSegment] : null;

  // Os sabores ficam de fora da prévia: são insumos do grupo composto, não
  // itens que o cliente vê no cardápio. Listá-los junto das pizzas faria o
  // lojista achar que "Sabor 1" é um produto à venda.
  const insumoRefs = new Set(
    activeMenuTemplate?.categorias.filter((c) => c.is_option_only).map((c) => c.ref),
  );
  const vendaveis =
    activeMenuTemplate?.produtos.filter((p) => !insumoRefs.has(p.categoria_ref)) ?? [];
  const insumos =
    activeMenuTemplate?.produtos.filter((p) => insumoRefs.has(p.categoria_ref)) ?? [];

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['categories'] });
    queryClient.invalidateQueries({ queryKey: ['products'] });
  }

  const create = useMutation({
    mutationFn: (categoryName: string) =>
      apiFetch('/admin/categories', {
        method: 'POST',
        body: JSON.stringify({ name: categoryName }),
      }),
    onSuccess: () => {
      invalidate();
      setName('');
      setError(null);
    },
  });

  const batchCreate = useMutation({
    mutationFn: (items: { name: string }[]) =>
      apiFetch('/admin/categories/batch', {
        method: 'POST',
        body: JSON.stringify({ categories: items }),
      }),
    onSuccess: () => {
      invalidate();
      setError(null);
    },
  });

  /**
   * Importa um modelo de cardápio inteiro (categorias + produtos + grupos).
   *
   * O template usa `ref` no lugar de id porque nada disso existe no banco
   * ainda; o backend resolve as ligações depois de criar cada entidade.
   */
  const importMenu = useMutation({
    mutationFn: (template: MenuTemplate) =>
      apiFetch('/admin/menu/import', {
        method: 'POST',
        body: JSON.stringify({
          categories: template.categorias.map((c) => ({
            ref: c.ref,
            name: c.nome,
            is_option_only: c.is_option_only ?? false,
          })),
          groups: template.grupos.map((g) => ({
            ref: g.ref,
            name: g.nome,
            min_select: g.min_select,
            max_select: g.max_select,
            is_required: g.is_required ?? false,
            source: g.source,
            source_category_ref: g.source_categoria_ref ?? null,
            pricing_rule: g.pricing_rule,
          })),
          products: template.produtos.map((p) => ({
            ref: p.ref,
            category_ref: p.categoria_ref,
            name: p.nome,
            description: p.descricao ?? null,
            price_cents: p.price_cents,
            group_refs: p.grupos_ref ?? [],
          })),
        }),
      }),
    onSuccess: () => {
      invalidate();
      // Só o import cria grupos de opções; as outras mutações desta tela
      // mexem apenas em categorias. Sem isto a tela de Opções seguiria com o
      // cache antigo e o grupo de sabores recém-criado não apareceria.
      queryClient.invalidateQueries({ queryKey: ['modifier-groups'] });
      setError(null);
    },
    onError: (caught) => {
      setError(
        caught instanceof ApiError
          ? caught.message
          : 'Não foi possível importar o modelo de cardápio.',
      );
    },
  });

  const update = useMutation({
    mutationFn: (input: {
      id: number;
      name?: string;
      is_active?: boolean;
      is_option_only?: boolean;
    }) => {
      // O PUT exige o registro inteiro: enviar só o campo alterado apagaria os
      // outros com os defaults da validação.
      const current = categories.find((c) => c.id === input.id);

      return apiFetch(`/admin/categories/${input.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          name: input.name ?? current?.name,
          is_active: input.is_active ?? current?.is_active,
          is_option_only: input.is_option_only ?? current?.is_option_only,
        }),
      });
    },
    onSuccess: () => {
      invalidate();
      setEditing(null);
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) =>
      apiFetch(`/admin/categories/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      invalidate();
      setError(null);
    },
    onError: (caught) => {
      setError(
        caught instanceof ApiError
          ? caught.message
          : 'Não foi possível remover a seção.',
      );
    },
  });

  const reorder = useMutation({
    mutationFn: (ids: number[]) =>
      apiFetch('/admin/categories/reorder', {
        method: 'POST',
        body: JSON.stringify({ ids }),
      }),
    onSuccess: invalidate,
  });

  /** Move uma seção uma posição para cima ou para baixo. */
  function move(index: number, direction: -1 | 1) {
    const target = index + direction;
    if (target < 0 || target >= categories.length) return;

    const ids = categories.map((c) => c.id);
    [ids[index], ids[target]] = [ids[target], ids[index]];
    reorder.mutate(ids);
  }

  return (
    <div>
      <PageHeader
        title="Categorias"
        description="As seções do seu cardápio, na ordem em que o cliente vê."
      />

      {/* Sugestão de Categorias por Segmento */}
      <div className="panel mb-4 p-3.5 space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <label className="text-xs font-semibold uppercase tracking-wide text-muted">
            Categorias do Tipo de Estabelecimento
          </label>
          <select
            value={activeSegment}
            onChange={(e) => setSelectedSegment(e.target.value)}
            className="field max-w-xs text-xs py-1 px-2.5"
          >
            <option value="">Selecione o tipo do estabelecimento...</option>
            {SEGMENT_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
        </div>

        {activeTemplates && activeTemplates.length > 0 && (
          <div className="space-y-2 border-t border-line pt-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-xs font-medium text-ink">
                Sugestões para {getSegmentLabel(activeSegment)}:
              </span>
              <button
                type="button"
                disabled={batchCreate.isPending}
                onClick={() => {
                  const items = activeTemplates.map((t) => ({
                    name: `${t.icone} ${t.nome}`,
                  }));
                  batchCreate.mutate(items);
                }}
                className="rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
              >
                {batchCreate.isPending ? 'Importando...' : '✨ Importar todas deste modelo'}
              </button>
            </div>
            <div className="flex flex-wrap gap-1.5">
              {activeTemplates.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  disabled={create.isPending}
                  onClick={() => create.mutate(`${item.icone} ${item.nome}`)}
                  className="rounded-full border border-line bg-surface px-3 py-1 text-xs font-medium transition-colors hover:bg-line/50"
                >
                  + {item.icone} {item.nome}
                </button>
              ))}
            </div>
          </div>
        )}

        {activeMenuTemplate && (
          <div className="space-y-2 border-t border-line pt-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="space-y-0.5">
                <span className="block text-xs font-medium text-ink">
                  Modelo pronto: {activeMenuTemplate.label}
                </span>
                <span className="block text-[11px] text-muted">
                  Cria {vendaveis.length} itens de cardápio e {insumos.length}{' '}
                  sabores de exemplo para você renomear. Os itens de dois sabores
                  já vêm com o grupo de sabores configurado.
                </span>
              </div>
              <button
                type="button"
                disabled={importMenu.isPending}
                onClick={() => {
                  // Diferente das seções vazias acima, desfazer um import é
                  // caro: são produtos e grupos item por item, e uma categoria
                  // com produtos dentro nem chega a ser excluída. Importar duas
                  // vezes duplica o cardápio inteiro.
                  const confirmed =
                    categories.length === 0 ||
                    window.confirm(
                      'Isto vai criar os itens do modelo além do que já existe no seu cardápio. Importar mesmo assim?',
                    );

                  if (confirmed) importMenu.mutate(activeMenuTemplate);
                }}
                className="rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
              >
                {importMenu.isPending ? 'Importando...' : '🍕 Importar cardápio modelo'}
              </button>
            </div>
            <div className="flex flex-wrap gap-1.5">
              {vendaveis.map((item) => (
                <span
                  key={item.ref}
                  className="rounded-full border border-line bg-surface px-3 py-1 text-xs font-medium text-muted"
                >
                  {item.nome}
                </span>
              ))}
            </div>
          </div>
        )}
      </div>

      <form
        onSubmit={(event) => {
          event.preventDefault();
          if (name.trim()) create.mutate(name.trim());
        }}
        className="panel mb-4 flex gap-2 p-3"
      >
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Nova seção — ex.: Sobremesas"
          className="field"
          aria-label="Nome da nova seção"
        />
        <button
          type="submit"
          disabled={!name.trim() || create.isPending}
          className="shrink-0 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
        >
          Adicionar
        </button>
      </form>

      {error && (
        <p role="alert" className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950/40 dark:text-red-300">
          {error}
        </p>
      )}

      {isLoading && <p className="text-sm text-muted">Carregando…</p>}

      {!isLoading && categories.length === 0 && (
        <EmptyState
          title="Nenhuma seção ainda"
          description="Crie seções como Entradas, Pratos principais e Bebidas para organizar o cardápio."
        />
      )}

      <ul className="grid gap-2">
        {categories.map((category, index) => (
          <li
            key={category.id}
            className="panel flex flex-wrap items-center gap-3 p-3.5"
          >
            <div className="flex shrink-0 flex-col">
              <button
                type="button"
                onClick={() => move(index, -1)}
                disabled={index === 0}
                aria-label={`Mover ${category.name} para cima`}
                className="grid size-5 place-items-center rounded text-muted hover:bg-line disabled:opacity-25"
              >
                ▲
              </button>
              <button
                type="button"
                onClick={() => move(index, 1)}
                disabled={index === categories.length - 1}
                aria-label={`Mover ${category.name} para baixo`}
                className="grid size-5 place-items-center rounded text-muted hover:bg-line disabled:opacity-25"
              >
                ▼
              </button>
            </div>

            <div className="min-w-0 flex-1">
              {editing?.id === category.id ? (
                <form
                  onSubmit={(event) => {
                    event.preventDefault();
                    update.mutate({ id: category.id, name: editing.name });
                  }}
                  className="flex gap-2"
                >
                  <input
                    autoFocus
                    value={editing.name}
                    onChange={(e) =>
                      setEditing({ ...editing, name: e.target.value })
                    }
                    className="field"
                  />
                  <button
                    type="submit"
                    className="shrink-0 rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white"
                  >
                    Salvar
                  </button>
                  <button
                    type="button"
                    onClick={() => setEditing(null)}
                    className="shrink-0 rounded-lg px-3 py-1.5 text-xs text-muted hover:bg-line"
                  >
                    Cancelar
                  </button>
                </form>
              ) : (
                <>
                  <div className="flex items-center gap-2">
                    <p className="truncate text-sm font-medium">
                      {category.name}
                    </p>
                    {!category.is_active && (
                      <span className="rounded-md bg-zinc-100 px-1.5 py-0.5 text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                        Oculta
                      </span>
                    )}
                    {category.is_option_only && (
                      <span className="rounded-md bg-amber-100 px-1.5 py-0.5 text-[11px] text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                        Só opção
                      </span>
                    )}
                  </div>
                  <p className="text-xs text-muted">
                    {category.products_count ?? 0}{' '}
                    {(category.products_count ?? 0) === 1 ? 'item' : 'itens'}
                    {category.is_option_only &&
                      ' · não aparece como seção do cardápio'}
                  </p>
                </>
              )}
            </div>

            {editing?.id !== category.id && (
              <>
                {/* Desktop Buttons */}
                <div className="hidden sm:flex shrink-0 gap-1">
                  <button
                    type="button"
                    onClick={() =>
                      update.mutate({
                        id: category.id,
                        is_active: !category.is_active,
                      })
                    }
                    className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted hover:bg-line"
                  >
                    {category.is_active ? 'Ocultar' : 'Mostrar'}
                  </button>
                  {/*
                    Marca a seção como insumo de grupos compostos: os sabores de
                    pizza precisam existir como produtos, mas não como uma seção
                    vendável logo abaixo dos tamanhos.
                  */}
                  <button
                    type="button"
                    onClick={() =>
                      update.mutate({
                        id: category.id,
                        is_option_only: !category.is_option_only,
                      })
                    }
                    title={
                      category.is_option_only
                        ? 'Voltar a exibir esta seção no cardápio'
                        : 'Usar apenas como opção (sabores), sem aparecer no cardápio'
                    }
                    className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted hover:bg-line"
                  >
                    {category.is_option_only ? 'Usar no cardápio' : 'Só opção'}
                  </button>
                  <button
                    type="button"
                    onClick={() =>
                      setEditing({ id: category.id, name: category.name })
                    }
                    className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted hover:bg-line"
                  >
                    Renomear
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      if (confirm(`Remover a seção "${category.name}"?`)) {
                        remove.mutate(category.id);
                      }
                    }}
                    className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"
                  >
                    Remover
                  </button>
                </div>

                {/* Mobile Dropdown Options */}
                <div className="sm:hidden relative">
                  <button
                    type="button"
                    onClick={() => setOpenMenuId(category.id)}
                    className="grid size-8 place-items-center rounded-lg text-muted hover:bg-line"
                    aria-label="Opções"
                  >
                    <svg
                      viewBox="0 0 24 24"
                      width="20"
                      height="20"
                      stroke="currentColor"
                      strokeWidth="2.5"
                      fill="none"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      className="size-5"
                    >
                      <circle cx="12" cy="12" r="1.25" fill="currentColor"></circle>
                      <circle cx="12" cy="5" r="1.25" fill="currentColor"></circle>
                      <circle cx="12" cy="19" r="1.25" fill="currentColor"></circle>
                    </svg>
                  </button>

                  {openMenuId === category.id && (
                    <>
                      <div
                        className="fixed inset-0 z-10"
                        onClick={(e) => {
                          e.stopPropagation();
                          setOpenMenuId(null);
                        }}
                      />
                      <div className="absolute right-0 mt-1 z-20 w-48 rounded-lg border border-line bg-panel py-1 shadow-lg">
                        <button
                          type="button"
                          onClick={() => {
                            setOpenMenuId(null);
                            update.mutate({
                              id: category.id,
                              is_active: !category.is_active,
                            });
                          }}
                          className="flex w-full items-center px-4 py-2.5 text-left text-xs font-medium text-ink hover:bg-line/40 transition-colors"
                        >
                          {category.is_active ? 'Ocultar' : 'Mostrar'}
                        </button>
                        <button
                          type="button"
                          onClick={() => {
                            setOpenMenuId(null);
                            update.mutate({
                              id: category.id,
                              is_option_only: !category.is_option_only,
                            });
                          }}
                          className="flex w-full items-center px-4 py-2.5 text-left text-xs font-medium text-ink hover:bg-line/40 transition-colors"
                        >
                          {category.is_option_only ? 'Usar no cardápio' : 'Só opção'}
                        </button>
                        <button
                          type="button"
                          onClick={() => {
                            setOpenMenuId(null);
                            setEditing({ id: category.id, name: category.name });
                          }}
                          className="flex w-full items-center px-4 py-2.5 text-left text-xs font-medium text-ink hover:bg-line/40 transition-colors"
                        >
                          Renomear
                        </button>
                        <div className="my-1 border-t border-line" />
                        <button
                          type="button"
                          onClick={() => {
                            setOpenMenuId(null);
                            if (confirm(`Remover a seção "${category.name}"?`)) {
                              remove.mutate(category.id);
                            }
                          }}
                          className="flex w-full items-center px-4 py-2.5 text-left text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
                        >
                          Remover
                        </button>
                      </div>
                    </>
                  )}
                </div>
              </>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
