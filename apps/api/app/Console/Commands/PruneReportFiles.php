<?php

namespace App\Console\Commands;

use App\Models\ReportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Remove PDFs de relatório vencidos.
 *
 * Sem isto o disco cresce sem limite: cada exportação deixa um arquivo, e um
 * dono que gera o relatório toda semana acumularia todos eles para sempre. O
 * registro é mantido (o histórico continua legível), só o arquivo sai.
 */
class PruneReportFiles extends Command
{
    protected $signature = 'reports:prune {--days= : Sobrescreve a retenção padrão}';

    protected $description = 'Apaga os arquivos de relatório mais antigos que o período de retenção';

    public function handle(): int
    {
        // `?:` não serve aqui: "0" é falsy em PHP, e `--days=0` (apagar tudo
        // agora) cairia silenciosamente na retenção padrão de 7 dias.
        $option = $this->option('days');
        $days = $option === null ? ReportJob::RETENTION_DAYS : (int) $option;
        $cutoff = now()->subDays($days);

        $disk = Storage::disk('local');
        $removed = 0;

        // withoutGlobalScopes: o comando roda sem tenant no contexto e precisa
        // varrer todas as lojas de uma vez.
        ReportJob::withoutGlobalScopes()
            ->whereNotNull('file_path')
            ->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($reports) use ($disk, &$removed) {
                foreach ($reports as $report) {
                    if ($disk->exists($report->file_path)) {
                        $disk->delete($report->file_path);
                    }

                    // O arquivo já não existe; marcar como expirado evita que a
                    // tela ofereça um download que resultaria em 404.
                    $report->forceFill([
                        'file_path' => null,
                        'file_size' => null,
                        'status' => 'expired',
                    ])->save();

                    $removed++;
                }
            });

        $this->info("Relatórios expirados removidos: {$removed}");

        return self::SUCCESS;
    }
}
