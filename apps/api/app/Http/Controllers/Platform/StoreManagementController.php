<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Services\TenantPurger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestão das lojas pelo staff da plataforma.
 *
 * Todas as rotas daqui rodam FORA de contexto de tenant, e isso é essencial: o
 * staff opera sobre lojas das quais não é membro. Com um tenant no contexto, o
 * global scope do BelongsToTenant e as policies de RLS recortariam cada
 * consulta ao tenant corrente e devolveriam listas vazias em vez de erro.
 *
 * Por isso também as contagens usam o query builder (`DB::table`) em vez dos
 * models: sem contexto, o global scope não filtra nada, mas passar pelo model
 * criaria a tentação de reintroduzir o escopo depois.
 */
class StoreManagementController extends Controller
{
    public function __construct(private TenantContext $context) {}

    /**
     * Lista as lojas, com busca e filtro por situação.
     *
     * Inclui as soft-deleted (`withTrashed`) de propósito: uma loja excluída
     * pelo próprio lojista continua ocupando o slug para sempre, e o staff
     * precisa vê-la para entender por que o subdomínio está indisponível.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['trial', 'active', 'past_due', 'suspended', 'deleted'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Tenant::withTrashed()
            // As colunas de capacidade entram no select porque os accessors do
            // Tenant leem `allows_orders`; ausente, o atributo vem null e uma
            // loja Pro passaria por somente-cardápio.
            ->with('plan:id,name,slug,allows_orders,allows_delivery,allows_online_payment')
            /*
             * Subqueries em vez de withCount: as relações passam pelo model, e
             * `Order`/`Product` usam BelongsToTenant. Fora de contexto o escopo
             * não filtra, mas basta alguém chamar esta rota com um tenant no
             * contexto — hoje impossível, amanhã não — para toda contagem virar
             * zero silenciosamente. O builder não tem esse acoplamento.
             */
            ->addSelect([
                'orders_count' => DB::table('orders')
                    ->selectRaw('count(*)')
                    ->whereColumn('orders.tenant_id', 'tenants.id'),
                'products_count' => DB::table('products')
                    ->selectRaw('count(*)')
                    ->whereColumn('products.tenant_id', 'tenants.id'),
                'last_order_at' => DB::table('orders')
                    ->selectRaw('max(created_at)')
                    ->whereColumn('orders.tenant_id', 'tenants.id'),
            ]);

        if ($search = $filters['search'] ?? null) {
            // ILIKE: busca por nome digitado com acento e caixa variados é o uso
            // real do campo. O `%` escapado evita que um `_` no termo vire
            // curinga e traga lojas que o staff não pediu.
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

            $query->where(function ($q) use ($term) {
                $q->where('name', 'ilike', $term)->orWhere('slug', 'ilike', $term);
            });
        }

        $status = $filters['status'] ?? null;

        if ($status === 'deleted') {
            $query->whereNotNull('deleted_at');
        } elseif ($status !== null) {
            $query->whereNull('deleted_at')->where('status', $status);
        }

        $stores = $this->context->runWithoutTenant(
            fn () => $query->orderByDesc('created_at')
                ->paginate($filters['per_page'] ?? 20)
                ->withQueryString(),
        );

        return response()->json([
            'data' => collect($stores->items())->map(fn (Tenant $t) => $this->summary($t)),
            'meta' => [
                'currentPage' => $stores->currentPage(),
                'lastPage' => $stores->lastPage(),
                'perPage' => $stores->perPage(),
                'total' => $stores->total(),
            ],
            'totals' => $this->totals(),
        ]);
    }

    /** Detalhe de uma loja, com o dono e as últimas ações do staff sobre ela. */
    public function show(string $slug): JsonResponse
    {
        $tenant = $this->findStore($slug);

        $owner = $this->context->runWithoutTenant(
            fn () => DB::table('users')
                ->where('tenant_id', $tenant->getKey())
                ->orderByRaw("case when role = 'owner' then 0 else 1 end")
                ->first(['id', 'name', 'email', 'role', 'created_at']),
        );

        $settings = $this->context->runWithoutTenant(
            fn () => DB::table('tenant_settings')
                ->where('tenant_id', $tenant->getKey())
                ->first(['phone', 'whatsapp', 'address', 'segment', 'logo_url']),
        );

        return response()->json([
            'store' => [
                ...$this->summary($tenant),
                'trialEndsAt' => $tenant->trial_ends_at?->toIso8601String(),
                'onboardingCompleted' => $tenant->hasCompletedOnboarding(),
                'onboardingStep' => $tenant->onboarding_step,
                'stripeConnected' => $tenant->stripe_account_id !== null,
                'acceptsOnlinePayment' => $tenant->acceptsOnlinePayment(),
                'deletionReason' => $tenant->deletion_reason,
                'segment' => $settings->segment ?? null,
                'phone' => $settings->phone ?? null,
                'whatsapp' => $settings->whatsapp ?? null,
                'address' => $settings->address ?? null,
                'logoUrl' => $settings->logo_url ?? null,
            ],
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => $owner->role,
            ] : null,
            'audit' => PlatformAuditLog::where('tenant_id', $tenant->getKey())
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (PlatformAuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'actor' => $log->actor_email,
                    'context' => $log->context,
                    'createdAt' => $log->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Suspende a loja: sai do ar, nada é apagado.
     *
     * Sem invalidação de cache, pelo mesmo motivo documentado no comando
     * `store:suspend`: o IdentifyTenant cacheia só o ID e relê a linha a cada
     * request, então o status novo vale imediatamente.
     */
    public function suspend(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->findStore($slug);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_if($tenant->trashed(), 422, 'Esta loja já foi excluída.');

        if ($tenant->isSuspended()) {
            return response()->json(['status' => $tenant->status, 'changed' => false]);
        }

        $tenant->update(['status' => 'suspended']);

        PlatformAuditLog::record(
            'suspend',
            $tenant,
            $request->user(),
            ['reason' => $data['reason'] ?? null],
            $request,
        );

        return response()->json(['status' => 'suspended', 'changed' => true]);
    }

    /**
     * Reativa uma loja suspensa.
     *
     * O `status` volta para `active` mesmo que a loja estivesse em `trial`
     * antes: a suspensão não guarda o estado anterior, e `active` é o valor
     * seguro — `trial` reabriria uma janela de teste já vencida.
     */
    public function reactivate(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->findStore($slug);

        abort_if($tenant->trashed(), 422, 'Esta loja foi excluída e não pode ser reativada.');

        if (! $tenant->isSuspended()) {
            return response()->json(['status' => $tenant->status, 'changed' => false]);
        }

        $tenant->update(['status' => 'active']);

        /*
         * Diferente da suspensão, aqui o cache PRECISA ser limpo. Um visitante
         * que tentou acessar a loja durante a suspensão pode ter gravado o
         * cache negativo (slug -> 0, "não existe") com TTL de uma hora, e sem
         * o forget a loja continuaria em 404 depois de reativada. Mesma
         * regressão coberta por StoreSuspensionTest.
         */
        Cache::forget("tenant-id:slug:{$tenant->slug}");

        PlatformAuditLog::record('reactivate', $tenant, $request->user(), [], $request);

        return response()->json(['status' => 'active', 'changed' => true]);
    }

    /** Prévia do estrago, para a tela de confirmação da exclusão permanente. */
    public function purgePreview(string $slug, TenantPurger $purger): JsonResponse
    {
        $tenant = $this->findStore($slug);

        return response()->json([
            'store' => ['name' => $tenant->name, 'slug' => $tenant->slug],
            'willDelete' => $purger->countBeforeDeleting($tenant),
            'stripeConnected' => $tenant->stripe_account_id !== null,
            // O slug não volta a ficar livre: TenantRegistrar::uniqueSlug
            // consulta com withTrashed, mas a purga apaga a linha — então aqui
            // ele DE FATO é liberado, e o staff precisa saber disso.
            'slugBecomesAvailable' => true,
        ]);
    }

    /**
     * Exclusão permanente. Ver TenantPurger para o que é apagado.
     *
     * Três barreiras, todas necessárias e nenhuma redundante: a senha do staff
     * (o token pode estar numa sessão esquecida aberta), o slug digitado (evita
     * o clique errado numa lista onde as linhas são parecidas) e o throttle da
     * rota (limita a tentativa de adivinhar a senha por aqui).
     */
    public function purge(Request $request, string $slug, TenantPurger $purger): JsonResponse
    {
        $tenant = $this->findStore($slug);
        $actor = $request->user();

        $data = $request->validate([
            'password' => ['required', 'string'],
            'confirmation' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! Hash::check($data['password'], $actor->password)) {
            throw ValidationException::withMessages(['password' => 'Senha incorreta.']);
        }

        if ($data['confirmation'] !== $tenant->slug) {
            throw ValidationException::withMessages([
                'confirmation' => "Digite exatamente “{$tenant->slug}” para confirmar.",
            ]);
        }

        $outcome = $purger->purge($tenant, $actor, $data['reason'] ?? null, $request);

        return response()->json(['purged' => true, ...$outcome]);
    }

    /**
     * Resolve a loja pelo slug, incluindo as soft-deleted.
     *
     * `withTrashed` é obrigatório: sem ele, uma loja que o lojista já excluiu
     * some do painel e o staff não consegue purgar de vez o que restou dela —
     * justamente o caso em que a purga é mais útil.
     */
    private function findStore(string $slug): Tenant
    {
        $tenant = $this->context->runWithoutTenant(
            fn () => Tenant::withTrashed()->where('slug', $slug)->first(),
        );

        abort_if($tenant === null, 404, 'Loja não encontrada.');

        return $tenant;
    }

    /** Contadores do cabeçalho do painel. */
    private function totals(): array
    {
        $rows = $this->context->runWithoutTenant(
            fn () => DB::table('tenants')
                ->selectRaw("
                    count(*) filter (where deleted_at is null) as live,
                    count(*) filter (where deleted_at is null and status = 'active') as active,
                    count(*) filter (where deleted_at is null and status = 'trial') as trial,
                    count(*) filter (where deleted_at is null and status = 'suspended') as suspended,
                    count(*) filter (where deleted_at is not null) as deleted
                ")
                ->first(),
        );

        return [
            'live' => (int) ($rows->live ?? 0),
            'active' => (int) ($rows->active ?? 0),
            'trial' => (int) ($rows->trial ?? 0),
            'suspended' => (int) ($rows->suspended ?? 0),
            'deleted' => (int) ($rows->deleted ?? 0),
        ];
    }

    /** Forma canônica de uma loja na resposta do painel. */
    private function summary(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            // `deleted` não é um status do banco; é derivado do soft delete e
            // existe só para a UI não precisar cruzar dois campos.
            'status' => $tenant->trashed() ? 'deleted' : $tenant->status,
            'plan' => $tenant->plan?->name,
            'planSlug' => $tenant->plan?->slug,
            // Uma loja vitrine nunca terá pedidos; sem esta marca, o staff leria
            // "0 pedidos" como problema em vez de característica do plano.
            'menuOnly' => $tenant->isMenuOnly(),
            'storefrontUrl' => $tenant->storefrontUrl(),
            'ordersCount' => (int) ($tenant->orders_count ?? 0),
            'productsCount' => (int) ($tenant->products_count ?? 0),
            'lastOrderAt' => $tenant->last_order_at,
            'createdAt' => $tenant->created_at?->toIso8601String(),
            'deletedAt' => $tenant->deleted_at?->toIso8601String(),
        ];
    }
}
