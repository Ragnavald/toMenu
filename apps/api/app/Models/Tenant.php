<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'slug', 'plan_id', 'status', 'trial_ends_at', 'deletion_reason',
    'stripe_customer_id', 'stripe_account_id', 'stripe_charges_enabled',
    'stripe_subscription_id', 'subscription_status', 'current_period_ends_at',
    'onboarding_step', 'onboarding_completed_at',
    'terms_accepted_at', 'terms_version', 'terms_accepted_ip',
])]
#[Hidden(['stripe_customer_id', 'stripe_subscription_id'])]
class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'onboarding_step' => 'integer',
            'stripe_charges_enabled' => 'boolean',
            'current_period_ends_at' => 'datetime',
        ];
    }

    /**
     * A assinatura está em dia?
     *
     * `trialing` conta como em dia: o lojista tem cartão cadastrado e o Stripe
     * vai cobrar sozinho no fim do trial.
     */
    public function hasActiveSubscription(): bool
    {
        return in_array($this->subscription_status, ['active', 'trialing'], true);
    }

    /** Já assinou alguma vez, mesmo que a assinatura hoje esteja cancelada. */
    public function hasSubscribed(): bool
    {
        return $this->stripe_subscription_id !== null;
    }

    /**
     * Trial vencido e sem assinatura em dia.
     *
     * Distingue quem nunca assinou de quem assinou e falhou no pagamento: os
     * dois pedem cobrança, mas a mensagem no painel é diferente.
     */
    public function trialHasExpired(): bool
    {
        return $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast()
            && ! $this->hasActiveSubscription();
    }

    /** Dias restantes do trial; negativo quando já venceu. */
    public function trialDaysLeft(): ?int
    {
        return $this->trial_ends_at
            ? (int) ceil(now()->diffInDays($this->trial_ends_at, false))
            : null;
    }

    public function settings(): HasOne
    {
        return $this->hasOne(TenantSettings::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * A loja recebe pedidos pelo site?
     *
     * Capacidade do plano. O `?? true` cobre o tenant cujo plano não foi
     * carregado — nenhuma loja deve perder a operação por um eager load
     * esquecido; o default erra a favor de quem paga o plano completo.
     */
    public function allowsOrders(): bool
    {
        return $this->plan?->allows_orders ?? true;
    }

    /** A loja configura entrega própria? Sem pedidos, entrega não existe. */
    public function allowsDelivery(): bool
    {
        return $this->plan?->allows_delivery ?? true;
    }

    /** Só cardápio: vitrine publicada, sem carrinho, checkout ou operação. */
    public function isMenuOnly(): bool
    {
        return ! $this->allowsOrders();
    }

    /**
     * Pagamento online exige duas coisas independentes: o plano permitir e o
     * onboarding do Stripe Connect ter concluído. Sem a primeira, o checkout
     * nem existe; sem a segunda, ele existiria e falharia na cobrança.
     */
    public function acceptsOnlinePayment(): bool
    {
        return ($this->plan?->allows_online_payment ?? true)
            && $this->stripe_charges_enabled
            && $this->stripe_account_id !== null;
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    /**
     * URL pública da loja: sempre subdomínio do domínio raiz.
     *
     * A porta é configurável porque em desenvolvimento o storefront não roda
     * na 80/443.
     */
    public function storefrontUrl(): string
    {
        $scheme = config('tenancy.storefront_scheme', 'https');

        $host = "{$this->slug}.".config('tenancy.root_domain');
        $port = config('tenancy.storefront_port');

        return $port ? "{$scheme}://{$host}:{$port}" : "{$scheme}://{$host}";
    }

    /**
     * Invalida o cardápio cacheado.
     *
     * Bump de versão em vez de Cache::forget: a operação é atômica no banco e
     * imune a race condition. Uma escrita concorrente durante o forget poderia
     * repovoar a chave com dado obsoleto; mudar a chave torna isso impossível.
     */
    public function bumpMenuVersion(): void
    {
        $this->increment('menu_version');
    }
}
