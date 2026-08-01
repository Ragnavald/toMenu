<?php

namespace App\Services;

use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidArgumentException;
use Stripe\StripeClient;

/**
 * Exclusão PERMANENTE de uma loja, executada pelo staff da plataforma.
 *
 * Difere do TenantDeleter, que é a saída voluntária do lojista: aquele faz soft
 * delete e preserva pedidos como documento fiscal. Aqui a linha do tenant é
 * apagada de verdade, e as FKs `cascadeOnDelete` levam junto categorias,
 * produtos, modificadores, clientes, endereços, pedidos, itens, pagamentos,
 * report_jobs, settings e usuários. Não há desfazer — só restore de backup.
 *
 * Os três estados fora do Postgres também são limpos, porque nenhum deles
 * desaparece com o DELETE:
 *
 *   R2/S3   as imagens continuariam servidas por URL pública indefinidamente;
 *   Redis   o mapa slug -> id sobreviveria por até uma hora, e o cardápio
 *           cacheado seria servido a quem tivesse a URL;
 *   Stripe  a conta Connect não é apagada de propósito (o dinheiro é do
 *           lojista), apenas desvinculada.
 *
 * A ordem importa e não é intercambiável:
 *
 *   1. auditoria — enquanto nome, slug e contagens ainda existem;
 *   2. Stripe    — precisa do stripe_account_id, que some com a linha;
 *   3. DELETE    — dentro de transação;
 *   4. cache     — depois do commit, senão uma leitura concorrente repovoa;
 *   5. R2        — por último, porque é o passo que pode falhar e não deve
 *                  impedir a exclusão dos dados.
 */
class TenantPurger
{
    public function __construct(
        private TenantContext $context,
        private TenantAssetPurger $assets,
    ) {}

    /**
     * O StripeClient é resolvido sob demanda, e não injetado, pelo mesmo motivo
     * do TenantDeleter: o SDK lança no construtor quando STRIPE_SECRET está
     * vazio, o que derrubaria a purga inteira com 500 num ambiente sem Stripe.
     */
    private function stripe(): StripeClient
    {
        return app(StripeClient::class);
    }

    /**
     * @return array{counts: array<string,int>, assets: array{disk: string|null, deleted: int, failed: int}, connect: string}
     */
    public function purge(
        Tenant $tenant,
        ?User $actor = null,
        ?string $reason = null,
        ?Request $request = null,
    ): array {
        $counts = $this->countBeforeDeleting($tenant);
        $connect = $this->detachConnectAccount($tenant);

        // Copiados antes do DELETE: depois dele o model continua em memória,
        // mas ler `$tenant->slug` já não corresponde a nada no banco.
        $slug = $tenant->slug;
        $tenantId = $tenant->getKey();

        /*
         * A trilha é gravada ANTES do DELETE, e precisa ser: depois dele não
         * existe mais linha de onde ler nome, slug e contagens.
         *
         * O preço é que uma transação abortada (deadlock, constraint, queda)
         * deixaria registrado um "purge" que não aconteceu. Como a tabela é
         * append-only por desenho — o RLS recusa UPDATE —, corrigir não é
         * reescrever a linha, e sim registrar o desfecho real como um evento
         * novo. É o que o `catch` abaixo faz.
         */
        PlatformAuditLog::record('purge', $tenant, $actor, [
            'counts' => $counts,
            'connect' => $connect,
            'reason' => $reason,
        ], $request);

        try {
            $this->deleteRows($tenant, $tenantId);
        } catch (\Throwable $e) {
            PlatformAuditLog::record('purge_failed', $tenant, $actor, [
                'error' => $e->getMessage(),
            ], $request);

            // Relançado: quem chamou precisa responder erro, não sucesso. Sem
            // isto o painel diria "excluída" para uma loja que continua no ar.
            throw $e;
        }

        // Depois do commit. Antes dele, uma requisição concorrente poderia ler
        // a linha ainda visível e regravar a chave que acabamos de limpar.
        $this->forgetCaches($tenantId, $slug);

        $assets = $this->assets->purge($tenant);

        Log::warning('Loja excluída permanentemente pela plataforma.', [
            'tenant_id' => $tenantId,
            'slug' => $slug,
            'actor' => $actor?->email,
            'counts' => $counts,
            'assets' => $assets,
            'connect' => $connect,
        ]);

        return ['counts' => $counts, 'assets' => $assets, 'connect' => $connect];
    }

    /** O DELETE em si, isolado numa transação. */
    private function deleteRows(Tenant $tenant, int $tenantId): void
    {
        DB::transaction(function () use ($tenant, $tenantId) {
            /*
             * Tokens primeiro, explicitamente.
             *
             * `personal_access_tokens` é polimórfica e não tem FK para users,
             * então o cascade NÃO a alcança: os tokens da loja sobreviveriam ao
             * DELETE como linhas órfãs, apontando para um tokenable_id que não
             * existe mais. O Sanctum trata isso como token inválido, mas manter
             * credencial órfã no banco é lixo que ninguém limpa depois.
             */
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', DB::table('users')->where('tenant_id', $tenantId)->select('id'))
                ->delete();

            /*
             * `forceDelete` porque o model usa SoftDeletes: `delete()` apenas
             * preencheria `deleted_at` e nada seria removido em cascata — a
             * purga viraria uma suspensão cara e silenciosa.
             *
             * Fora de contexto de tenant de propósito. Com `app.tenant_id`
             * setado, as policies de RLS restringem cada tabela ao tenant
             * corrente; o cascade é executado pelo próprio Postgres e passa por
             * cima disso, mas qualquer leitura feita pelo Eloquent no caminho
             * (eventos, observers) enxergaria um recorte inconsistente.
             */
            $this->context->runWithoutTenant(fn () => $tenant->forceDelete());
        });
    }

    /**
     * O que existe agora — para a tela de confirmação e para a auditoria.
     *
     * Precisa rodar antes do DELETE por razões óbvias, e sem contexto de tenant
     * porque as policies de RLS zerariam as contagens de um tenant que não é o
     * corrente (o staff opera sem tenant no contexto).
     *
     * @return array<string,int>
     */
    public function countBeforeDeleting(Tenant $tenant): array
    {
        return $this->context->runWithoutTenant(fn () => [
            'products' => DB::table('products')->where('tenant_id', $tenant->getKey())->count(),
            'categories' => DB::table('categories')->where('tenant_id', $tenant->getKey())->count(),
            'orders' => DB::table('orders')->where('tenant_id', $tenant->getKey())->count(),
            'customers' => DB::table('customers')->where('tenant_id', $tenant->getKey())->count(),
            'payments' => DB::table('payments')->where('tenant_id', $tenant->getKey())->count(),
            'users' => DB::table('users')->where('tenant_id', $tenant->getKey())->count(),
        ]);
    }

    /**
     * Desvincula a conta Connect, sem apagá-la no Stripe.
     *
     * Mesma política do TenantDeleter, e pelo mesmo motivo: a conta Express
     * pode ter saldo não repassado ou chargeback em aberto, e o dinheiro é do
     * lojista. A plataforma só marca a conta como desvinculada; excluí-la seria
     * dispor do dinheiro de terceiro.
     *
     * Falha não aborta a purga — o staff decidiu excluir, e deixar a operação
     * pela metade porque a API do Stripe está fora do ar é pior. O resultado
     * entra na auditoria para reconciliação manual.
     */
    private function detachConnectAccount(Tenant $tenant): string
    {
        if (! $tenant->stripe_account_id) {
            return 'none';
        }

        try {
            $this->stripe()->accounts->update($tenant->stripe_account_id, [
                'metadata' => [
                    'tenant_id' => (string) $tenant->getKey(),
                    'platform_status' => 'purged',
                    'purged_at' => now()->toIso8601String(),
                ],
            ]);

            return 'detached';
        } catch (ApiErrorException|InvalidArgumentException $e) {
            Log::warning('Falha ao desvincular conta Connect na purga da loja.', [
                'tenant_id' => $tenant->getKey(),
                'stripe_account_id' => $tenant->stripe_account_id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * Limpa o que o Redis guarda sobre a loja.
     *
     * São duas coisas distintas: o mapa slug -> id do IdentifyTenant (TTL de
     * uma hora, e sem isto o storefront continuaria resolvendo a loja) e o
     * cardápio renderizado do MenuService, que grava em `menu:{id}:v{versão}` e
     * em `menu:{id}:stale`. A versão está na linha que acabou de ser apagada,
     * então varrer o prefixo `menu:{id}:` é a única forma de alcançar as duas
     * de uma vez — e nem todo store de cache suporta varredura, daí o
     * tratamento tolerante.
     */
    private function forgetCaches(int $tenantId, string $slug): void
    {
        Cache::forget("tenant-id:slug:{$slug}");

        try {
            $store = Cache::getStore();

            if (! $store instanceof \Illuminate\Cache\RedisStore) {
                return;
            }

            $prefix = $store->getPrefix();
            $connection = $store->connection();

            // SCAN em vez de KEYS: KEYS bloqueia o Redis inteiro enquanto varre,
            // e este código roda num processo que atende requisições.
            $cursor = null;

            do {
                [$cursor, $keys] = $connection->scan(
                    $cursor ?? 0,
                    ['match' => "{$prefix}menu:{$tenantId}:*", 'count' => 100],
                );

                if ($keys !== []) {
                    $connection->del($keys);
                }
            } while ((int) $cursor !== 0);
        } catch (\Throwable $e) {
            // Cache órfão expira sozinho; não vale falhar a purga por isso.
            Log::info('Não foi possível varrer o cache do cardápio na purga.', [
                'tenant_id' => $tenantId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
