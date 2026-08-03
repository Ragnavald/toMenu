<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Remove os arquivos de uma loja: imagens no object storage e relatórios no
 * disco local.
 *
 * São dois storages com convenções opostas, e é por isso que a classe tem dois
 * caminhos em vez de um loop só.
 *
 * As imagens vão para o disco remoto (R2 em produção) e dependem inteiramente
 * da convenção de nomes, que é a única coisa que liga um objeto ao seu tenant:
 *
 *   products/{tenantId}-{timestamp}-{random}.{ext}   (ProductAdminController)
 *   logos/{tenantId}-logo-{timestamp}.{ext}          (SettingsController)
 *
 * Não há prefixo por tenant — os objetos de todas as lojas convivem nos mesmos
 * dois diretórios. Por isso a purga lista o prefixo e filtra pelo basename, em
 * vez de apagar um diretório inteiro.
 *
 * O `-` depois do id é obrigatório no filtro e é a parte que mais importa:
 * `str_starts_with($base, '12')` casaria com os arquivos do tenant 12, mas
 * também com os do 120 e do 1234. Apagar imagem de loja alheia numa rotina de
 * exclusão seria uma perda de dados silenciosa e irreversível.
 *
 * Os relatórios financeiros são o caso contrário: ficam em `reports/{tenantId}/`
 * no disco `local`, um diretório por loja, então ali a purga apaga a árvore
 * inteira e não precisa filtrar nome por nome.
 *
 * Esse passo trata um resíduo, não um fluxo vivo: a exportação virou CSV em
 * streaming e não grava mais nada em disco. O que sobrou são os PDFs gerados
 * antes da mudança, que continuariam no volume de produção depois de a loja ser
 * excluída — o `reports:prune` que os limpava por idade também deixou de
 * existir. Quando não houver mais instalação com esses arquivos, este passo
 * pode sair junto.
 */
class TenantAssetPurger
{
    /** Diretórios onde os uploads da plataforma são gravados. */
    private const PREFIXES = ['products', 'logos'];

    /**
     * @return array{disk: string|null, deleted: int, failed: int, reports: string}
     */
    public function purge(Tenant $tenant): array
    {
        $disk = $this->remoteDisk() ?? 'public';

        // Antes do early return das imagens: uma loja sem foto de produto pode
        // perfeitamente ter exportado relatório.
        $reports = $this->purgeReports($tenant);

        $targets = [];

        foreach (self::PREFIXES as $prefix) {
            $targets = [...$targets, ...$this->matching($disk, $prefix, $tenant->getKey())];
        }

        if ($targets === []) {
            return ['disk' => $disk, 'deleted' => 0, 'failed' => 0, 'reports' => $reports];
        }

        /*
         * Falha aqui não pode abortar a exclusão. O tenant já foi (ou está
         * prestes a ser) apagado do banco, e interromper no meio deixaria a
         * loja num estado pior do que arquivos órfãos no bucket: registro
         * removido pela metade. O log em nível de warning é o gancho para a
         * limpeza manual.
         */
        try {
            $ok = Storage::disk($disk)->delete($targets);
        } catch (\Throwable $e) {
            Log::warning('Falha ao purgar imagens da loja no object storage.', [
                'tenant_id' => $tenant->getKey(),
                'slug' => $tenant->slug,
                'disk' => $disk,
                'objects' => count($targets),
                'exception' => $e->getMessage(),
            ]);

            return ['disk' => $disk, 'deleted' => 0, 'failed' => count($targets), 'reports' => $reports];
        }

        return [
            'disk' => $disk,
            'deleted' => $ok ? count($targets) : 0,
            'failed' => $ok ? 0 : count($targets),
            'reports' => $reports,
        ];
    }

    /**
     * Apaga os PDFs de relatório financeiro da loja.
     *
     * `deleteDirectory` em vez de listar e filtrar: aqui existe diretório por
     * tenant, e `reports/7` nunca contém arquivo de outra loja — o risco de
     * prefixo parecido que assombra as imagens (`7` casando com `70`) não se
     * aplica, porque o caminho é comparado inteiro pelo próprio storage.
     *
     * Disco `local` fixo, e não o remoto: é onde os PDFs foram gravados. Usar
     * `remoteDisk()` aqui apagaria um caminho que não existe no R2 e deixaria
     * os arquivos intactos no container, sem erro nenhum.
     *
     * Como no resto da purga, falha não aborta a exclusão — devolve o desfecho
     * para a auditoria e segue.
     */
    private function purgeReports(Tenant $tenant): string
    {
        $path = "reports/{$tenant->getKey()}";

        try {
            $disk = Storage::disk('local');

            // Diretório ausente é o caso comum (loja que nunca exportou), e o
            // deleteDirectory de um caminho inexistente varia entre drivers:
            // uns devolvem false, outros lançam. Checar antes evita registrar
            // "failed" para uma loja que simplesmente não tinha relatório.
            if (! $disk->exists($path)) {
                return 'none';
            }

            return $disk->deleteDirectory($path) ? 'deleted' : 'failed';
        } catch (\Throwable $e) {
            Log::warning('Falha ao purgar relatórios da loja.', [
                'tenant_id' => $tenant->getKey(),
                'slug' => $tenant->slug,
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * Objetos do prefixo que pertencem a este tenant.
     *
     * @return list<string>
     */
    private function matching(string $disk, string $prefix, int $tenantId): array
    {
        try {
            $files = Storage::disk($disk)->files($prefix);
        } catch (\Throwable $e) {
            // Prefixo inexistente é o caso comum numa loja que nunca subiu
            // imagem; não é erro e não merece alarme.
            Log::info('Prefixo indisponível ao purgar imagens da loja.', [
                'disk' => $disk,
                'prefix' => $prefix,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }

        $needle = "{$tenantId}-";

        return array_values(array_filter(
            $files,
            fn (string $path) => str_starts_with(basename($path), $needle),
        ));
    }

    /**
     * Mesma precedência do ImageStorage — R2 antes de S3, pela chave de acesso.
     *
     * Precisa casar com ela: purgar de um disco diferente daquele em que o
     * upload gravou deixaria todos os arquivos para trás sem erro nenhum.
     */
    private function remoteDisk(): ?string
    {
        foreach (['r2', 's3'] as $disk) {
            if (! empty(config("filesystems.disks.{$disk}.key"))) {
                return $disk;
            }
        }

        return null;
    }
}
