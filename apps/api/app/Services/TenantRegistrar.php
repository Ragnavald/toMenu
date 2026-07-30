<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSettings;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private TenantContext $context) {}

    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $plan = Plan::firstOrCreate(
                ['slug' => 'pro'],
                [
                    'name' => 'Pro',
                    'price_cents' => 9900,
                    'max_products' => 500,
                    'allows_online_payment' => true,
                ]
            );

            $tenant = Tenant::create([
                'name' => $data['store_name'],
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['store_name']),
                'plan_id' => $plan->id,
                'status' => 'trial',
                'trial_ends_at' => now()->addDays(14),
                'onboarding_step' => 1,
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
                    ],
                    'payment_methods' => ['cash'],
                    'business_hours' => $this->defaultHours(),
                ]);
            });

            return [
                'tenant' => $tenant,
                'token' => $user->createToken('admin')->plainTextToken,
                'user' => $user,
            ];
        });
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
