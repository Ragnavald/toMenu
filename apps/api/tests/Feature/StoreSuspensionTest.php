<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreSuspensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.trust_header' => true]);
    }

    private function makeTenant(string $slug = 'loja-teste', string $status = 'active'): Tenant
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
            'status' => $status,
        ]);
    }

    public function test_suspende_a_loja_pelo_comando(): void
    {
        $tenant = $this->makeTenant();

        $this->artisan('store:suspend', ['slug' => $tenant->slug])
            ->assertSuccessful();

        $this->assertSame('suspended', $tenant->fresh()->status);
    }

    /** O ponto do recurso: a loja sai do ar para o cliente final. */
    public function test_storefront_responde_403_quando_suspensa(): void
    {
        $tenant = $this->makeTenant();

        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])->assertOk();

        $this->artisan('store:suspend', ['slug' => $tenant->slug]);

        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])
            ->assertStatus(403);
    }

    public function test_reativa_a_loja_pelo_comando(): void
    {
        $tenant = $this->makeTenant(status: 'suspended');

        $this->artisan('store:reactivate', ['slug' => $tenant->slug])
            ->assertSuccessful();

        $this->assertSame('active', $tenant->fresh()->status);

        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])->assertOk();
    }

    /**
     * Suspender não é excluir: nada pode ser perdido no caminho de ida e volta.
     */
    public function test_historico_sobrevive_ao_ciclo_de_suspensao(): void
    {
        $tenant = $this->makeTenant();

        $before = [
            'products' => $tenant->products()->count(),
            'orders' => $tenant->orders()->count(),
            'users' => $tenant->users()->count(),
        ];

        $this->artisan('store:suspend', ['slug' => $tenant->slug]);
        $this->artisan('store:reactivate', ['slug' => $tenant->slug]);

        $tenant->refresh();

        $this->assertSame($before['products'], $tenant->products()->count());
        $this->assertSame($before['orders'], $tenant->orders()->count());
        $this->assertSame($before['users'], $tenant->users()->count());
        $this->assertNull($tenant->deleted_at);
    }

    public function test_suspender_loja_inexistente_falha(): void
    {
        $this->artisan('store:suspend', ['slug' => 'nao-existe'])
            ->assertFailed();
    }

    public function test_suspender_duas_vezes_e_inofensivo(): void
    {
        $tenant = $this->makeTenant(status: 'suspended');

        $this->artisan('store:suspend', ['slug' => $tenant->slug])
            ->assertSuccessful();

        $this->assertSame('suspended', $tenant->fresh()->status);
    }

    /**
     * A suspensão vale mesmo com o cache do tenant já aquecido.
     *
     * O IdentifyTenant cacheia só o ID e relê a linha, então o status nunca
     * fica obsoleto — este teste fixa esse contrato. Se alguém passar a cachear
     * o model inteiro, ele quebra, que é exatamente o alarme desejado.
     */
    public function test_suspensao_vale_com_cache_aquecido(): void
    {
        $tenant = $this->makeTenant();

        // Aquece o cache, como faria um acesso real antes da suspensão.
        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])->assertOk();

        $this->artisan('store:suspend', ['slug' => $tenant->slug]);

        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])
            ->assertStatus(403);
    }

    /**
     * Regressão do cache negativo: um acesso à loja suspensa pode gravar 0
     * ("slug não existe") na chave, com TTL de uma hora. Sem o forget na
     * reativação, a loja voltaria a 404 mesmo já estando ativa.
     */
    public function test_loja_volta_ao_ar_mesmo_apos_acesso_durante_a_suspensao(): void
    {
        $tenant = $this->makeTenant(status: 'suspended');

        // Cliente tentando acessar enquanto a loja está fora do ar.
        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])
            ->assertStatus(403);

        $this->artisan('store:reactivate', ['slug' => $tenant->slug]);

        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])->assertOk();
    }
}
