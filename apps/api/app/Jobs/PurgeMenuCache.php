<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\MenuCachePurger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Avisa Next e Cloudflare que o cardápio de uma loja mudou.
 *
 * Vai para a fila porque são duas chamadas HTTP de rede: no caminho síncrono
 * elas entrariam no tempo de resposta do "Salvar item" do admin, e um alvo
 * lento faria o lojista esperar por um trabalho que não muda o resultado do
 * salvamento.
 *
 * Recebe o id, não o model — payload menor e estado sempre relido fresco.
 */
class PurgeMenuCache implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [5, 30, 120];

    public function __construct(public int $tenantId) {}

    /**
     * Uma edição costuma salvar vários models em sequência (produto, seus
     * modificadores, a categoria). Sem isto cada save viraria um par de
     * chamadas de rede; com a janela, a rajada colapsa num purge só.
     */
    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public int $uniqueFor = 10;

    public function handle(MenuCachePurger $purger): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($this->tenantId);

        if (! $tenant) {
            return;
        }

        $purger->purge($tenant);
    }
}
