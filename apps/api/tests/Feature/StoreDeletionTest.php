<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Service\AccountService;
use Stripe\StripeClient;
use Tests\TestCase;

class StoreDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.trust_header' => true]);

        // Nenhum teste pode alcançar a API do Stripe de verdade. Sem esta
        // substituição o StripeClient nem sequer é construído: a suíte não
        // define STRIPE_SECRET e o SDK recusa api_key vazia.
        $stripe = $this->createMock(StripeClient::class);
        $accounts = $this->createMock(AccountService::class);
        $accounts->method('update')->willReturn(null);
        $stripe->accounts = $accounts;

        $this->app->instance(StripeClient::class, $stripe);
    }

    private function makeTenant(string $slug = 'loja-teste'): Tenant
    {
        $plan = Plan::firstOrCreate(['slug' => 'pro'], [
            'name' => 'Pro',
            'price_cents' => 9900,
            'max_products' => 500,
            'allows_online_payment' => true,
        ]);

        return Tenant::create([
            'name' => 'Loja Teste',
            'slug' => $slug,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    private function owner(Tenant $tenant): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Dona',
            'email' => "dona-{$tenant->id}@teste.test",
            'password' => 'password',
            'role' => 'owner',
        ]);
    }

    private function headers(Tenant $tenant): array
    {
        return ['X-Tenant' => $tenant->slug];
    }

    public function test_owner_exclui_a_loja(): void
    {

        $tenant = $this->makeTenant();
        $owner = $this->owner($tenant);

        $response = $this->actingAs($owner, 'sanctum')->deleteJson('/api/admin/store', [
            'password' => 'password',
            'confirmation' => $tenant->slug,
            'reason' => 'Fechei o restaurante.',
        ], $this->headers($tenant));

        $response->assertOk()->assertJson(['deleted' => true]);

        $this->assertSoftDeleted('tenants', ['id' => $tenant->id]);
    }

    public function test_exclusao_exige_slug_correto(): void
    {

        $tenant = $this->makeTenant();
        $owner = $this->owner($tenant);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/admin/store', [
                'password' => 'password',
                'confirmation' => 'errado',
            ], $this->headers($tenant))
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmation');

        $this->assertNotSoftDeleted('tenants', ['id' => $tenant->id]);
    }

    public function test_exclusao_exige_senha_correta(): void
    {

        $tenant = $this->makeTenant();
        $owner = $this->owner($tenant);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/admin/store', [
                'password' => 'senha-errada',
                'confirmation' => $tenant->slug,
            ], $this->headers($tenant))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNotSoftDeleted('tenants', ['id' => $tenant->id]);
    }

    public function test_membro_que_nao_e_owner_nao_exclui(): void
    {

        $tenant = $this->makeTenant();

        $staff = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Gerente',
            'email' => 'gerente@teste.test',
            'password' => 'password',
            'role' => 'staff',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->deleteJson('/api/admin/store', [
                'password' => 'password',
                'confirmation' => $tenant->slug,
            ], $this->headers($tenant))
            ->assertStatus(403);

        $this->assertNotSoftDeleted('tenants', ['id' => $tenant->id]);
    }

    /** O ponto crítico: a loja precisa sair do ar de fato. */
    public function test_storefront_para_de_responder_apos_exclusao(): void
    {

        $tenant = $this->makeTenant();
        $owner = $this->owner($tenant);

        // Aquece o cache slug -> id, como um acesso real faria.
        $this->getJson('/api/menu', $this->headers($tenant));

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/admin/store', [
            'password' => 'password',
            'confirmation' => $tenant->slug,
        ], $this->headers($tenant))->assertOk();

        $this->getJson('/api/menu', $this->headers($tenant))->assertStatus(404);
    }

    public function test_tokens_dos_usuarios_sao_revogados(): void
    {

        $tenant = $this->makeTenant();
        $owner = $this->owner($tenant);
        $owner->createToken('admin');

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/admin/store', [
            'password' => 'password',
            'confirmation' => $tenant->slug,
        ], $this->headers($tenant))->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $owner->id,
            'tokenable_type' => User::class,
        ]);
    }

    /** O slug fica retido: uma loja nova não pode herdar o subdomínio. */
    public function test_slug_nao_e_liberado_para_reuso(): void
    {

        $tenant = $this->makeTenant('padaria');
        $owner = $this->owner($tenant);

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/admin/store', [
            'password' => 'password',
            'confirmation' => 'padaria',
        ], $this->headers($tenant))->assertOk();

        $registrar = app(\App\Services\TenantRegistrar::class);

        $this->assertFalse($registrar->isSlugAvailable('padaria'));
        $this->assertSame('padaria-2', $registrar->uniqueSlug('padaria'));
    }

    /**
     * Sem mock do Stripe e sem STRIPE_SECRET, como é o ambiente de
     * desenvolvimento padrão.
     *
     * Regressão: o TenantDeleter recebia o StripeClient pelo construtor, e o
     * SDK lança ao validar api_key vazia. A exclusão respondia 500 antes de
     * executar uma linha sequer — inclusive para loja sem Stripe conectado. Os
     * outros testes não pegavam isso porque o setUp injeta um mock.
     */
    public function test_exclui_mesmo_sem_stripe_configurado(): void
    {
        $this->app->forgetInstance(StripeClient::class);
        // Zera as credenciais do modo ativo: resolver o StripeClient passa a
        // lançar, que é exatamente a condição que este teste protege.
        config([
            'services.stripe.mode' => 'test',
            'services.stripe.test.secret' => '',
            'services.stripe.test.key' => '',
        ]);

        $tenant = $this->makeTenant('sem-stripe');
        $owner = $this->owner($tenant);

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/admin/store', [
            'password' => 'password',
            'confirmation' => 'sem-stripe',
        ], $this->headers($tenant))->assertOk();

        $this->assertSoftDeleted('tenants', ['id' => $tenant->id]);
    }

    public function test_preview_mostra_o_que_sera_perdido(): void
    {

        $tenant = $this->makeTenant();
        $owner = $this->owner($tenant);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/admin/store/deletion-preview', $this->headers($tenant))
            ->assertOk()
            ->assertJsonStructure([
                'store' => ['name', 'slug'],
                'willLose' => ['products', 'orders', 'users'],
                'stripeConnected',
            ]);
    }
}
