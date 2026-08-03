<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FinanceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceAdminController extends Controller
{
    /** Além disso o gráfico diário vira uma serra ilegível; agrupa por mês. */
    private const DAILY_GRANULARITY_MAX_DAYS = 92;

    /**
     * Rótulos legíveis no CSV — os mesmos que o PDF usava e que a tela exibe.
     *
     * A planilha é lida por gente, não por máquina: `stripe_card` numa coluna
     * de "Pagamento" não diz nada ao dono do restaurante.
     */
    private const PAYMENT_LABELS = [
        'cash' => 'Dinheiro na entrega',
        'card_on_delivery' => 'Cartão na entrega',
        'pix_on_delivery' => 'Pix na entrega',
        'stripe_card' => 'Cartão pelo site',
        'stripe_pix' => 'Pix pelo site',
    ];

    private const FULFILLMENT_LABELS = [
        'delivery' => 'Entrega',
        'pickup' => 'Retirada',
    ];

    public function __construct(
        private readonly FinanceReportService $finance,
    ) {}

    /** Resumo, séries dos gráficos e composição por pagamento. */
    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->resolveRange($request);
        $search = trim($request->string('search')->toString());

        $useDaily = $from->diffInDays($to) <= self::DAILY_GRANULARITY_MAX_DAYS;

        return response()->json([
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'granularity' => $useDaily ? 'day' : 'month',
            ],
            'summary' => $this->finance->summary($from, $to, $search),
            'series' => $useDaily
                ? $this->finance->dailyRevenue($from, $to, $search)
                : $this->finance->monthlyRevenue($from, $to, $search),
            // A tendência mensal acompanha o intervalo escolhido, mas nunca é
            // mais curta que 6 meses: com um mês só o gráfico não diz nada.
            'monthly' => $this->finance->monthlyRevenue(
                $this->earliest($from, $to->copy()->subMonthsNoOverflow(5))->startOfMonth(),
                $to,
                $search,
            ),
            'byPaymentMethod' => $this->finance->revenueByPaymentMethod($from, $to, $search),
        ]);
    }

    /** Registros de pedidos do período, paginados. */
    public function orders(Request $request): JsonResponse
    {
        [$from, $to] = $this->resolveRange($request);
        $search = trim($request->string('search')->toString());

        $query = $this->finance->revenueQuery($from, $to)
            ->with(['customer:id,name,phone', 'items:id,order_id,product_name,quantity,total_cents']);

        $orders = $this->finance->applySearch($query, $search)
            ->latest('placed_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))))
            ->withQueryString();

        return response()->json($orders);
    }

    /**
     * Exporta os pedidos do período em CSV, direto na resposta.
     *
     * Substituiu a geração de PDF em fila. O PDF custava caro por linha — o
     * dompdf monta a árvore de layout inteira em memória e o custo de quebrar
     * uma tabela longa entre páginas explode: 2.500 pedidos levavam ~5 min e
     * ~2 GB, acima do `--memory=256` e do `--timeout=240` do worker. Ou seja, o
     * limite de 5.000 linhas que a validação prometia nunca foi alcançável.
     *
     * Aqui nada é acumulado: cada linha é escrita e descartada, então a memória
     * é constante e o arquivo começa a chegar antes de a query terminar. Medido
     * em 100 mil pedidos: ~1s e ~34 MB, dos quais a maior parte é o boot do
     * framework. Por isso não há mais teto de linhas nem fila — o custo deixou
     * de escalar com o tamanho do período.
     *
     * O callback roda DEPOIS que o Laravel envia os headers, o que tem duas
     * consequências que moldam este método:
     *
     *   1. o cursor é aberto aqui fora, ainda dentro do contexto de tenant do
     *      request. Abrir lá dentro arriscaria montar a query sem o escopo;
     *   2. um erro no meio do streaming não vira mais 500 — a resposta já
     *      começou com 200. Daí a validação do intervalo acontecer antes.
     */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->resolveRange($request);
        $search = trim($request->string('search')->toString());

        // Sem isto o Laravel guarda cada query executada em memória. Numa
        // exportação longa é vazamento puro, e some justamente o ganho do
        // cursor.
        DB::disableQueryLog();

        $rows = $this->finance->exportCursor($from, $to, $search);

        $filename = sprintf(
            'financeiro-%s-a-%s.csv',
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        );

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            /*
             * BOM UTF-8 na frente do arquivo.
             *
             * É o que faz o Excel no Windows reconhecer a codificação: sem ele
             * "Ação" e "R$" chegam quebrados no acento, que é exatamente o que
             * o lojista vê ao abrir a planilha. Editor de texto e LibreOffice
             * ignoram o BOM, então não custa nada aos outros leitores.
             */
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, FinanceReportService::EXPORT_HEADER, ';');

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->number,
                    $row->placed_at ? Carbon::parse($row->placed_at)->format('d/m/Y H:i') : '',
                    $row->customer_name ?? 'Não informado',
                    $row->customer_phone ?? '',
                    self::PAYMENT_LABELS[$row->payment_method] ?? $row->payment_method,
                    self::FULFILLMENT_LABELS[$row->fulfillment] ?? $row->fulfillment,
                    $this->decimal($row->subtotal_cents),
                    $this->decimal($row->delivery_fee_cents),
                    $this->decimal($row->total_cents),
                ], ';');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Centavos → decimal com vírgula, no formato que o Excel pt-BR entende.
     *
     * Sem separador de milhar de propósito: "1.234,56" com ponto faria o Excel
     * ler como texto em algumas configurações regionais, e aí a coluna não
     * soma — que é a primeira coisa que o lojista tenta fazer na planilha.
     */
    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }

    /**
     * Normaliza o intervalo pedido, com o mês corrente como padrão.
     *
     * Datas invertidas são trocadas em vez de rejeitadas: quem escolhe o fim
     * antes do início quer aquele intervalo, não um erro de validação.
     */
    private function resolveRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = isset($data['from'])
            ? Carbon::parse($data['from'])->startOfDay()
            : Carbon::now()->startOfMonth();

        $to = isset($data['to'])
            ? Carbon::parse($data['to'])->endOfDay()
            : Carbon::now()->endOfMonth();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    /** Cópia da data mais antiga entre as duas, sem mutar nenhuma delas. */
    private function earliest(Carbon $a, Carbon $b): Carbon
    {
        return $a->lte($b) ? $a->copy() : $b->copy();
    }
}
