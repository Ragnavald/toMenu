<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Remove do object storage as imagens de uma loja.
 *
 * O desenho depende inteiramente da convenção de nomes dos uploads, que é a
 * única coisa que liga um objeto no bucket ao seu tenant:
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
 */
class TenantAssetPurger
{
    /** Diretórios onde os uploads da plataforma são gravados. */
    private const PREFIXES = ['products', 'logos'];

    /**
     * @return array{disk: string|null, deleted: int, failed: int}
     */
    public function purge(Tenant $tenant): array
    {
        $disk = $this->remoteDisk() ?? 'public';

        $targets = [];

        foreach (self::PREFIXES as $prefix) {
            $targets = [...$targets, ...$this->matching($disk, $prefix, $tenant->getKey())];
        }

        if ($targets === []) {
            return ['disk' => $disk, 'deleted' => 0, 'failed' => 0];
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

            return ['disk' => $disk, 'deleted' => 0, 'failed' => count($targets)];
        }

        return [
            'disk' => $disk,
            'deleted' => $ok ? count($targets) : 0,
            'failed' => $ok ? 0 : count($targets),
        ];
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
