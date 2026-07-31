<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateFinancialReport;
use App\Models\ReportJob;
use App\Services\FinanceReportService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceAdminController extends Controller
{
    /** Além disso o gráfico diário vira uma serra ilegível; agrupa por mês. */
    private const DAILY_GRANULARITY_MAX_DAYS = 92;

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
     * Enfileira a geração do PDF e responde 202 imediatamente.
     *
     * Gerar em linha travaria o request por segundos num mês cheio e estouraria
     * o timeout do PHP-FPM justamente nos relatórios maiores — os que mais
     * importam. O cliente acompanha o progresso por `show`.
     */
    public function requestExport(Request $request, TenantContext $context): JsonResponse
    {
        [$from, $to] = $this->resolveRange($request);
        $search = trim($request->string('search')->toString());

        $rowCount = $this->finance->applySearch(
            $this->finance->revenueQuery($from, $to),
            $search,
        )->count();

        if ($rowCount > FinanceReportService::MAX_EXPORT_ROWS) {
            throw ValidationException::withMessages([
                'range' => sprintf(
                    'O período selecionado tem %s pedidos, acima do limite de %s por relatório. Escolha um intervalo menor.',
                    number_format($rowCount, 0, ',', '.'),
                    number_format(FinanceReportService::MAX_EXPORT_ROWS, 0, ',', '.'),
                ),
            ]);
        }

        // Um relatório idêntico já em andamento é reaproveitado: clicar duas
        // vezes no botão não deve custar dois PDFs iguais na fila.
        $pending = ReportJob::query()
            ->whereIn('status', ['queued', 'processing'])
            ->where('from_date', $from->toDateString())
            ->where('to_date', $to->toDateString())
            ->where('search', $search !== '' ? $search : null)
            ->first();

        if ($pending) {
            return response()->json($this->present($pending), 202);
        }

        $report = ReportJob::create([
            'user_id' => $request->user()?->getKey(),
            'type' => 'financial',
            'status' => 'queued',
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'search' => $search !== '' ? $search : null,
            'row_count' => $rowCount,
        ]);

        GenerateFinancialReport::dispatch($report->getKey(), $context->getOrFail()->getKey());

        return response()->json($this->present($report), 202);
    }

    /** Estado de uma exportação — alvo do polling do admin. */
    public function showExport(string $report, TenantContext $context): JsonResponse
    {
        return response()->json($this->present($this->findForTenant($report, $context)));
    }

    /** Histórico recente de exportações. */
    public function exports(): JsonResponse
    {
        $reports = ReportJob::query()
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (ReportJob $report) => $this->present($report));

        return response()->json(['data' => $reports]);
    }

    /**
     * Entrega o PDF pronto.
     *
     * O arquivo fica em disco privado e passa por aqui de propósito: uma URL
     * pública em storage/app/public seria acessível a qualquer um que
     * descobrisse o caminho, sem passar por auth nem pela checagem de tenant.
     */
    public function download(string $report, TenantContext $context): StreamedResponse
    {
        $report = $this->findForTenant($report, $context);

        abort_unless($report->isDone(), 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($report->file_path), 404);

        return $disk->download(
            $report->file_path,
            sprintf(
                'financeiro-%s-a-%s.pdf',
                $report->from_date->format('Y-m-d'),
                $report->to_date->format('Y-m-d'),
            ),
        );
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

    /**
     * Busca a exportação filtrando o tenant explicitamente.
     *
     * Não usa route model binding de propósito. O binding roda em
     * `substituteBindings`, que é avaliado ANTES do middleware de rota — ou
     * seja, antes de identify.tenant popular o contexto. Sem tenant no
     * contexto o global scope do BelongsToTenant não tem o que filtrar, e o
     * registro de qualquer loja era resolvido normalmente: um usuário
     * autenticado conseguia baixar o PDF financeiro de outra loja informando o
     * id na URL. Filtrar aqui não depende da ordem dos middlewares.
     */
    private function findForTenant(string $id, TenantContext $context): ReportJob
    {
        $report = ReportJob::query()
            ->where('id', $id)
            ->where('tenant_id', $context->getOrFail()->getKey())
            ->first();

        abort_if($report === null, 404);

        return $report;
    }

    /** Cópia da data mais antiga entre as duas, sem mutar nenhuma delas. */
    private function earliest(Carbon $a, Carbon $b): Carbon
    {
        return $a->lte($b) ? $a->copy() : $b->copy();
    }

    private function present(ReportJob $report): array
    {
        return [
            'id' => $report->getKey(),
            'status' => $report->status,
            'from' => $report->from_date?->toDateString(),
            'to' => $report->to_date?->toDateString(),
            'search' => $report->search,
            'rowCount' => $report->row_count,
            'fileSize' => $report->file_size,
            'error' => $report->error,
            'createdAt' => $report->created_at?->toIso8601String(),
            'finishedAt' => $report->finished_at?->toIso8601String(),
        ];
    }
}
