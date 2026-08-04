import { useEffect, useState, useSyncExternalStore } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch, formatMoney } from '@/lib/api';
import {
  readDeliveredByDrag,
  writeDeliveredByDrag,
} from '@/lib/delivery-release';
import { connectionStore } from '@/lib/echo';
import {
  FULFILLMENT_LABELS,
  ORDER_STATUSES,
  paymentLabelFor,
  STATUS_LABELS,
  type Fulfillment,
  type Order,
  type Paginated,
} from '@/lib/types';

/**
 * Próximo passo natural do fluxo, para virar o status com um clique só.
 *
 * Depois de pronto o caminho diverge pela modalidade: só a entrega passa por
 * "saiu para entrega". Retirada e consumo no local vão de "pronto" direto para
 * concluído — empurrá-los para a rota do motoboy encheria a coluna de entrega
 * com pedidos que nunca saem da loja.
 */
function nextStatus(order: Order): string | null {
  switch (order.status) {
    case 'confirmed':
      return 'preparing';
    case 'preparing':
      return 'ready';
    case 'ready':
      return order.fulfillment === 'delivery' ? 'out_for_delivery' : 'delivered';
    case 'out_for_delivery':
      return 'delivered';
    default:
      return null;
  }
}

/*
 * Largura a partir da qual o Kanban cabe na tela.
 *
 * Mesmo valor do breakpoint `sm` do Tailwind, usado no seletor de visão logo
 * abaixo. Os dois precisam concordar: se o seletor aparecesse numa largura em
 * que o Kanban não é oferecido, o botão "Kanban" não faria nada.
 */
const KANBAN_MIN_WIDTH = '(min-width: 640px)';

/**
 * Acompanha uma media query.
 *
 * `useSyncExternalStore` em vez de `useState` + `useEffect` porque o valor
 * mora fora do React: assim a primeira renderização já lê a largura real, sem
 * o frame intermediário em que o celular mostraria o Kanban antes de corrigir.
 */
function useMediaQuery(query: string): boolean {
  return useSyncExternalStore(
    (onChange) => {
      const list = window.matchMedia(query);
      list.addEventListener('change', onChange);

      return () => list.removeEventListener('change', onChange);
    },
    () => window.matchMedia(query).matches,
    () => true, // No SSR não há janela; o desktop é o padrão histórico da tela.
  );
}

const KANBAN_COLUMNS: { key: string; label: string; tone: string }[] = [
  {
    key: 'confirmed',
    label: 'Confirmados',
    tone: 'border-blue-500/30 bg-blue-50/30 dark:bg-blue-950/10',
  },
  {
    key: 'preparing',
    label: 'Em Preparo',
    tone: 'border-indigo-500/30 bg-indigo-50/30 dark:bg-indigo-950/10',
  },
  {
    key: 'ready',
    label: 'Prontos',
    tone: 'border-emerald-500/30 bg-emerald-50/30 dark:bg-emerald-950/10',
  },
  {
    key: 'out_for_delivery',
    label: 'Em Entrega',
    tone: 'border-cyan-500/30 bg-cyan-50/30 dark:bg-cyan-950/10',
  },
  {
    key: 'delivered',
    label: 'Entregues',
    tone: 'border-zinc-500/30 bg-zinc-50/20 dark:bg-zinc-900/20',
  },
];

export function OrdersPage() {
  const queryClient = useQueryClient();
  const [filter, setFilter] = useState<string>('');
  const [search, setSearch] = useState<string>('');
  const [viewMode, setViewMode] = useState<'kanban' | 'list'>('kanban');

  /*
   * No celular a lista não é uma preferência, é a única visão possível.
   *
   * O Kanban tem colunas de 280px de largura mínima e 680px de altura mínima
   * dentro de um scroll horizontal, e muda o status por drag and drop do
   * HTML5 — que não dispara em toque. Numa tela estreita isso é um quadro que
   * não cabe e cujos cards não se movem. Pior: o seletor de visão é
   * `hidden sm:flex`, então o lojista no celular também não tinha como sair
   * dali.
   *
   * Por isso a escolha do usuário só vale acima do breakpoint; abaixo dele a
   * visão é decidida pela largura, não pelo estado.
   */
  const fitsKanban = useMediaQuery(KANBAN_MIN_WIDTH);
  const effectiveViewMode = fitsKanban ? viewMode : 'list';

  // Decide a cadência do polling abaixo: com o tempo real vivo ele é apenas
  // reconciliação; sem ele, é o único aviso de pedido novo.
  const socketConnected = useSyncExternalStore(
    connectionStore.subscribe,
    connectionStore.getSnapshot,
    () => false, // No SSR não há socket; assume desconectado e faz polling.
  );

  // Drag and drop state
  const [draggingId, setDraggingId] = useState<number | null>(null);
  const [dragOverColumn, setDragOverColumn] = useState<string | null>(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['orders', filter, search],
    queryFn: () => {
      const params = new URLSearchParams();
      if (filter) params.set('status', filter);
      if (search.trim()) params.set('search', search.trim());
      params.set('per_page', '100'); // Traz até 100 pedidos recentes para fluidez no Kanban
      const queryString = params.toString();
      return apiFetch<Paginated<Order>>(
        `/admin/orders${queryString ? `?${queryString}` : ''}`,
      );
    },
    /*
     * O polling é rede de segurança, não o mecanismo de aviso.
     *
     * Quem anuncia pedido novo é o WebSocket, que já invalida esta query ao
     * receber o evento. Com o socket conectado, buscar de 15 em 15 segundos é
     * repetir de graça uma consulta com dois `whereHas` e eager loading —
     * multiplicada por cada painel aberto, é a chamada mais cara da plataforma
     * e quase sempre devolve exatamente o que o cliente já tem.
     *
     * O intervalo longo com o socket vivo cobre o que o tempo real não cobre:
     * status mudado por outro operador, e o evento perdido enquanto a aba
     * dormia. Sem socket, volta à cadência antiga — aí o polling é o único
     * aviso que a cozinha tem.
     */
    refetchInterval: socketConnected ? 120_000 : 15_000,
  });

  /*
   * Um erro aqui precisa aparecer na tela.
   *
   * O card volta sozinho para a coluna de origem quando a chamada falha (a
   * lista é reidratada do servidor), e sem aviso isso é indistinguível de um
   * arraste que não pegou — o lojista tenta de novo achando que errou a mira,
   * enquanto o pedido segue no status antigo.
   */
  const [statusError, setStatusError] = useState<string | null>(null);

  /**
   * Pedidos que chegaram em "Entregue" pelo arraste.
   *
   * O botão Finalizar só libera para estes: por decisão de produto, o gesto de
   * arrastar até a coluna é o que confirma a entrega — mudar pelo select move o
   * pedido, mas não autoriza tirá-lo do painel.
   *
   * Persistido no localStorage para atravessar o F5 e a troca de aba. Detalhes
   * do armazenamento em `lib/delivery-release`.
   */
  const [draggedToDelivered, setDraggedToDelivered] = useState<Set<number>>(
    readDeliveredByDrag,
  );

  /** Atualiza a liberação e grava, mantendo as duas pontas em sincronia. */
  const updateReleased = (mutate: (current: Set<number>) => Set<number>) => {
    setDraggedToDelivered((current) => {
      const next = mutate(current);
      if (next === current) {
        return current;
      }
      writeDeliveredByDrag(next);
      return next;
    });
  };

  const updateStatus = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) =>
      apiFetch(`/admin/orders/${id}/status`, {
        method: 'PATCH',
        body: JSON.stringify({ status }),
      }),
    onMutate: ({ id, status }) => {
      setStatusError(null);

      // Sair de "Entregue" por qualquer caminho revoga a liberação — inclusive
      // pelo select, que não passa pelo handleDrop.
      if (status !== 'delivered') {
        updateReleased((current) => {
          if (!current.has(id)) {
            return current;
          }
          const next = new Set(current);
          next.delete(id);
          return next;
        });
      }
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['orders'] }),
    onError: (caught) =>
      setStatusError(
        caught instanceof ApiError
          ? caught.message
          : 'Não foi possível mudar o status do pedido.',
      ),
  });

  /**
   * Tira o pedido do painel.
   *
   * Invalida `orders` — a lista do painel e a do histórico compartilham o
   * prefixo da chave, então o card sai de uma tela e aparece na outra sem que
   * nenhuma das duas precise saber da existência da outra.
   */
  const archiveOrder = useMutation({
    mutationFn: (id: number) =>
      apiFetch(`/admin/orders/${id}/archive`, { method: 'PATCH' }),
    onMutate: () => setStatusError(null),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['orders'] }),
    onError: (caught) =>
      setStatusError(
        caught instanceof ApiError
          ? caught.message
          : 'Não foi possível finalizar o pedido.',
      ),
  });

  const orders = data?.data ?? [];

  /**
   * Adota, uma única vez, os pedidos que já estavam entregues ao abrir a tela.
   *
   * Sem isto a regra do arraste prenderia no painel todo pedido entregue antes
   * desta sessão — inclusive os entregues antes de a regra existir — e nenhum
   * gesto os soltaria: o card já está na coluna de destino, então não há para
   * onde arrastar. O botão ficaria travado para sempre.
   *
   * Roda no `useEffect`, e não durante o render, porque grava estado e escreve
   * no localStorage: fazer isso no corpo do componente é efeito colateral em
   * render, que o StrictMode duplica e o React pode descartar.
   *
   * `adoptedFrom` limita a adoção à primeira carga. Depois dela, um pedido que
   * o lojista arrastar para fora de "Entregue" perde a liberação e não é
   * readotado no refetch seguinte — do contrário a revogação seria desfeita
   * sozinha, e a trava não valeria nada.
   */
  const [adoptedFrom, setAdoptedFrom] = useState<Paginated<Order> | null>(null);

  useEffect(() => {
    // Depende de `data` e não de `orders`: o segundo é um array novo a cada
    // render (`data?.data ?? []`), o que reexecutaria o efeito sem necessidade.
    if (!data || adoptedFrom === data) {
      return;
    }

    setAdoptedFrom(data);

    // Só a primeira carga adota. Nas seguintes o efeito apenas marca `data`
    // como visto, para não readotar um pedido cuja liberação foi revogada.
    if (adoptedFrom !== null) {
      return;
    }

    const alreadyDelivered = data.data
      .filter((order) => order.status === 'delivered')
      .map((order) => order.id);

    if (alreadyDelivered.length === 0) {
      return;
    }

    updateReleased((current) => {
      const missing = alreadyDelivered.filter((id) => !current.has(id));
      return missing.length > 0 ? new Set([...current, ...missing]) : current;
    });
  }, [adoptedFrom, data]);

  /*
   * "Em entrega" só faz sentido para pedidos que saem da loja. Numa casa que
   * atende apenas no salão a coluna seria uma faixa morta ocupando espaço
   * horizontal do kanban — mas ela precisa continuar existindo enquanto houver
   * qualquer pedido de entrega, inclusive como alvo de arraste.
   */
  const columns = orders.some((order) => order.fulfillment === 'delivery')
    ? KANBAN_COLUMNS
    : KANBAN_COLUMNS.filter((col) => col.key !== 'out_for_delivery');

  // Handlers do HTML5 Drag and Drop (Kanban)
  const handleDragStart = (e: React.DragEvent, orderId: number) => {
    e.dataTransfer.setData('text/plain', String(orderId));
    setDraggingId(orderId);
  };

  const handleDragOver = (e: React.DragEvent, columnKey: string) => {
    e.preventDefault();
    if (dragOverColumn !== columnKey) {
      setDragOverColumn(columnKey);
    }
  };

  const handleDragLeave = (e: React.DragEvent) => {
    e.preventDefault();
    setDragOverColumn(null);
  };

  const handleDrop = (e: React.DragEvent, targetStatus: string) => {
    e.preventDefault();
    setDragOverColumn(null);
    setDraggingId(null);

    const idStr = e.dataTransfer.getData('text/plain');
    const orderId = Number(idStr);

    if (orderId && !isNaN(orderId)) {
      const targetOrder = orders.find((o) => o.id === orderId);
      if (targetOrder && targetOrder.status !== targetStatus) {
        updateStatus.mutate({ id: orderId, status: targetStatus });

        /*
         * Só o arraste até "Entregue" libera o Finalizar. Largar o card em
         * qualquer outra coluna revoga a liberação: o pedido voltou atrás, e
         * deixar o botão ativo permitiria arquivar algo que saiu da entrega.
         */
        updateReleased((current) => {
          const next = new Set(current);
          if (targetStatus === 'delivered') {
            next.add(orderId);
          } else {
            next.delete(orderId);
          }
          return next;
        });
      }
    }
  };

  return (
    <div className="space-y-5">
      {/* Top Header & Controles */}
      <div className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <h1 className="text-lg font-semibold tracking-tight">Pedidos</h1>
            {/* Visual Switcher para Desktop */}
            <div className="hidden items-center rounded-lg border border-line bg-panel p-0.5 sm:flex">
              <button
                type="button"
                onClick={() => setViewMode('kanban')}
                className={`flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                  effectiveViewMode === 'kanban'
                    ? 'bg-accent text-white'
                    : 'text-muted hover:text-foreground'
                }`}
              >
                <svg className="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" />
                </svg>
                Kanban
              </button>
              <button
                type="button"
                onClick={() => setViewMode('list')}
                className={`flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors ${
                  effectiveViewMode === 'list'
                    ? 'bg-accent text-white'
                    : 'text-muted hover:text-foreground'
                }`}
              >
                <svg className="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                </svg>
                Lista
              </button>
            </div>
          </div>

          <div className="flex flex-wrap gap-1">
            {[
              { value: '', label: 'Todos' },
              ...ORDER_STATUSES.filter((s) => s !== 'pending_payment').map(
                (s) => ({
                  value: s,
                  label: STATUS_LABELS[s] ?? s,
                }),
              ),
            ].map((option) => (
              <button
                key={option.value}
                type="button"
                onClick={() => {
                  setFilter(option.value);
                  if (option.value === '') {
                    setViewMode('kanban');
                  }
                }}
                className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                  filter === option.value
                    ? 'bg-accent text-white'
                    : 'text-muted hover:bg-line'
                }`}
              >
                {option.label}
              </button>
            ))}
          </div>
        </div>

        <div className="relative">
          <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
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
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Pesquisar por nº do pedido (#1001), cliente, telefone ou item..."
            className="w-full rounded-lg border border-line bg-panel py-2 pl-9 pr-9 text-sm text-foreground transition-colors placeholder:text-muted focus:border-accent focus:outline-none"
          />
          {search && (
            <button
              type="button"
              onClick={() => setSearch('')}
              className="absolute inset-y-0 right-0 flex items-center pr-3 text-muted hover:text-foreground"
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

      {statusError && (
        <p
          role="alert"
          className="panel flex items-center justify-between gap-3 p-3 text-sm text-red-600"
        >
          {statusError}
          <button
            type="button"
            onClick={() => setStatusError(null)}
            className="shrink-0 text-xs font-medium text-muted hover:text-foreground"
          >
            Dispensar
          </button>
        </p>
      )}

      {isLoading && <p className="text-sm text-muted">Carregando pedidos…</p>}

      {isError && (
        <p className="panel p-4 text-sm text-red-600">
          Não foi possível carregar os pedidos.
        </p>
      )}

      {!isLoading && orders.length === 0 && (
        <div className="panel grid place-items-center px-6 py-16 text-center">
          <p className="text-sm font-medium">
            {search
              ? `Nenhum pedido encontrado para "${search}"`
              : 'Nenhum pedido por aqui'}
          </p>
          <p className="mt-1 text-sm text-muted">
            {search
              ? 'Tente buscar por outro número, nome de cliente ou telefone.'
              : 'Os pedidos aparecem automaticamente assim que chegarem.'}
          </p>
          {search && (
            <button
              type="button"
              onClick={() => setSearch('')}
              className="mt-3 rounded-lg border border-line px-3 py-1.5 text-xs font-medium text-muted transition-colors hover:bg-line hover:text-foreground"
            >
              Limpar pesquisa
            </button>
          )}
        </div>
      )}

      {/* KANBAN VIEW (Exibe Kanban quando estiver no modo Kanban E sem filtro de status específico selecionado) */}
      {!isLoading && orders.length > 0 && effectiveViewMode === 'kanban' && !filter && (
        <div className="flex overflow-x-auto pb-4 gap-4 scrollbar-thin">
          {columns.map((col) => {
            const columnOrders = orders.filter((o) => o.status === col.key);
            const isTarget = dragOverColumn === col.key;

            return (
              <div
                key={col.key}
                onDragOver={(e) => handleDragOver(e, col.key)}
                onDragLeave={handleDragLeave}
                onDrop={(e) => handleDrop(e, col.key)}
                className={`flex flex-col min-w-[280px] lg:min-w-[300px] flex-1 min-h-[680px] max-h-[calc(100vh-170px)] rounded-xl border p-3.5 transition-all ${
                  col.tone
                } ${
                  isTarget
                    ? 'ring-2 ring-accent border-accent bg-accent/10 shadow-lg scale-[1.01]'
                    : 'border-line/70'
                }`}
              >
                {/* Header da Coluna */}
                <div className="mb-3 flex items-center justify-between border-b border-line/60 pb-2.5">
                  <span className="text-xs font-bold uppercase tracking-wider text-foreground">
                    {col.label}
                  </span>
                  <span className="rounded-full bg-panel px-2.5 py-0.5 text-xs font-bold tabular-nums text-muted border border-line">
                    {columnOrders.length}
                  </span>
                </div>

                {/* Cards na Coluna */}
                <div className="flex-1 space-y-3 overflow-y-auto pr-0.5">
                  {columnOrders.length === 0 ? (
                    <div className="flex h-36 items-center justify-center rounded-lg border border-dashed border-line/60 text-center text-xs text-muted">
                      Arraste um pedido para cá
                    </div>
                  ) : (
                    columnOrders.map((order) => {
                      const isDragging = draggingId === order.id;

                      return (
                        <div
                          key={order.id}
                          draggable
                          onDragStart={(e) => handleDragStart(e, order.id)}
                          className={`panel cursor-grab active:cursor-grabbing p-3.5 shadow-xs transition-all hover:shadow-md ${
                            isDragging ? 'opacity-40 scale-95 border-dashed border-accent' : ''
                          }`}
                        >
                          <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                              <div className="flex flex-wrap items-center gap-1.5">
                                <span className="font-bold tabular-nums text-sm">
                                  #{order.number}
                                </span>
                                <FulfillmentBadge
                                  fulfillment={order.fulfillment}
                                />
                                {order.payment_status === 'paid' && (
                                  <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                    Pago
                                  </span>
                                )}
                              </div>
                              <p className="mt-0.5 text-xs font-medium text-foreground truncate">
                                {order.customer?.name ?? 'Cliente'}
                              </p>
                              <p className="text-[11px] text-muted truncate">
                                {order.customer?.phone ?? '—'}
                              </p>
                            </div>
                            <span className="text-xs font-bold tabular-nums text-foreground">
                              {formatMoney(order.total_cents)}
                            </span>
                          </div>

                          {/* Itens */}
                          <ul className="mt-2.5 divide-y divide-line/40 border-t border-line/40 pt-2 text-xs">
                            {order.items.map((item) => (
                              <li key={item.id} className="flex justify-between py-0.5 text-muted">
                                <span className="truncate">
                                  {item.quantity}× {item.product_name}
                                </span>
                              </li>
                            ))}
                          </ul>

                          {order.notes && (
                            <p className="mt-2 rounded bg-amber-50 p-1.5 text-[11px] text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                              {order.notes}
                            </p>
                          )}

                          {/*
                            O selo mostra em que estado o pedido está; o select
                            muda esse estado. No lugar do rótulo fixo "Status:"
                            porque a coluna do kanban some de vista assim que o
                            card é arrastado — e é justamente aí, com o card na
                            mão, que o lojista precisa confirmar onde ele caiu.
                          */}
                          <div className="mt-3 flex items-center justify-between gap-2 border-t border-line/40 pt-2">
                            <StatusBadge status={order.status} />
                            <select
                              value={order.status}
                              disabled={updateStatus.isPending}
                              onChange={(e) =>
                                updateStatus.mutate({
                                  id: order.id,
                                  status: e.target.value,
                                })
                              }
                              className="rounded border border-line bg-panel px-1.5 py-0.5 text-[11px] font-medium text-foreground focus:outline-none"
                            >
                              {ORDER_STATUSES.map((st) => (
                                <option key={st} value={st}>
                                  {STATUS_LABELS[st] ?? st}
                                </option>
                              ))}
                            </select>
                          </div>

                          <FinalizeButton
                            order={order}
                            onArchive={archiveOrder.mutate}
                            pending={archiveOrder.isPending}
                            released={
                              order.status === 'delivered' &&
                              draggedToDelivered.has(order.id)
                            }
                          />
                        </div>
                      );
                    })
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* LISTA VIEW (Exibida no modo lista OU quando há filtro de status específico selecionado) */}
      {!isLoading && orders.length > 0 && (effectiveViewMode === 'list' || Boolean(filter)) && (
        <ul className="grid gap-3">
          {orders.map((order) => (
            <li key={order.id} className="panel p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="font-semibold tabular-nums">
                      #{order.number}
                    </span>
                    <StatusBadge status={order.status} />
                    <FulfillmentBadge fulfillment={order.fulfillment} />
                    {order.payment_status === 'paid' && (
                      <span className="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                        Pago
                      </span>
                    )}
                  </div>

                  <p className="mt-1 text-sm text-muted">
                    {order.customer?.name ?? 'Cliente'} ·{' '}
                    {order.customer?.phone ?? '—'} ·{' '}
                    {paymentLabelFor(order.payment_method, order.fulfillment)}
                  </p>
                </div>

                <span className="text-sm font-semibold tabular-nums">
                  {formatMoney(order.total_cents)}
                </span>
              </div>

              <ul className="mt-3 grid gap-0.5 border-t border-line pt-3">
                {order.items.map((item) => (
                  <li key={item.id} className="flex justify-between text-sm">
                    <span className="text-muted">
                      {item.quantity}× {item.product_name}
                    </span>
                    <span className="tabular-nums text-muted">
                      {formatMoney(item.total_cents)}
                    </span>
                  </li>
                ))}
              </ul>

              {order.notes && (
                <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                  {order.notes}
                </p>
              )}

              {/* Controles de Status Mobile / Lista */}
              <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
                <div className="flex flex-wrap items-center gap-2">
                  {nextStatus(order) && (
                    <button
                      type="button"
                      disabled={updateStatus.isPending}
                      onClick={() =>
                        updateStatus.mutate({
                          id: order.id,
                          status: nextStatus(order)!,
                        })
                      }
                      className="rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
                    >
                      Avançar para {STATUS_LABELS[nextStatus(order)!]}
                    </button>
                  )}

                  {order.status !== 'cancelled' && order.status !== 'delivered' && (
                    <button
                      type="button"
                      disabled={updateStatus.isPending}
                      onClick={() =>
                        updateStatus.mutate({ id: order.id, status: 'cancelled' })
                      }
                      className="rounded-lg border border-line px-3 py-1.5 text-xs font-medium text-muted transition-colors hover:bg-line hover:text-foreground"
                    >
                      Cancelar
                    </button>
                  )}
                </div>

                {/* Selector Responsivo de Status */}
                <div className="flex items-center gap-1.5">
                  <label htmlFor={`status-select-${order.id}`} className="text-xs text-muted">
                    Status:
                  </label>
                  <select
                    id={`status-select-${order.id}`}
                    value={order.status}
                    disabled={updateStatus.isPending}
                    onChange={(e) =>
                      updateStatus.mutate({
                        id: order.id,
                        status: e.target.value,
                      })
                    }
                    className="rounded-lg border border-line bg-panel px-2 py-1 text-xs font-medium text-foreground focus:border-accent focus:outline-none"
                  >
                    {ORDER_STATUSES.map((st) => (
                      <option key={st} value={st}>
                        {STATUS_LABELS[st] ?? st}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              {/*
                No modo lista não existe coluna para arrastar, então aqui a
                liberação segue o status. É a saída para o celular e para quem
                filtra por status — do contrário o pedido entregue não teria
                como sair do painel.
              */}
              <FinalizeButton
                order={order}
                onArchive={archiveOrder.mutate}
                pending={archiveOrder.isPending}
                released={order.status === 'delivered'}
              />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * Modalidade do pedido, destacada por cor.
 *
 * É o primeiro dado que a operação precisa ler no card: define se o pedido sai
 * com o motoboy, espera no balcão ou vai para uma mesa do salão. As cores são
 * distintas das do status para os dois selos não se confundirem no mesmo card.
 */
function FulfillmentBadge({ fulfillment }: { fulfillment: Fulfillment }) {
  const tones: Record<Fulfillment, string> = {
    delivery: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
    pickup:
      'bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300',
    dine_in:
      'bg-orange-50 text-orange-700 dark:bg-orange-950/40 dark:text-orange-300',
  };

  return (
    <span
      className={`rounded-md px-1.5 py-0.5 text-[10px] font-semibold ${tones[fulfillment] ?? tones.delivery}`}
    >
      {FULFILLMENT_LABELS[fulfillment] ?? fulfillment}
    </span>
  );
}

/**
 * Encerramento do pedido, abaixo do status.
 *
 * Quem decide a liberação é quem renderiza (`released`), não este componente:
 * no kanban ela exige o arraste até a coluna "Entregue", e mudar pelo select
 * não basta. Deixar a regra fora daqui é o que permite ao modo lista, que não
 * tem para onde arrastar, liberar pelo status — sem isso o botão seria
 * inalcançável ali e no celular, onde o drag do HTML5 não dispara em toque.
 *
 * O botão bloqueado continua visível em vez de sumir porque ele é a explicação
 * de por que o pedido ainda está na tela: o lojista vê o que falta fazer antes
 * de poder limpar o card.
 */
function FinalizeButton({
  order,
  onArchive,
  pending,
  released,
}: {
  order: Order;
  onArchive: (id: number) => void;
  pending: boolean;
  released: boolean;
}) {
  /*
   * Um pedido já entregue mas ainda travado só acontece no kanban, e a razão é
   * sempre a mesma: chegou ali pelo select, sem o arraste. O aviso precisa
   * dizer isso, e não "espere ficar entregue" — o card já está na coluna, e
   * repetir o óbvio faria o botão parecer quebrado.
   */
  const awaitingDrag = order.status === 'delivered';

  const hint = awaitingDrag
    ? 'Arraste o card até a coluna Entregue para liberar'
    : 'Disponível quando o pedido estiver em Entregue';

  // O rótulo é a versão curta: o botão do kanban tem a largura da coluna, e a
  // frase inteira quebraria em três linhas. O title carrega o texto completo.
  const label = awaitingDrag
    ? 'Finalizar (arraste para Entregue)'
    : 'Finalizar pedido';

  return (
    <div className="mt-2 border-t border-line/40 pt-2">
      <button
        type="button"
        disabled={!released || pending}
        onClick={() => onArchive(order.id)}
        title={released ? 'Arquiva o pedido e tira o card do painel' : hint}
        className={`w-full rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors ${
          released
            ? 'bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-60'
            : 'cursor-not-allowed border border-dashed border-line text-muted'
        }`}
      >
        {released ? 'Finalizar pedido' : label}
      </button>
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const tones: Record<string, string> = {
    pending_payment:
      'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    confirmed: 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
    preparing:
      'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300',
    ready:
      'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    out_for_delivery:
      'bg-cyan-50 text-cyan-700 dark:bg-cyan-950/40 dark:text-cyan-300',
    delivered: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
    cancelled: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
  };

  return (
    <span
      className={`rounded-md px-1.5 py-0.5 text-[11px] font-medium ${
        tones[status] ?? tones.delivered
      }`}
    >
      {STATUS_LABELS[status] ?? status}
    </span>
  );
}
