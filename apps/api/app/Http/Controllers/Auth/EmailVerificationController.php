<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmailVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Confirmação do e-mail do lojista.
 *
 * Espelha a forma do PasswordResetController — dois passos, token com hash,
 * conta identificada pelo par (loja, e-mail) — e pelo mesmo motivo: `users` é
 * único por (tenant_id, email), então o endereço sozinho não identifica a
 * conta e um token emitido para uma loja não pode confirmar a outra.
 *
 * A diferença de consequência é que aqui o token DEVOLVE uma sessão: quem
 * confirma acabou de criar a conta e cai direto no painel, sem passar pelo
 * login que ele ainda não conseguiria usar.
 */
class EmailVerificationController extends Controller
{
    public function __construct(private EmailVerifier $verifier) {}

    /**
     * Passo 1: reenvia o link.
     *
     * Existe porque a validade é curta e o e-mail pode demorar ou cair no
     * spam: sem reenvio, uma conta criada e não confirmada a tempo ficaria
     * inacessível para sempre, com o slug já ocupado pelo próprio dono.
     *
     * A resposta é sempre a mesma, exista a conta ou não — confirmar que um
     * e-mail está cadastrado numa loja específica permite enumerar quem
     * trabalha onde.
     */
    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'tenant' => ['required', 'string'],
        ]);

        /*
         * Limite por e-mail+loja, não por IP.
         *
         * Sem isto o endpoint vira uma metralhadora de e-mail apontada para
         * terceiros: basta repetir o POST para encher a caixa de um lojista.
         * Mesma defesa (e mesma janela) do pedido de redefinição de senha.
         */
        $key = 'verify-email:'.Str::lower($data['email']).':'.Str::lower($data['tenant']);

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 3)) {
            throw ValidationException::withMessages([
                'email' => 'Muitas tentativas. Aguarde alguns minutos antes de pedir outro link.',
            ]);
        }

        RateLimiter::hit($key, decaySeconds: 900);

        $user = $this->findAccount($data['email'], $data['tenant']);

        // Conta já confirmada não gera token novo: o link seria inútil e o
        // reenvio viraria caminho para inundar a caixa de quem já terminou.
        if ($user && ! $user->hasVerifiedEmail()) {
            $this->verifier->sendLink($user, $user->tenant);
        }

        return response()->json([
            'message' => 'Se houver uma conta pendente com este e-mail, o link de confirmação foi enviado.',
            'expiresInMinutes' => EmailVerifier::TOKEN_TTL_MINUTES,
        ]);
    }

    /**
     * Passo 2: consome o token, marca a conta como confirmada e abre a sessão.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'tenant' => ['required', 'string'],
        ]);

        $user = $this->findAccount($data['email'], $data['tenant']);

        /*
         * Conta já confirmada, token já consumido.
         *
         * Responde sucesso em vez de erro: o caso comum é o lojista abrindo o
         * mesmo link duas vezes (clicou, voltou, clicou de novo), e dizer
         * "link inválido" a quem de fato confirmou seria mentira. Não emite
         * sessão — o token já foi gasto, e devolver credencial para um link
         * reutilizável transformaria um e-mail encaminhado em acesso.
         */
        if ($user && $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Este e-mail já foi confirmado. Faça login para entrar no painel.',
                'alreadyVerified' => true,
            ]);
        }

        // Mensagem única para token ausente, expirado, de outra conta ou conta
        // inexistente: os quatro casos são indistinguíveis para quem tem
        // direito ao link, e separá-los só ajudaria quem está adivinhando.
        if (! $user || ! $this->verifier->consume($user, $data['token'])) {
            throw ValidationException::withMessages([
                'token' => 'Este link de confirmação é inválido ou expirou.',
            ]);
        }

        $tenant = $user->tenant;

        return response()->json([
            // A sessão sai daqui: quem confirmou acabou de criar a conta e não
            // deve ser mandado ao login para digitar a senha que escolheu há
            // dois minutos.
            'token' => $user->createToken('admin')->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'onboardingStep' => $tenant->onboarding_step,
            ],
        ]);
    }

    /** A conta é o par (loja, e-mail); sem os dois não há o que procurar. */
    private function findAccount(string $email, string $tenantSlug): ?User
    {
        $tenant = Tenant::where('slug', Str::lower($tenantSlug))->first();

        return $tenant
            ? User::where('tenant_id', $tenant->id)->where('email', $email)->first()
            : null;
    }
}
