<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O painel é servido de `app.{dominio}` e chama a API na mesma origem.
 *
 * Nesse host nenhum dos identificadores clássicos funciona: `app` é subdomínio
 * reservado, a rota não tem `{tenant}` no path, e o X-Tenant é ignorado em
 * produção. Estes testes fixam o comportamento com `trust_header=false`, que é
 * o de produção — sem isso a regressão não aparece.
 */
class AdminSameOriginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exatamente como produção: o header não é confiável.
        config([
            'tenancy.trust_header' => false,
            'tenancy.root_domain' => 'to-menu.com',
        ]);
    }

    private function makeTenant(string $slug): Tenant
    {
        $plan = Plan::firstOrCreate(['slug' => 'pro'], [
            'name' => 'Pro',
            'price_cents' => 9900,
            'max_products' => 500,
            'allows_online_payment' => true,
        ]);

        return Tenant::create([
            'name' => "Loja {$slug}",
            'slug' => $slug,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    private function owner(Tenant $tenant): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Dono',
            'email' => "dono-{$tenant->id}@teste.test",
            'password' => 'password',
            'role' => 'owner',
        ]);
    }

    /**
     * Regressão: em produção isto respondia 404 "Loja não encontrada", e o
     * painel inteiro ficava inacessível logo após o cadastro.
     */
    public function test_admin_resolve_o_tenant_pelo_usuario_no_host_do_painel(): void
    {
        $tenant = $this->makeTenant('padaria');
        $owner = $this->owner($tenant);

        $this->actingAs($owner, 'sanctum')
            ->getJson('http://app.to-menu.com/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('store.slug', 'padaria');
    }

    public function test_categorias_tambem_resolvem(): void
    {
        $tenant = $this->makeTenant('mercearia');
        $owner = $this->owner($tenant);

        $this->actingAs($owner, 'sanctum')
            ->getJson('http://app.to-menu.com/api/admin/categories')
            ->assertOk();
    }

    /**
     * O ponto crítico de segurança: o fallback usa o vínculo do banco, então
     * não pode virar uma porta para alcançar outra loja pelo host.
     */
    public function test_usuario_nao_alcanca_outra_loja_pelo_host(): void
    {
        $mine = $this->makeTenant('minha-loja');
        $other = $this->makeTenant('loja-alheia');
        $owner = $this->owner($mine);

        // Host aponta para a loja alheia; o token é da minha.
        $this->actingAs($owner, 'sanctum')
            ->getJson('http://loja-alheia.to-menu.com/api/admin/settings')
            ->assertStatus(403);

        $this->assertNotSame($mine->id, $other->id);
    }

    /** O X-Tenant continua ignorado: o fallback não pode ressuscitá-lo. */
    public function test_header_x_tenant_continua_sendo_ignorado(): void
    {
        $mine = $this->makeTenant('minha-loja');
        $this->makeTenant('loja-alheia');
        $owner = $this->owner($mine);

        // Mesmo pedindo outra loja pelo header, o tenant resolvido é o do
        // usuário — o header não tem efeito algum.
        $this->actingAs($owner, 'sanctum')
            ->getJson('http://app.to-menu.com/api/admin/settings', [
                'X-Tenant' => 'loja-alheia',
            ])
            ->assertOk()
            ->assertJsonPath('store.slug', 'minha-loja');
    }

    /** Rota pública não pode passar a resolver tenant por usuário logado. */
    public function test_storefront_nao_resolve_pelo_usuario(): void
    {
        $tenant = $this->makeTenant('padaria');
        $owner = $this->owner($tenant);

        // Autenticado, mas num host que não identifica loja alguma: o cardápio
        // não pode "adivinhar" a loja do usuário.
        $this->actingAs($owner, 'sanctum')
            ->getJson('http://app.to-menu.com/api/menu')
            ->assertStatus(404);
    }

    /** Sem autenticação o comportamento anterior é preservado: 401, não 404. */
    public function test_sem_token_continua_401(): void
    {
        $this->makeTenant('padaria');

        $this->getJson('http://app.to-menu.com/api/admin/settings')
            ->assertStatus(401);
    }
}
