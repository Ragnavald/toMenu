import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch } from '@/lib/api';
import type { Category } from '@/lib/types';
import { EmptyState, PageHeader } from '@/components/ui';

export function CategoriesPage() {
  const queryClient = useQueryClient();
  const [name, setName] = useState('');
  const [editing, setEditing] = useState<{ id: number; name: string } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['categories'],
    queryFn: () => apiFetch<{ data: Category[] }>('/admin/categories'),
  });

  const categories = data?.data ?? [];

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['categories'] });
    // O cardápio depende das categorias; recarregar evita lista desatualizada.
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

  const update = useMutation({
    mutationFn: (input: { id: number; name?: string; is_active?: boolean }) =>
      apiFetch(`/admin/categories/${input.id}`, {
        method: 'PUT',
        body: JSON.stringify({
          name: input.name ?? categories.find((c) => c.id === input.id)?.name,
          is_active:
            input.is_active ??
            categories.find((c) => c.id === input.id)?.is_active,
        }),
      }),
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
                  </div>
                  <p className="text-xs text-muted">
                    {category.products_count ?? 0}{' '}
                    {(category.products_count ?? 0) === 1 ? 'item' : 'itens'}
                  </p>
                </>
              )}
            </div>

            {editing?.id !== category.id && (
              <div className="flex shrink-0 gap-1">
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
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
