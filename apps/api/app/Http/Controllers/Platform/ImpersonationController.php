<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Acesso do staff ao painel de uma loja, sem a senha do lojista.
 *
 * O token emitido aqui É o token do owner: o Sanctum autentica como aquele
 * usuário, e o painel da loja funciona sem saber que se trata de impersonação.
 * É a única forma de o staff ver exatamente o que o lojista vê ao depurar um
 * problema relatado.
 *
 * Isso torna o endpoint a credencial mais sensível da plataforma, e daí as
 * três contenções:
 *
 *   expiração curta   o token morre em EXPIRES_MINUTES, e não junto com a
 *                     sessão. Um token de acesso alheio esquecido num
 *                     histórico de navegação é o risco concreto;
 *   habilidade        marcado com `impersonate`, o que o EnsurePlatformAdmin
 *                     recusa — o token não reentra no painel da plataforma
 *                     para escalar até a purga de outras lojas;
 *   auditoria         gravada ANTES da emissão, para que o registro exista
 *                     mesmo que algo falhe depois.
 */
class ImpersonationController extends Controller
{
    /** Habilidade que marca o token como emitido por impersonação. */
    public const ABILITY = 'impersonate';

    /**
     * Vida do token.
     *
     * Curta o bastante para que o vazamento tenha janela pequena, longa o
     * bastante para uma sessão real de suporte. Exige
     * `sanctum.expiration`/`expires_at` — o Sanctum respeita a data por token.
     */
    private const EXPIRES_MINUTES = 30;

    public function __construct(private TenantContext $context) {}

    public function store(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->context->runWithoutTenant(
            fn () => Tenant::where('slug', $slug)->first(),
        );

        abort_if($tenant === null, 404, 'Loja não encontrada.');

        /*
         * Loja excluída ou suspensa não é impersonável.
         *
         * Não é preciosismo: o IdentifyTenant devolve 403 para tenant suspenso
         * em TODA rota, inclusive /api/admin/*. O token seria emitido com
         * sucesso e o painel abriria num erro incompreensível. Recusar aqui
         * torna a causa explícita — para reativar, existe o botão de reativar.
         */
        abort_if($tenant->trashed(), 422, 'Esta loja foi excluída.');
        abort_if($tenant->isSuspended(), 422, 'Reative a loja antes de acessá-la.');

        $owner = $this->resolveOwner($tenant);

        abort_if($owner === null, 422, 'Esta loja não tem nenhum usuário para acessar.');

        $reason = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ])['reason'] ?? null;

        // Antes de emitir: se a criação do token falhar, o registro da tentativa
        // ainda precisa existir.
        PlatformAuditLog::record('impersonate', $tenant, $request->user(), [
            'owner_id' => $owner->getKey(),
            'owner_email' => $owner->email,
            'expires_minutes' => self::EXPIRES_MINUTES,
            'reason' => $reason,
        ], $request);

        $expiresAt = now()->addMinutes(self::EXPIRES_MINUTES);

        $token = $owner->createToken(
            // O nome fica visível na tabela de tokens e diz quem abriu a sessão;
            // é o que permite revogar sem adivinhação.
            "impersonation:{$request->user()->email}",
            [self::ABILITY],
            $expiresAt,
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'expiresAt' => $expiresAt->toIso8601String(),
            'tenant' => [
                'slug' => $tenant->slug,
                'name' => $tenant->name,
            ],
            'user' => [
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => $owner->role,
            ],
        ]);
    }

    /**
     * O owner da loja; qualquer usuário dela se não houver owner.
     *
     * O fallback importa porque `role` é apenas uma string com default `owner`,
     * sem constraint: uma loja pode acabar sem nenhuma linha marcada assim, e
     * negar acesso ao suporte por causa disso seria o pior desfecho.
     */
    private function resolveOwner(Tenant $tenant): ?User
    {
        return $this->context->runWithoutTenant(
            fn () => User::where('tenant_id', $tenant->getKey())
                ->orderByRaw("case when role = 'owner' then 0 else 1 end")
                ->orderBy('id')
                ->first(),
        );
    }

    /**
     * Revoga todos os tokens de impersonação de uma loja.
     *
     * Existe para o caso em que o staff encerra o atendimento antes dos 30
     * minutos, e para o botão de emergência quando um token vaza. Apaga por
     * prefixo do nome, que é como os tokens de impersonação se identificam.
     */
    public function destroy(Request $request, string $slug): JsonResponse
    {
        $tenant = $this->context->runWithoutTenant(
            fn () => Tenant::withTrashed()->where('slug', $slug)->first(),
        );

        abort_if($tenant === null, 404, 'Loja não encontrada.');

        $revoked = $this->context->runWithoutTenant(
            fn () => DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn(
                    'tokenable_id',
                    DB::table('users')->where('tenant_id', $tenant->getKey())->select('id'),
                )
                ->where('name', 'like', 'impersonation:%')
                ->delete(),
        );

        return response()->json(['revoked' => $revoked]);
    }
}
