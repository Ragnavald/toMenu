import { useState } from 'react';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import {
  formatDate,
  platformFetch,
  type StoreListResponse,
  type StoreStatus,
} from '@/lib/platform';
import { PageHeader } from '@/components/ui';
import { StatusPill } from '@/components/platform/status-pill';

const FILTERS: { value: StoreStatus | 'all'; label: string }[] = [
  { value: 'all', label: 'Todas' },
  { value: 'active', label: 'Ativas' },
  { value: 'trial', label: 'Em teste' },
  { value: 'suspended', label: 'Suspensas' },
  { value: 'deleted', label: 'Excluídas' },
];

export function PlatformStoresPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<StoreStatus | 'all'>('all');
  const [page, setPage] = useState(1);

  const query = useQuery({
    queryKey: ['platform', 'stores', { search, status, page }],
    queryFn: () => {
      const params = new URLSearchParams({ page: String(page) });
      if (search) params.set('search', search);
      if (status !== 'all') params.set('status', status);

      return platformFetch<StoreListResponse>(`/stores?${params}`);
    },
    // Sem isto a tabela pisca em branco a cada tecla digitada na busca; manter
    // o resultado anterior enquanto o novo chega mantém a leitura estável.
    placeholderData: keepPreviousData,
  });

  const totals = query.data?.totals;

  return (
    <div>
      <PageHeader
        title="Lojas"
        description="Todas as lojas da plataforma."
      />

      {totals && (
        <div className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Stat label="No ar" value={totals.live} />
          <Stat label="Em teste" value={totals.trial} />
          <Stat label="Suspensas" value={totals.suspended} />
          <Stat label="Excluídas" value={totals.deleted} />
        </div>
      )}

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <input
          type="search"
          value={search}
          onChange={(event) => {
            setSearch(event.target.value);
            setPage(1); // Um termo novo recomeça da primeira página.
          }}
          placeholder="Buscar por nome ou endereço"
          className="field max-w-xs"
          aria-label="Buscar lojas"
        />

        <div className="flex flex-wrap gap-1">
          {FILTERS.map((filter) => (
            <button
              key={filter.value}
              type="button"
              onClick={() => {
                setStatus(filter.value);
                setPage(1);
              }}
              className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                status === filter.value
                  ? 'bg-accent/10 text-accent'
                  : 'text-muted hover:bg-line/60'
              }`}
            >
              {filter.label}
            </button>
          ))}
        </div>
      </div>

      {query.isError && (
        <p role="alert" className="panel p-4 text-sm text-red-600">
          Não foi possível carregar as lojas.
        </p>
      )}

      {query.isPending && <p className="text-sm text-muted">Carregando…</p>}

      {query.data && query.data.data.length === 0 && (
        <p className="panel p-8 text-center text-sm text-muted">
          Nenhuma loja encontrada.
        </p>
      )}

      {query.data && query.data.data.length > 0 && (
        // A tabela rola sozinha em telas estreitas; sem isto a página inteira
        // ganharia rolagem horizontal.
        <div className="panel overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b border-line text-left text-xs text-muted">
                <th className="px-4 py-2.5 font-medium">Loja</th>
                <th className="px-4 py-2.5 font-medium">Situação</th>
                <th className="px-4 py-2.5 text-right font-medium">Pedidos</th>
                <th className="px-4 py-2.5 text-right font-medium">Produtos</th>
                <th className="px-4 py-2.5 font-medium">Último pedido</th>
                <th className="px-4 py-2.5 font-medium">Criada em</th>
              </tr>
            </thead>

            <tbody>
              {query.data.data.map((store) => (
                <tr
                  key={store.id}
                  className="border-b border-line/60 last:border-0 hover:bg-line/30"
                >
                  <td className="px-4 py-3">
                    <Link
                      to={`/plataforma/lojas/${store.slug}`}
                      className="font-medium hover:text-accent"
                    >
                      {store.name}
                    </Link>
                    <p className="text-xs text-muted">{store.slug}</p>
                  </td>
                  <td className="px-4 py-3">
                    <StatusPill status={store.status} />
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {store.ordersCount}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">
                    {store.productsCount}
                  </td>
                  <td className="px-4 py-3 text-muted">
                    {formatDate(store.lastOrderAt)}
                  </td>
                  <td className="px-4 py-3 text-muted">
                    {formatDate(store.createdAt)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {query.data && query.data.meta.lastPage > 1 && (
        <div className="mt-4 flex items-center justify-between gap-3">
          <p className="text-xs text-muted">
            Página {query.data.meta.currentPage} de {query.data.meta.lastPage} ·{' '}
            {query.data.meta.total} loja(s)
          </p>

          <div className="flex gap-2">
            <button
              type="button"
              disabled={query.data.meta.currentPage <= 1}
              onClick={() => setPage((current) => current - 1)}
              className="rounded-lg border border-line px-3 py-1.5 text-xs font-medium disabled:opacity-40"
            >
              Anterior
            </button>
            <button
              type="button"
              disabled={query.data.meta.currentPage >= query.data.meta.lastPage}
              onClick={() => setPage((current) => current + 1)}
              className="rounded-lg border border-line px-3 py-1.5 text-xs font-medium disabled:opacity-40"
            >
              Próxima
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="panel px-4 py-3">
      <p className="text-xs text-muted">{label}</p>
      <p className="mt-0.5 text-xl font-semibold tabular-nums">{value}</p>
    </div>
  );
}
