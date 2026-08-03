<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Ponto único de upload de imagem da plataforma (logo, capa e foto de produto).
 *
 * Antes existiam duas implementações divergentes: a de produto usava o disco
 * remoto, a de logo só reconhecia 's3' e por isso nunca escolhia o R2 — a foto
 * ia para a nuvem e a logo ficava no disco do container. Centralizar aqui evita
 * que os dois caminhos voltem a divergir.
 */
class ImageStorage
{
    /**
     * Envia o arquivo para o disco remoto configurado e devolve a URL pública.
     *
     * Se nenhum disco remoto estiver configurado (caso do ambiente local), cai
     * para o disco público — é o que permite rodar sem credencial de nuvem.
     */
    public function put(UploadedFile $file, string $filename): string
    {
        $disk = $this->remoteDisk();

        if ($disk !== null) {
            try {
                $path = Storage::disk($disk)->putFileAs('', $file, $filename);

                return Storage::disk($disk)->url($path);
            } catch (\Throwable $e) {
                // O fallback mantém o upload funcionando, mas o arquivo fica no
                // disco do container e some no próximo deploy. É degradação
                // silenciosa: o log em nível de error existe para que apareça
                // no monitoramento em vez de passar despercebido.
                Log::error("Falha ao enviar imagem para o disco '{$disk}'; usando storage local.", [
                    'exception' => $e->getMessage(),
                    'filename' => $filename,
                ]);
            }
        }

        $path = Storage::disk('public')->putFileAs('', $file, $filename);

        return Storage::disk('public')->url($path);
    }

    /**
     * Apaga do storage a imagem apontada por `$url`, se ela for nossa.
     *
     * Chamado quando a foto deixa de ser referenciada — troca de imagem ou
     * exclusão do produto. Sem isso o arquivo antigo fica no bucket para sempre:
     * a URL some do banco e com ela a única pista de que o objeto existia.
     *
     * Devolve true só quando algo foi de fato apagado. Os outros casos (URL
     * vazia, externa, de outro tenant, falha no storage) devolvem false — nenhum
     * deles é erro para quem chama, porque salvar o produto não pode falhar por
     * causa da faxina.
     *
     * O `$tenantId` não é opcional por precaução: é ele que impede que uma
     * `image_url` colada à mão no formulário — o campo aceita URL livre — vire
     * um pedido de exclusão de arquivo de outra loja. O caminho é reconstruído a
     * partir do nome esperado, nunca aceito como veio.
     *
     * O `$prefix` é o diretório em que o `put()` gravou (`products`, `logos`).
     * Ele participa da validação: uma URL de logo não é apagável por quem está
     * editando um produto, mesmo sendo da mesma loja.
     */
    public function delete(?string $url, int $tenantId, string $prefix = 'products'): bool
    {
        $path = $this->pathFor($url, $tenantId, $prefix);

        if ($path === null) {
            return false;
        }

        $disk = $this->remoteDisk() ?? 'public';

        try {
            return Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            // Mesma escolha do TenantAssetPurger: arquivo órfão é melhor do que
            // uma edição que falha. O warning é o gancho para a limpeza manual.
            Log::warning("Falha ao apagar imagem do disco '{$disk}'.", [
                'exception' => $e->getMessage(),
                'path' => $path,
            ]);

            return false;
        }
    }

    /**
     * Caminho no disco correspondente à URL, ou null se ela não for um upload
     * desta loja.
     *
     * A validação toda mora aqui e é deliberadamente estreita: só reconhece o
     * formato que o `put()` gera. Uma URL externa (o lojista pode colar
     * qualquer https no campo) não casa com o prefixo e é ignorada; um basename
     * que não comece por `{tenantId}-` pertence a outra loja e também é
     * ignorado.
     *
     * O `-` depois do id é o que separa o tenant 12 dos tenants 120 e 1234 —
     * mesma armadilha documentada no TenantAssetPurger, e aqui ela seria pior:
     * lá a exclusão é de uma loja que já morreu, aqui roda numa edição comum.
     */
    private function pathFor(?string $url, int $tenantId, string $prefix): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $basename = basename(parse_url($url, PHP_URL_PATH) ?: '');

        if ($basename === '' || ! str_starts_with($basename, "{$tenantId}-")) {
            return null;
        }

        // O caminho é remontado a partir do prefixo conhecido: qualquer
        // diretório que viesse na URL é descartado junto com o resto dela.
        $path = trim($prefix, '/')."/{$basename}";

        // Confirma que a URL realmente aponta para o objeto que acabamos de
        // deduzir. Sem esta comparação, um link de terceiro cujo arquivo tenha
        // o nome certo (`.../12-160000-abc.jpg`) apagaria a foto homônima da
        // loja 12 no nosso bucket.
        $disk = $this->remoteDisk() ?? 'public';

        try {
            $expected = Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return null;
        }

        return $this->sameLocation($expected, $url) ? $path : null;
    }

    /**
     * Compara duas URLs ignorando o que não identifica o objeto.
     *
     * Host e caminho precisam bater; query string e fragmento não entram na
     * conta porque o R2 pode devolver a URL assinada e o banco guardar a versão
     * limpa (ou o contrário). Esquema fora da comparação pelo mesmo motivo: um
     * http gravado antes do TLS não deveria impedir a faxina.
     */
    private function sameLocation(string $expected, string $actual): bool
    {
        $a = parse_url($expected);
        $b = parse_url($actual);

        if ($a === false || $b === false) {
            return false;
        }

        return ($a['host'] ?? null) === ($b['host'] ?? null)
            && rtrim($a['path'] ?? '', '/') === rtrim($b['path'] ?? '', '/');
    }

    /**
     * Nome do disco remoto configurado, ou null quando não há credencial.
     *
     * O R2 tem precedência sobre o S3 por ser o storage de produção da
     * plataforma; a checagem é pela chave de acesso porque o disco existe em
     * config mesmo sem credencial preenchida.
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
