<?php

use App\Jobs\ProcessStripeEvent;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\SubscriptionService;
use App\Tenancy\TenantContext;
use Stripe\StripeClient;

/**
 * Assinatura do lojista na plataforma (a mensalidade).
 *
 * O `SubscriptionService` é mockado nos testes de endpoint: o que se verifica
 * aqui é quem pode assinar e o que o webhook faz com o estado da loja — não a
 * chamada ao Stripe.
 */
beforeEach(function () {
    /*
     * Credenciais fictícias de teste.
     *
     * O ambiente da suíte não tem Stripe configurado, e sem isto o
     * `isConfigured()` do controller responde 503 antes de chegar às regras
     * que estes testes verificam. O `StripeClient` real nunca é construído: o
     * serviço é mockado nos testes de endpoint.
     */
    config([
        'services.stripe.mode' => 'test',
        'services.stripe.test.key' => 'pk_test_fake',
        'services.stripe.test.secret' => 'sk_test_fake',
        'services.stripe.test.webhook_secret' => 'whsec_fake',
    ]);

    $this->plan = Plan::factory()->create([
        'slug' => Plan::PRO,
        'price_cents' => 9900,
        'stripe_price_id' => 'price_test_pro',
    ]);

    $this->tenant = Tenant::factory()->create([
        'slug' => 'loja-a',
        'plan_id' => $this->plan->id,
        'status' => 'trial',
        'trial_ends_at' => now()->addDays(10),
    ]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'owner',
    ]);
});

function headersFor(Tenant $tenant): array
{
    return ['X-Tenant' => $tenant->slug];
}

it('abre o checkout e devolve a url do stripe', function () {
    $service = Mockery::mock(SubscriptionService::class);
    $service->shouldReceive('createCheckoutSession')
        ->once()
        ->andReturn('https://checkout.stripe.com/c/pay/cs_test_123');
    app()->instance(SubscriptionService::class, $service);

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/admin/billing/checkout', [], headersFor($this->tenant))
        ->assertOk()
        ->assertJsonPath('url', 'https://checkout.stripe.com/c/pay/cs_test_123');
});

it('recusa assinatura de quem não é dono da loja', function () {
    $membro = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'manager',
    ]);

    $service = Mockery::mock(SubscriptionService::class);
    $service->shouldNotReceive('createCheckoutSession');
    app()->instance(SubscriptionService::class, $service);

    // Quem assina é quem paga. Um gerente com acesso ao painel não deve
    // conseguir criar uma cobrança recorrente no cartão do dono.
    $this->actingAs($membro, 'sanctum')
        ->postJson('/api/admin/billing/checkout', [], headersFor($this->tenant))
        ->assertForbidden();
});

it('não abre um segundo checkout para quem já assina', function () {
    $this->tenant->update(['subscription_status' => 'active']);

    $service = Mockery::mock(SubscriptionService::class);
    $service->shouldNotReceive('createCheckoutSession');
    app()->instance(SubscriptionService::class, $service);

    // Assinar de novo criaria uma segunda mensalidade para a mesma loja.
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/admin/billing/checkout', [], headersFor($this->tenant))
        ->assertStatus(422);
});

it('recusa o portal para loja que nunca assinou', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/admin/billing/portal', [], headersFor($this->tenant))
        ->assertStatus(422);
});

it('mostra o estado da assinatura e os dias de trial', function () {
    $this->actingAs($this->owner, 'sanctum')
        ->getJson('/api/admin/billing', headersFor($this->tenant))
        ->assertOk()
        ->assertJsonPath('plan.priceCents', 9900)
        ->assertJsonPath('subscribed', false)
        ->assertJsonPath('active', false)
        ->assertJsonPath('trialExpired', false)
        ->assertJsonPath('trialDaysLeft', 10);
});

/** Dispara um evento de webhook já gravado, como faz o controller. */
function processStripe(string $type, array $object): void
{
    WebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => 'evt_'.uniqid(),
        'type' => $type,
        'payload' => ['data' => ['object' => $object]],
    ]);

    $record = WebhookEvent::where('type', $type)->latest('id')->first();

    (new ProcessStripeEvent($record->event_id))->handle(app(TenantContext::class));
}

it('vincula a assinatura ao tenant quando o checkout conclui', function () {
    processStripe('checkout.session.completed', [
        'id' => 'cs_test_1',
        'mode' => 'subscription',
        'subscription' => 'sub_test_1',
        'customer' => 'cus_test_1',
        'metadata' => ['tenant_id' => (string) $this->tenant->id],
    ]);

    $this->tenant->refresh();

    expect($this->tenant->stripe_subscription_id)->toBe('sub_test_1')
        ->and($this->tenant->stripe_customer_id)->toBe('cus_test_1');
});

it('ignora checkout que não é de assinatura', function () {
    processStripe('checkout.session.completed', [
        'id' => 'cs_test_2',
        // Sessão de pagamento avulso não cria mensalidade.
        'mode' => 'payment',
        'subscription' => null,
        'metadata' => ['tenant_id' => (string) $this->tenant->id],
    ]);

    expect($this->tenant->refresh()->stripe_subscription_id)->toBeNull();
});

it('espelha o status vindo do stripe', function () {
    $this->tenant->update(['stripe_subscription_id' => 'sub_test_1']);

    processStripe('customer.subscription.updated', [
        'id' => 'sub_test_1',
        'status' => 'active',
        'current_period_end' => now()->addMonth()->timestamp,
    ]);

    $this->tenant->refresh();

    expect($this->tenant->subscription_status)->toBe('active')
        ->and($this->tenant->current_period_ends_at)->not->toBeNull()
        ->and($this->tenant->hasActiveSubscription())->toBeTrue();
});

it('marca a loja como inadimplente quando a fatura falha', function () {
    $this->tenant->update([
        'stripe_subscription_id' => 'sub_test_1',
        'status' => 'active',
    ]);

    processStripe('invoice.payment_failed', [
        'id' => 'in_test_1',
        'subscription' => 'sub_test_1',
    ]);

    $this->tenant->refresh();

    // A loja continua no ar: derrubar um restaurante no almoço por um cartão
    // recusado é desproporcional, e o Stripe ainda vai recobrar.
    expect($this->tenant->status)->toBe('past_due')
        ->and($this->tenant->deleted_at)->toBeNull();
});

it('não reativa loja suspensa quando o pagamento entra', function () {
    $this->tenant->update([
        'stripe_subscription_id' => 'sub_test_1',
        'status' => 'suspended',
    ]);

    processStripe('customer.subscription.updated', [
        'id' => 'sub_test_1',
        'status' => 'active',
        'current_period_end' => now()->addMonth()->timestamp,
    ]);

    // A suspensão é decisão do staff e pode ter outro motivo que não a
    // cobrança. Pagar não desfaz sozinho uma decisão administrativa.
    expect($this->tenant->refresh()->status)->toBe('suspended');
});

it('tira a loja de past_due quando a assinatura volta a ficar ativa', function () {
    $this->tenant->update([
        'stripe_subscription_id' => 'sub_test_1',
        'status' => 'past_due',
    ]);

    processStripe('customer.subscription.updated', [
        'id' => 'sub_test_1',
        'status' => 'active',
        'current_period_end' => now()->addMonth()->timestamp,
    ]);

    // Vale inclusive quando o lojista regularizou pelo portal do Stripe, sem
    // passar pela aplicação.
    expect($this->tenant->refresh()->status)->toBe('active');
});

it('marca past_due no vencimento do trial sem assinatura', function () {
    $this->tenant->update(['trial_ends_at' => now()->subDay()]);

    $this->artisan('trials:expire')->assertSuccessful();

    expect($this->tenant->refresh()->status)->toBe('past_due');
});

it('não marca past_due quem tem assinatura em dia', function () {
    $this->tenant->update([
        'trial_ends_at' => now()->subDay(),
        'subscription_status' => 'active',
    ]);

    // O Stripe cobra sozinho no fim do trial; marcar como devedor cobraria de
    // novo a atenção de quem já resolveu.
    $this->artisan('trials:expire')->assertSuccessful();

    expect($this->tenant->refresh()->status)->toBe('trial');
});

it('não mexe em loja suspensa ao expirar trials', function () {
    $this->tenant->update([
        'trial_ends_at' => now()->subDay(),
        'status' => 'suspended',
    ]);

    $this->artisan('trials:expire')->assertSuccessful();

    expect($this->tenant->refresh()->status)->toBe('suspended');
});

/*
 * Troca de STRIPE_MODE.
 *
 * Os ids do Stripe (`cus_`, `sub_`) não carregam o ambiente no nome, então
 * trocar o modo deixa o banco apontando para objetos que a chave ativa não
 * enxerga — o Checkout morria com "No such customer" em todo clique, sem saída
 * pela interface. A coluna `stripe_mode` é quem distingue.
 */
it('descarta o vinculo do stripe quando o modo muda e cria outro cliente', function () {
    $this->tenant->update([
        'stripe_customer_id' => 'cus_do_modo_live',
        'stripe_mode' => 'live',
        'stripe_subscription_id' => 'sub_do_modo_live',
        'subscription_status' => 'active',
    ]);

    $service = app(SubscriptionService::class);

    // Só a criação do cliente é interceptada: o resto do caminho (detectar o
    // descasamento, limpar e regravar) é o que está sendo verificado.
    $customers = Mockery::mock();
    $customers->shouldReceive('create')->once()->andReturn((object) ['id' => 'cus_do_modo_test']);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->customers = $customers;

    (function () use ($stripe) {
        $this->stripe = $stripe;
    })->call($service);

    expect($service->ensureCustomer($this->tenant->fresh()))->toBe('cus_do_modo_test');

    $tenant = $this->tenant->refresh();

    expect($tenant->stripe_customer_id)->toBe('cus_do_modo_test')
        ->and($tenant->stripe_mode)->toBe('test')
        // A assinatura do outro ambiente vai junto: mantê-la faria a exclusão
        // da loja tentar cancelar um `sub_` que não existe na conta ativa.
        ->and($tenant->stripe_subscription_id)->toBeNull()
        ->and($tenant->subscription_status)->toBeNull();
});

it('mantem o cliente do stripe quando o modo continua o mesmo', function () {
    $this->tenant->update([
        'stripe_customer_id' => 'cus_ja_existente',
        'stripe_mode' => 'test',
    ]);

    // Sem mock do StripeClient de propósito: se o serviço tentar criar um
    // cliente, a chamada real falha e o teste denuncia a recriação indevida.
    expect(app(SubscriptionService::class)->ensureCustomer($this->tenant->fresh()))
        ->toBe('cus_ja_existente');

    expect($this->tenant->refresh()->stripe_customer_id)->toBe('cus_ja_existente');
});

it('nao considera assinante a loja cujo vinculo e de outro modo', function () {
    $this->tenant->update([
        'stripe_customer_id' => 'cus_do_modo_live',
        'stripe_mode' => 'live',
        'stripe_subscription_id' => 'sub_do_modo_live',
        'subscription_status' => 'active',
    ]);

    $this->actingAs($this->owner, 'sanctum')
        ->getJson('/api/admin/billing', headersFor($this->tenant))
        ->assertOk()
        ->assertJson(['subscribed' => false, 'active' => false, 'status' => null]);
});

it('deixa assinar de novo quando a assinatura ativa e de outro modo', function () {
    $this->tenant->update([
        'stripe_customer_id' => 'cus_do_modo_live',
        'stripe_mode' => 'live',
        'stripe_subscription_id' => 'sub_do_modo_live',
        'subscription_status' => 'active',
    ]);

    $service = Mockery::mock(SubscriptionService::class);
    $service->shouldReceive('createCheckoutSession')->once()->andReturn('https://checkout.stripe.com/x');
    app()->instance(SubscriptionService::class, $service);

    // Sem a checagem de modo, o guard de "já tem assinatura ativa" devolveria
    // 422 e trancaria o lojista fora do ambiente novo.
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/admin/billing/checkout', [], headersFor($this->tenant))
        ->assertOk();
});

it('recusa o portal quando o vinculo e de outro modo', function () {
    $this->tenant->update([
        'stripe_customer_id' => 'cus_do_modo_live',
        'stripe_mode' => 'live',
        'subscription_status' => 'active',
    ]);

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/admin/billing/portal', [], headersFor($this->tenant))
        ->assertStatus(422);
});

it('mantem o acesso ao painel de quem paga mesmo com o modo trocado', function () {
    $this->tenant->update([
        'stripe_customer_id' => 'cus_do_modo_live',
        'stripe_mode' => 'live',
        'subscription_status' => 'active',
        'trial_ends_at' => now()->subDays(30),
    ]);

    /*
     * O ponto central do desenho: `hasActiveSubscription()` ignora o modo.
     *
     * É ele que os middlewares consultam. Se dependesse do `STRIPE_MODE`, um
     * `.env` errado num deploy derrubaria TODA loja paga ao mesmo tempo, em
     * silêncio — falha muito pior que o 502 isolado que o descasamento causa.
     */
    expect($this->tenant->refresh()->hasActiveSubscription())->toBeTrue()
        ->and($this->tenant->trialHasExpired())->toBeFalse();
});

it('grava o modo ativo quando o webhook confirma a assinatura', function () {
    $this->tenant->update(['stripe_customer_id' => null, 'stripe_mode' => null]);

    processStripe('checkout.session.completed', [
        'id' => 'cs_modo',
        'mode' => 'subscription',
        'subscription' => 'sub_novo',
        'customer' => 'cus_novo',
        'metadata' => ['tenant_id' => (string) $this->tenant->id],
    ]);

    $tenant = $this->tenant->refresh();

    // Sem gravar o modo aqui, o vínculo recém-criado seria descartado como "de
    // outro ambiente" no clique seguinte — logo depois de o lojista pagar.
    expect($tenant->stripe_customer_id)->toBe('cus_novo')
        ->and($tenant->stripe_mode)->toBe('test');
});
