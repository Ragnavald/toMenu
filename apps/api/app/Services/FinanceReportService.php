<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Consultas do painel financeiro.
 *
 * Centraliza a definição de "receita" num lugar só. A tela e o PDF usam este
 * mesmo serviço de propósito: se cada um montasse a própria query, um relatório
 * impresso poderia divergir do número exibido na tela — e o dono do restaurante
 * não teria como saber qual dos dois está certo.
 */
class FinanceReportService
{
    /**
     * Só entra na receita o que virou dinheiro de fato.
     *
     * Um pedido entregue mas com pagamento pendente ainda não é caixa, e um
     * pedido pago mas cancelado normalmente vira estorno. Exigir os dois é o
     * critério que bate com o fechamento do restaurante.
     */
    public const REVENUE_STATUS = 'delivered';

    public const REVENUE_PAYMENT_STATUS = 'paid';

    /**
     * Colunas do CSV, na ordem em que saem.
     *
     * @var list<string>
     */
    public const EXPORT_HEADER = [
        'Pedido', 'Data', 'Cliente', 'Telefone', 'Pagamento',
        'Entrega', 'Subtotal', 'Taxa de entrega', 'Total',
    ];

    /**
     * Query base do período, já com os filtros de receita aplicados.
     *
     * `placed_at` é a data de referência (quando o cliente pediu), não
     * `created_at`: são iguais hoje, mas um pedido importado ou remarcado
     * quebraria o relatório se ele seguisse a data da linha no banco.
     */
    public function revenueQuery(Carbon $from, Carbon $to): Builder
    {
        return Order::query()
            ->where('status', self::REVENUE_STATUS)
            ->where('payment_status', self::REVENUE_PAYMENT_STATUS)
            ->whereBetween('placed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    /**
     * Linhas da exportação, uma por vez, sem carregar o período na memória.
     *
     * Três decisões sustentam o consumo constante de RAM, e nenhuma é opcional:
     *
     *   `toBase()`   devolve o query builder cru por baixo do Eloquent. Cada
     *                linha vira um stdClass em vez de um model hidratado — o
     *                model traz atributos originais, casts e relações, e é o
     *                que fazia a versão antiga acumular. O filtro por tenant
     *                NÃO se perde: ele já foi aplicado pelo global scope na
     *                montagem da query, e o RLS do Postgres é a rede de baixo.
     *
     *   `cursor()`   percorre o resultado em streaming (PDO unbuffered), então
     *                a memória não cresce com o número de pedidos. `get()` e
     *                até `chunkById()` materializam lotes; aqui nada é
     *                acumulado, porque quem consome escreve direto na saída.
     *
     *   `leftJoin`   o nome do cliente vem na mesma query. Com `with()` seria
     *                um segundo SELECT por lote, e com acesso lazy seria um
     *                por pedido — o N+1 clássico, que num cursor de 100 mil
     *                linhas significa 100 mil consultas.
     *
     * `leftJoin` e não `join`: pedido sem cliente (balcão, cadastro removido)
     * precisa aparecer no relatório financeiro, senão a soma do CSV não bate
     * com o total exibido na tela.
     *
     * @return LazyCollection<int, \stdClass>
     */
    public function exportCursor(Carbon $from, Carbon $to, string $search = ''): LazyCollection
    {
        return $this->applySearch($this->revenueQuery($from, $to), $search)
            ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
            ->orderBy('orders.placed_at')
            ->orderBy('orders.id') // Desempate estável entre pedidos do mesmo instante.
            ->select([
                'orders.number',
                'orders.placed_at',
                'customers.name AS customer_name',
                'customers.phone AS customer_phone',
                'orders.payment_method',
                'orders.fulfillment',
                'orders.subtotal_cents',
                'orders.delivery_fee_cents',
                'orders.total_cents',
            ])
            ->toBase()
            ->cursor();
    }

    /**
     * Totais do período: receita, número de pedidos e ticket médio.
     *
     * Uma agregação só no banco, em vez de somar em PHP — com meses de pedidos
     * carregar tudo em memória só para somar é desperdício puro.
     */
    public function summary(Carbon $from, Carbon $to, string $search = ''): array
    {
        $row = $this->applySearch($this->revenueQuery($from, $to), $search)
            ->selectRaw('COALESCE(SUM(total_cents), 0) AS revenue_cents')
            ->selectRaw('COALESCE(SUM(delivery_fee_cents), 0) AS delivery_cents')
            ->selectRaw('COALESCE(SUM(subtotal_cents), 0) AS subtotal_cents')
            ->selectRaw('COUNT(*) AS orders_count')
            ->first();

        $orders = (int) ($row->orders_count ?? 0);
        $revenue = (int) ($row->revenue_cents ?? 0);

        return [
            'revenueCents' => $revenue,
            'subtotalCents' => (int) ($row->subtotal_cents ?? 0),
            'deliveryCents' => (int) ($row->delivery_cents ?? 0),
            'ordersCount' => $orders,
            // Divisão inteira protegida: sem pedidos no período o ticket é zero,
            // não uma divisão por zero.
            'averageTicketCents' => $orders > 0 ? intdiv($revenue, $orders) : 0,
        ];
    }

    /**
     * Receita agrupada por mês, para o gráfico de tendência.
     *
     * Devolve todos os meses do intervalo, inclusive os sem venda — um mês
     * ausente viraria um buraco no gráfico, sugerindo falha de dados em vez de
     * ausência de pedidos.
     */
    public function monthlyRevenue(Carbon $from, Carbon $to, string $search = ''): Collection
    {
        $rows = $this->applySearch($this->revenueQuery($from, $to), $search)
            ->selectRaw("to_char(placed_at, 'YYYY-MM') AS month")
            ->selectRaw('COALESCE(SUM(total_cents), 0) AS revenue_cents')
            ->selectRaw('COUNT(*) AS orders_count')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $months = collect();
        // O cursor precisa estar no dia 1: `addMonth()` sobre dia 31 transborda
        // para o mês seguinte (31/01 vira 03/03) e pula meses inteiros da série.
        $cursor = $from->copy()->startOfMonth();
        $limit = $to->copy()->startOfMonth();

        while ($cursor->lte($limit)) {
            $key = $cursor->format('Y-m');
            $found = $rows->get($key);

            $months->push([
                'month' => $key,
                'label' => $cursor->locale('pt_BR')->isoFormat('MMM/YY'),
                'revenueCents' => (int) ($found->revenue_cents ?? 0),
                'ordersCount' => (int) ($found->orders_count ?? 0),
            ]);

            $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /** Receita por dia — alimenta o gráfico quando o intervalo é curto. */
    public function dailyRevenue(Carbon $from, Carbon $to, string $search = ''): Collection
    {
        $rows = $this->applySearch($this->revenueQuery($from, $to), $search)
            ->selectRaw("to_char(placed_at, 'YYYY-MM-DD') AS day")
            ->selectRaw('COALESCE(SUM(total_cents), 0) AS revenue_cents')
            ->selectRaw('COUNT(*) AS orders_count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $days = collect();
        $cursor = $from->copy()->startOfDay();
        $limit = $to->copy()->startOfDay();

        while ($cursor->lte($limit)) {
            $key = $cursor->format('Y-m-d');
            $found = $rows->get($key);

            $days->push([
                'day' => $key,
                'label' => $cursor->format('d/m'),
                'revenueCents' => (int) ($found->revenue_cents ?? 0),
                'ordersCount' => (int) ($found->orders_count ?? 0),
            ]);

            $cursor->addDay();
        }

        return $days;
    }

    /** Composição da receita por forma de pagamento. */
    public function revenueByPaymentMethod(Carbon $from, Carbon $to, string $search = ''): Collection
    {
        return $this->applySearch($this->revenueQuery($from, $to), $search)
            ->selectRaw('payment_method')
            ->selectRaw('COALESCE(SUM(total_cents), 0) AS revenue_cents')
            ->selectRaw('COUNT(*) AS orders_count')
            ->groupBy('payment_method')
            ->orderByDesc('revenue_cents')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->payment_method,
                'revenueCents' => (int) $row->revenue_cents,
                'ordersCount' => (int) $row->orders_count,
            ]);
    }

    /**
     * Aplica a busca textual sobre número do pedido, cliente ou item.
     *
     * Usa ILIKE por ser Postgres: `like` diferencia maiúsculas ali, e procurar
     * por "maria" não acharia "Maria" — exatamente o caso comum.
     */
    public function applySearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $sub) use ($search) {
            $numeric = ltrim($search, '#');

            if ($numeric !== '' && ctype_digit($numeric)) {
                $sub->orWhere('number', (int) $numeric);
            }

            $sub->orWhereHas('customer', fn (BuilderContract $c) => $c
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('phone', 'ilike', "%{$search}%"))
                ->orWhereHas('items', fn (BuilderContract $i) => $i
                    ->where('product_name', 'ilike', "%{$search}%"));
        });
    }
}
