<?php

namespace App\Jobs;

use App\Models\ReportJob;
use App\Models\Tenant;
use App\Services\FinanceReportService;
use App\Tenancy\TenantContext;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * Renderiza o relatório financeiro em PDF fora do ciclo de request.
 *
 * Recebe IDs, não models: o payload da fila fica pequeno e o estado é relido
 * fresco, seguindo o mesmo padrão de NotifyNewOrder.
 *
 * As decisões de desempenho aqui têm um alvo concreto: um mês cheio de pedidos
 * não pode estourar a memória do worker, que roda como daemon de vida longa e
 * é compartilhado com os jobs de notificação — um relatório grande travando o
 * worker atrasaria o aviso de pedido novo na cozinha.
 */
class GenerateFinancialReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var int[] */
    public array $backoff = [30, 120];

    /** Um PDF que passa disso está com problema — melhor falhar que travar. */
    public int $timeout = 240;

    /** Linhas por lote ao percorrer os pedidos. */
    private const CHUNK_SIZE = 500;

    public function __construct(
        public int $reportJobId,
        public int $tenantId,
    ) {
        // Fila própria: relatório é pesado e lento; pedido novo é urgente. Vai
        // pelo onQueue() do trait — declarar `public $queue` colide com a
        // propriedade do Queueable e quebra a composição da classe.
        $this->onQueue('reports');
    }

    public function handle(TenantContext $context, FinanceReportService $finance): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($this->tenantId);

        if (! $tenant) {
            return;
        }

        $context->runFor($tenant, function () use ($tenant, $finance) {
            $report = ReportJob::find($this->reportJobId);

            if (! $report || $report->status === 'done') {
                return;
            }

            $report->update(['status' => 'processing', 'started_at' => now()]);

            try {
                $this->render($tenant, $report, $finance);
            } catch (Throwable $e) {
                // O erro precisa chegar ao dono da loja como estado do relatório;
                // sem isto a tela ficaria em "processando" para sempre.
                $report->update([
                    'status' => 'failed',
                    'error' => 'Não foi possível gerar o relatório.',
                    'finished_at' => now(),
                ]);

                Log::error('Falha ao gerar relatório financeiro', [
                    'report_job_id' => $report->getKey(),
                    'tenant_id' => $this->tenantId,
                    'exception' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    private function render(Tenant $tenant, ReportJob $report, FinanceReportService $finance): void
    {
        $from = Carbon::parse($report->from_date)->startOfDay();
        $to = Carbon::parse($report->to_date)->endOfDay();
        $search = $report->search ?? '';

        $summary = $finance->summary($from, $to, $search);
        $monthly = $finance->monthlyRevenue($from, $to, $search);
        $byMethod = $finance->revenueByPaymentMethod($from, $to, $search);

        // chunkById em vez de get(): percorre os pedidos em lotes de tamanho
        // fixo, então a memória usada não cresce com o tamanho do período. Com
        // `get()` um ano de pedidos viria inteiro para a RAM do worker.
        $rows = [];
        $finance->applySearch($finance->revenueQuery($from, $to), $search)
            ->with(['customer:id,name,phone'])
            ->select(['id', 'number', 'placed_at', 'payment_method', 'fulfillment',
                'subtotal_cents', 'delivery_fee_cents', 'total_cents', 'customer_id'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($orders) use (&$rows) {
                foreach ($orders as $order) {
                    $rows[] = [
                        'number' => $order->number,
                        'date' => $order->placed_at?->format('d/m/Y H:i') ?? '—',
                        'customer' => $order->customer?->name ?? 'Não informado',
                        'method' => $order->payment_method,
                        'fulfillment' => $order->fulfillment,
                        'subtotal' => $order->subtotal_cents,
                        'delivery' => $order->delivery_fee_cents,
                        'total' => $order->total_cents,
                    ];
                }
            });

        $html = View::make('reports.financial', [
            'tenant' => $tenant,
            'logoPath' => $this->resolveLogoPath($tenant),
            'from' => $from,
            'to' => $to,
            'search' => $search,
            'summary' => $summary,
            'monthly' => $monthly,
            'byMethod' => $byMethod,
            'rows' => $rows,
            'generatedAt' => now(),
        ])->render();

        // Libera o array de linhas antes de rasterizar: o dompdf é a fase que
        // mais consome memória, e não precisa da fonte dos dados a essa altura.
        unset($rows);

        $options = new Options();
        $options->set('isRemoteEnabled', false); // Nada de buscar URL externa a partir do PDF.
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans'); // Tem acentuação; a Helvetica padrão não.
        $options->set('chroot', [storage_path('app')]);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $output = $dompdf->output();

        $path = sprintf('reports/%d/financeiro-%s.pdf', $tenant->getKey(), $report->getKey());
        Storage::disk('local')->put($path, $output);

        $report->update([
            'status' => 'done',
            'file_path' => $path,
            'file_size' => strlen($output),
            'finished_at' => now(),
            'error' => null,
        ]);
    }

    /**
     * Caminho local do logo para embutir no cabeçalho.
     *
     * O dompdf roda com isRemoteEnabled=false, então só entra arquivo do disco.
     * Logo hospedado em S3/URL externa é ignorado de propósito — habilitar
     * remoto abriria SSRF a partir de um template.
     */
    private function resolveLogoPath(Tenant $tenant): ?string
    {
        $url = $tenant->settings?->logo_url;

        if (! $url) {
            return null;
        }

        $relative = ltrim(parse_url($url, PHP_URL_PATH) ?? '', '/');
        $relative = preg_replace('#^storage/#', '', $relative);

        if ($relative === null || $relative === '') {
            return null;
        }

        $absolute = storage_path('app/public/'.$relative);

        return is_file($absolute) ? $absolute : null;
    }

    /** Marca o registro como falho quando a fila esgota as tentativas. */
    public function failed(?Throwable $exception): void
    {
        $context = app(TenantContext::class);
        $tenant = Tenant::withoutGlobalScopes()->find($this->tenantId);

        if (! $tenant) {
            return;
        }

        $context->runFor($tenant, function () {
            ReportJob::where('id', $this->reportJobId)
                ->whereIn('status', ['queued', 'processing'])
                ->update([
                    'status' => 'failed',
                    'error' => 'Não foi possível gerar o relatório.',
                    'finished_at' => now(),
                ]);
        });
    }
}
