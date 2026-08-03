<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSettings;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Criação de uma nova loja a partir do cadastro público.
 *
 * Roda inteiramente fora de contexto de tenant: o tenant ainda não existe
 * quando a requisição chega. Por isso as escritas usam `withoutGlobalScopes`
 * implicitamente (models centrais) ou o contexto recém-criado (settings).
 */
class TenantRegistrar
{
    /** Subdomínios da plataforma; nunca podem virar slug de loja. */
    public const RESERVED_SLUGS = [
        'www', 'app', 'api', 'admin', 'central', 'mail', 'static', 'assets',
        'cdn', 'blog', 'help', 'suporte', 'status', 'painel', 'conta', 'login',
    ];

    public function __construct(
        private TenantContext $context,
        private EmailVerifier $verifier,
    ) {}

    public function register(array $data): array
    {
        $result = DB::transaction(function () use ($data) {
            $plan = $this->resolvePlan($data['plan'] ?? Plan::PRO);

            $tenant = Tenant::create([
                'name' => $data['store_name'],
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['store_name']),
                'plan_id' => $plan->id,
                'status' => 'trial',
                'trial_ends_at' => now()->addDays(14),
                'onboarding_step' => 1,
                // Gravado no mesmo insert do tenant, dentro da transação: a
                // loja e a prova de que os termos foram aceitos para criá-la
                // nascem juntas ou não nascem. O controller já recusou o
                // cadastro sem aceite, então chegar aqui significa que ele
                // ocorreu — o que falta é registrar de qual texto se trata.
                'terms_accepted_at' => now(),
                'terms_version' => config('legal.terms_version'),
                'terms_accepted_ip' => $data['ip'] ?? null,
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'owner',
            ]);

            // O contexto precisa existir para que o global scope preencha o
            // tenant_id e o RLS aceite a escrita.
            $this->context->runFor($tenant, function () use ($tenant) {
                TenantSettings::create([
                    'tenant_id' => $tenant->id,
                    'theme' => app(ThemeSanitizer::class)->sanitize([]),
                    'delivery_config' => [
                        'fee_cents' => 0,
                        'min_order_cents' => 0,
                        'eta_minutes' => 40,
                        'free_above_cents' => null,
                        'radius_km' => null,
                        'accepts_pickup' => true,
                        'accepts_delivery' => true,
                        // Opt-in: a loja liga quando tiver salão.
                        'accepts_dine_in' => false,
                    ],
                    'payment_methods' => ['cash'],
                    'business_hours' => $this->defaultHours(),
                ]);
            });

            return [
                'tenant' => $tenant,
                // Sem token: a sessão só nasce depois que o e-mail é
                // confirmado, e é o EmailVerificationController que a emite.
                'user' => $user,
            ];
        });

        $this->sendVerification($result['user'], $result['tenant']);

        return $result;
    }

    /**
     * Link de confirmação, depois do commit.
     *
     * Fora da transação de propósito. Dentro dela o e-mail sairia antes do
     * commit e um rollback entregaria ao lojista o link de uma conta que não
     * existe.
     *
     * O try/catch existe porque o cadastro já está gravado neste ponto, e
     * derrubar a resposta com 500 mandaria o lojista de volta a um formulário
     * que agora recusaria o slug ocupado por ele mesmo. A diferença para as
     * boas-vindas é a consequência da falha: sem este e-mail ninguém entra no
     * painel, então o erro sobe como `error` no log (e não `warning`) e a tela
     * de confirmação oferece o reenvio, que é a saída do lojista.
     *
     * As boas-vindas saem depois, no VerifyEmail: mandar "sua loja está no ar"
     * junto do "confirme seu e-mail" poria duas chamadas para ação
     * concorrentes na mesma caixa, e a primeira levaria a um painel trancado.
     */
    private function sendVerification(User $user, Tenant $tenant): void
    {
        try {
            $this->verifier->sendLink($user, $tenant);
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar o e-mail de confirmação do cadastro.', [
                'tenant_id' => $tenant->id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Plano escolhido no cadastro, garantido em banco.
     *
     * O `firstOrCreate` cobre o ambiente onde o seed nunca rodou — sem ele o
     * primeiro cadastro numa base recém-migrada morreria por falta da linha.
     * Slug desconhecido cai no Pro em vez de estourar: o controller já valida
     * a entrada, então chegar aqui com outra coisa é bug nosso, e degradar
     * para o plano completo erra a favor do lojista.
     */
    private function resolvePlan(string $slug): Plan
    {
        $definitions = PlanSeeder::definitions();

        if (! isset($definitions[$slug])) {
            $slug = Plan::PRO;
        }

        return Plan::firstOrCreate(['slug' => $slug], $definitions[$slug]);
    }

    /** Slug único, previsível e livre de colisão com rotas da plataforma. */
    public function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'loja';

        if (in_array($base, self::RESERVED_SLUGS, true)) {
            $base = "{$base}-loja";
        }

        $slug = $base;
        $suffix = 2;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function isSlugAvailable(string $slug): bool
    {
        return ! in_array($slug, self::RESERVED_SLUGS, true)
            && ! Tenant::withTrashed()->where('slug', $slug)->exists();
    }

    /** Seg–dom, 18h–23h: ponto de partida plausível, ajustável no wizard. */
    private function defaultHours(): array
    {
        return collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
            ->mapWithKeys(fn (string $day) => [
                $day => ['open' => '18:00', 'close' => '23:00', 'enabled' => true],
            ])
            ->all();
    }
}
