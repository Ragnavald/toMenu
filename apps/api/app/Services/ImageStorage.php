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
