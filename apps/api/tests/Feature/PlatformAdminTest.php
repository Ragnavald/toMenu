<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PlatformAuditLog;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stripe\Service\AccountService;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Painel da plataforma: quem entra, o que enxerga e o que consegue destruir.
 *
 * O foco dos testes é a fronteira de autorização. As rotas daqui operam sobre
 * todas as lojas sem passar por identify.tenant, então o `platform.admin` é a
 * única coisa separando um token de lojista do controle da plataforma inteira
 * — e é isso que a maior parte destes casos fixa.
 */
class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.trust_header' => true]);

        // Nenhum teste alcança o Stripe. Sem a substituição o SDK nem constrói:
        // a suíte não define STRIPE_SECRET e ele recusa api_key vazia.
        $stripe = $this->createMock(StripeClient::class);
        $accounts = $this->createMock(AccountService::class);
        $accounts->method('update')->willReturn(null);
        $stripe->accounts = $accounts;

        $this->app->instance(StripeClient::class, $stripe);
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

    private function staff(string $email = 'equipe@to-menu.com'): User
    {
        return User::create([
            'tenant_id' => null,
            'name' => 'Equipe',
            'email' => $email,
            'password' => 'senha-muito-longa',
            'role' => 'platform',
            'is_platform_admin' => true,
        ]);
    }

    /**
     * Autentica como staff usando um token real, e não `Sanctum::actingAs`.
     *
     * O actingAs chama `setUser` no guard, o que fixa o usuário para TODAS as
     * requisições do teste: um Bearer enviado depois é simplesmente ignorado, e
     * os casos que verificam a expiração e a revogação de um token de
     * impersonação passariam com 200 sem testar nada. Com token real, cada
     * requisição é autenticada pelo header que ela mesma envia.
     */
    private function actingAsStaff(?User $staff = null): User
    {
        $staff ??= $this->staff();

        $this->withToken($staff->createToken('platform', ['platform'])->plainTextToken);

        return $staff;
    }

    /**
     * Troca a identidade da requisição, descartando a anterior por completo.
     *
     * Duas limpezas, cada uma para um problema distinto do ambiente de teste —
     * ambas descobertas fazendo os testes de expiração passarem por engano:
     *
     * `flushHeaders` porque os headers do `actingAsStaff` ficam em
     * `defaultHeaders` e são mesclados em toda requisição seguinte; sem limpar,
     * o Authorization do staff continua indo junto e é ele quem autentica.
     *
     * `Auth::forgetGuards` porque o guard mantém o usuário já resolvido para o
     * resto do teste. Numa requisição HTTP real cada processo começa do zero e
     * o Sanctum revalida o token, mas aqui a segunda chamada reaproveitaria o
     * usuário da primeira e nunca reavaliaria `expires_at` — um token expirado
     * ou revogado seguiria respondendo 200.
     */
    private function withTokenOnly(string $token, array $headers = []): static
    {
        \Illuminate\Support\Facades\Auth::forgetGuards();

        return $this->flushHeaders()->withToken($token)->withHeaders($headers);
    }

    // --- Autenticação --------------------------------------------------

    public function test_staff_entra_com_email_e_senha(): void
    {
        $this->staff();

        $this->postJson('/api/platform/auth/login', [
            'email' => 'equipe@to-menu.com',
            'password' => 'senha-muito-longa',
        ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    /**
     * O login da plataforma não é uma porta lateral para o painel do lojista.
     *
     * A conta de uma loja tem tenant_id preenchido; se o filtro por
     * `whereNull('tenant_id')` sumisse, o dono de qualquer loja entraria aqui
     * com as próprias credenciais.
     */
    public function test_dono_de_loja_nao_entra_no_painel_da_plataforma(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->owner($tenant);

        $this->postJson('/api/platform/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ])->assertStatus(422);
    }

    /** Conta central sem a flag não é staff: o privilégio precisa ser explícito. */
    public function test_usuario_central_sem_a_flag_nao_entra(): void
    {
        User::create([
            'tenant_id' => null,
            'name' => 'Central',
            'email' => 'central@to-menu.com',
            'password' => 'senha-muito-longa',
            'role' => 'platform',
            'is_platform_admin' => false,
        ]);

        $this->postJson('/api/platform/auth/login', [
            'email' => 'central@to-menu.com',
            'password' => 'senha-muito-longa',
        ])->assertStatus(422);
    }

    // --- Autorização das rotas -----------------------------------------

    public function test_rotas_da_plataforma_exigem_autenticacao(): void
    {
        $this->getJson('/api/platform/stores')->assertStatus(401);
    }

    /**
     * O caso mais grave que este painel pode ter.
     *
     * O token do lojista é um token Sanctum perfeitamente válido. Sem o
     * `platform.admin`, ele listaria todas as lojas concorrentes e alcançaria a
     * rota de purga.
     */
    public function test_token_de_lojista_nao_alcanca_o_painel_da_plataforma(): void
    {
        $tenant = $this->makeTenant();
        $token = $this->owner($tenant)->createToken('admin')->plainTextToken;

        $this->withTokenOnly($token);

        $this->getJson('/api/platform/stores')->assertStatus(403);
        $this->deleteJson("/api/platform/stores/{$tenant->slug}", [
            'password' => 'password',
            'confirmation' => $tenant->slug,
        ])->assertStatus(403);
    }

    /**
     * A rota `platform` não pode ser capturada pelo curinga `{tenant}`.
     *
     * Se o grupo de rotas voltasse a ser registrado depois do prefixo
     * `{tenant}`, "platform" viraria um slug de loja e tudo aqui responderia
     * 404 "Loja não encontrada" — falha confusa e difícil de rastrear.
     */
    public function test_prefixo_platform_nao_e_confundido_com_slug_de_loja(): void
    {
        $this->getJson('/api/platform/stores')->assertStatus(401); // e não 404.
    }

    // --- Listagem -------------------------------------------------------

    public function test_lista_as_lojas_com_contagens_e_totais(): void
    {
        $this->actingAsStaff();

        $tenant = $this->makeTenant('pizzaria-do-ze');

        app(TenantContext::class)->set($tenant);
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        Product::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
        ]);
        app(TenantContext::class)->forget();

        $response = $this->getJson('/api/platform/stores')->assertOk();

        $response->assertJsonPath('data.0.slug', 'pizzaria-do-ze')
            ->assertJsonPath('data.0.productsCount', 3)
            ->assertJsonPath('totals.live', 1)
            ->assertJsonPath('totals.active', 1);
    }

    /**
     * Uma loja que o lojista excluiu continua ocupando o slug para sempre. O
     * staff precisa vê-la para explicar por que o subdomínio não abre.
     */
    public function test_lista_inclui_lojas_excluidas_pelo_lojista(): void
    {
        $this->actingAsStaff();

        $tenant = $this->makeTenant('loja-fechada');
        $tenant->delete();

        $this->getJson('/api/platform/stores?status=deleted')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'loja-fechada')
            ->assertJsonPath('data.0.status', 'deleted');
    }

    public function test_busca_por_nome_e_slug(): void
    {
        $this->actingAsStaff();

        $this->makeTenant('aoi-sushi');
        $this->makeTenant('forno-di-napoli');

        $response = $this->getJson('/api/platform/stores?search=sushi')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('aoi-sushi', $response->json('data.0.slug'));
    }

    // --- Suspensão ------------------------------------------------------

    public function test_suspende_e_reativa_a_loja(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();

        $this->postJson("/api/platform/stores/{$tenant->slug}/suspend", [
            'reason' => 'Denúncia de fraude',
        ])->assertOk()->assertJsonPath('status', 'suspended');

        // O efeito real: a loja sai do ar para o cliente final.
        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])->assertStatus(403);

        $this->postJson("/api/platform/stores/{$tenant->slug}/reactivate")
            ->assertOk()
            ->assertJsonPath('status', 'active');

        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])->assertOk();
    }

    /**
     * Regressão do cache negativo: quem tenta acessar durante a suspensão pode
     * gravar "slug não existe" com TTL de uma hora. Sem o forget na reativação,
     * a loja continuaria em 404 depois de reativada.
     */
    public function test_loja_volta_ao_ar_apos_acesso_durante_a_suspensao(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant(status: 'suspended');

        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])->assertStatus(403);

        $this->postJson("/api/platform/stores/{$tenant->slug}/reactivate")->assertOk();

        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])->assertOk();
    }

    public function test_suspender_nao_apaga_nada(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();
        $this->owner($tenant);

        $this->postJson("/api/platform/stores/{$tenant->slug}/suspend")->assertOk();

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'deleted_at' => null]);
        $this->assertSame(1, DB::table('users')->where('tenant_id', $tenant->id)->count());
    }

    // --- Impersonação ---------------------------------------------------

    public function test_impersonacao_devolve_token_que_abre_o_painel_da_loja(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();
        $owner = $this->owner($tenant);

        $response = $this->postJson("/api/platform/stores/{$tenant->slug}/impersonate", [
            'reason' => 'Suporte ao chamado #42',
        ])->assertOk();

        $this->assertSame($owner->email, $response->json('user.email'));

        // O token precisa funcionar de verdade no painel do lojista.
        $this->withTokenOnly($response->json('token'), ['X-Tenant' => $tenant->slug])
            ->getJson('/api/admin/settings')
            ->assertOk();
    }

    /**
     * O token de impersonação não reentra no painel da plataforma.
     *
     * Sem esta barreira, o staff que impersonasse uma loja teria um token que
     * também administra a plataforma — e um vazamento desse token daria acesso
     * à purga de todas as lojas, não só àquela sob suporte.
     */
    public function test_token_de_impersonacao_nao_opera_a_plataforma(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();
        $this->owner($tenant);

        $token = $this->postJson("/api/platform/stores/{$tenant->slug}/impersonate")
            ->assertOk()
            ->json('token');

        $this->withTokenOnly($token)
            ->getJson('/api/platform/stores')
            ->assertStatus(403);
    }

    public function test_token_de_impersonacao_expira(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();
        $this->owner($tenant);

        $token = $this->postJson("/api/platform/stores/{$tenant->slug}/impersonate")
            ->assertOk()
            ->json('token');

        // Vale antes: sem esta asserção, um token quebrado por outro motivo
        // faria o teste passar por engano.
        $this->withTokenOnly($token, ['X-Tenant' => $tenant->slug])
            ->getJson('/api/admin/settings')
            ->assertOk();

        // Depois da janela de 30 minutos, o Sanctum recusa pelo expires_at.
        $this->travel(31)->minutes();

        $this->withTokenOnly($token, ['X-Tenant' => $tenant->slug])
            ->getJson('/api/admin/settings')
            ->assertStatus(401);
    }

    /**
     * Loja suspensa não é impersonável: o IdentifyTenant devolve 403 em toda
     * rota, e o painel abriria num erro incompreensível. Recusar aqui torna a
     * causa explícita.
     */
    public function test_nao_impersona_loja_suspensa(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant(status: 'suspended');
        $this->owner($tenant);

        $this->postJson("/api/platform/stores/{$tenant->slug}/impersonate")
            ->assertStatus(422);
    }

    public function test_revoga_os_tokens_de_impersonacao_da_loja(): void
    {
        $staff = $this->actingAsStaff();
        $tenant = $this->makeTenant();
        $this->owner($tenant);

        $token = $this->postJson("/api/platform/stores/{$tenant->slug}/impersonate")
            ->assertOk()
            ->json('token');

        $this->deleteJson("/api/platform/stores/{$tenant->slug}/impersonate")
            ->assertOk()
            ->assertJsonPath('revoked', 1);

        $this->withTokenOnly($token, ['X-Tenant' => $tenant->slug])
            ->getJson('/api/admin/settings')
            ->assertStatus(401);

        // A revogação é cirúrgica: apaga só os tokens de impersonação, e não a
        // sessão do próprio staff nem os tokens legítimos do lojista.
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $staff->id,
            'name' => 'platform',
        ]);
    }

    // --- Purga ----------------------------------------------------------

    public function test_purga_apaga_a_loja_e_tudo_em_cascata(): void
    {
        $staff = $this->actingAsStaff();
        $tenant = $this->makeTenant();
        $owner = $this->owner($tenant);

        app(TenantContext::class)->set($tenant);
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        Product::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
        ]);
        app(TenantContext::class)->forget();

        $this->deleteJson("/api/platform/stores/{$tenant->slug}", [
            'password' => 'senha-muito-longa',
            'confirmation' => $tenant->slug,
            'reason' => 'Fraude confirmada',
        ])->assertOk()->assertJsonPath('purged', true);

        // A linha some de verdade — não é soft delete.
        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);

        // E leva tudo junto pelas FKs cascadeOnDelete.
        $this->assertSame(0, DB::table('products')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, DB::table('categories')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, DB::table('users')->where('tenant_id', $tenant->id)->count());

        // Tokens são polimórficos e não têm FK: precisam ser apagados à mão,
        // senão sobrariam como credenciais órfãs.
        $this->assertSame(
            0,
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $owner->id)
                ->count(),
        );

        $this->assertSame($staff->email, PlatformAuditLog::where('action', 'purge')->first()->actor_email);
    }

    /** Depois da purga o slug fica livre, ao contrário da exclusão do lojista. */
    public function test_storefront_deixa_de_resolver_a_loja_purgada(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();

        // Aquece o cache slug -> id, como faria um acesso real antes da purga.
        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])->assertOk();

        $this->deleteJson("/api/platform/stores/{$tenant->slug}", [
            'password' => 'senha-muito-longa',
            'confirmation' => $tenant->slug,
        ])->assertOk();

        $this->getJson('/api/menu', ['X-Tenant' => $tenant->slug])->assertStatus(404);
    }

    public function test_purga_exige_a_senha_do_staff(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();

        $this->deleteJson("/api/platform/stores/{$tenant->slug}", [
            'password' => 'senha-errada',
            'confirmation' => $tenant->slug,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    /** Digitar o slug evita o clique errado numa lista de linhas parecidas. */
    public function test_purga_exige_o_slug_digitado(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();

        $this->deleteJson("/api/platform/stores/{$tenant->slug}", [
            'password' => 'senha-muito-longa',
            'confirmation' => 'outra-coisa',
        ])->assertStatus(422)->assertJsonValidationErrors('confirmation');

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    /** A purga alcança o que o lojista já tinha soft-deletado. */
    public function test_purga_loja_ja_excluida_pelo_lojista(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();
        $tenant->delete();

        $this->deleteJson("/api/platform/stores/{$tenant->slug}", [
            'password' => 'senha-muito-longa',
            'confirmation' => $tenant->slug,
        ])->assertOk();

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }

    public function test_previa_da_purga_conta_o_que_sera_apagado(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();
        $this->owner($tenant);

        app(TenantContext::class)->set($tenant);
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        Product::factory()->count(4)->create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
        ]);
        app(TenantContext::class)->forget();

        $this->getJson("/api/platform/stores/{$tenant->slug}/purge-preview")
            ->assertOk()
            ->assertJsonPath('willDelete.products', 4)
            ->assertJsonPath('willDelete.users', 1);
    }

    /**
     * A trilha sobrevive à purga.
     *
     * É o único registro que resta do que existia: por isso `tenant_id` e
     * `actor_id` não têm foreign key. Uma FK com cascade levaria a auditoria
     * junto, exatamente na operação em que ela é indispensável.
     */
    public function test_auditoria_sobrevive_a_purga_da_loja(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();

        $this->postJson("/api/platform/stores/{$tenant->slug}/suspend")->assertOk();

        $this->deleteJson("/api/platform/stores/{$tenant->slug}", [
            'password' => 'senha-muito-longa',
            'confirmation' => $tenant->slug,
        ])->assertOk();

        $logs = PlatformAuditLog::where('tenant_slug', $tenant->slug)->get();

        $this->assertCount(2, $logs);
        $this->assertEqualsCanonicalizing(['suspend', 'purge'], $logs->pluck('action')->all());
        // Nome e slug ficam desnormalizados porque a linha original sumiu.
        $this->assertSame('Loja Teste', $logs->first()->tenant_name);
    }

    /**
     * Purga que falha no meio não fica registrada como sucesso.
     *
     * A trilha é gravada antes do DELETE por necessidade — depois dele não há
     * mais linha de onde ler nome e contagens. Se a transação abortar, o
     * registro de `purge` já existe e não pode ser reescrito (a tabela é
     * append-only). O desfecho real entra como um evento novo, `purge_failed`,
     * e o erro sobe para o cliente em vez de virar um "excluída" falso.
     */
    public function test_falha_na_purga_e_registrada_e_propagada(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();

        // Faz o DELETE do tenant estourar, sem alterar o resto do fluxo.
        DB::statement("
            CREATE OR REPLACE FUNCTION bloqueia_purga() RETURNS trigger AS \$\$
            BEGIN RAISE EXCEPTION 'bloqueado para o teste'; END;
            \$\$ LANGUAGE plpgsql
        ");
        DB::statement('
            CREATE TRIGGER bloqueia_purga_trg BEFORE DELETE ON tenants
            FOR EACH ROW EXECUTE FUNCTION bloqueia_purga()
        ');

        // Sem isto o handler converte a exceção em 500 mas engole o corpo, e a
        // falha do teste não diria qual erro ocorreu.
        $this->withoutExceptionHandling([\Illuminate\Database\QueryException::class]);

        try {
            $this->deleteJson("/api/platform/stores/{$tenant->slug}", [
                'password' => 'senha-muito-longa',
                'confirmation' => $tenant->slug,
            ])->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS bloqueia_purga_trg ON tenants');
            DB::statement('DROP FUNCTION IF EXISTS bloqueia_purga()');
        }

        // A loja continua lá, e a trilha diz o que realmente aconteceu.
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
        $this->assertDatabaseHas('platform_audit_logs', [
            'tenant_slug' => $tenant->slug,
            'action' => 'purge_failed',
        ]);
    }

    /**
     * A trilha não se reescreve nem se apaga, nem pela própria aplicação.
     *
     * A proteção é o `FORCE ROW LEVEL SECURITY` sem policy de UPDATE/DELETE, e
     * não o REVOKE: em produção o dono das tabelas É o papel da aplicação (o
     * `01-app-role.sql` transfere o schema para ele e as migrations rodam com o
     * mesmo usuário), e o dono não perde privilégio sobre a própria tabela. O
     * FORCE é justamente o que faz as policies valerem para o dono também.
     *
     * O efeito é silencioso por desenho do Postgres: linha que nenhuma policy
     * alcança é invisível à operação, então o DELETE afeta zero linhas em vez
     * de lançar erro. Este teste fixa o que importa — a linha continua lá.
     */
    public function test_auditoria_e_imutavel_no_banco(): void
    {
        $this->actingAsStaff();
        $tenant = $this->makeTenant();

        $this->postJson("/api/platform/stores/{$tenant->slug}/suspend")->assertOk();

        $affected = DB::table('platform_audit_logs')->where('tenant_slug', $tenant->slug)->delete();

        $this->assertSame(0, $affected);
        $this->assertDatabaseHas('platform_audit_logs', [
            'tenant_slug' => $tenant->slug,
            'action' => 'suspend',
        ]);

        $updated = DB::table('platform_audit_logs')
            ->where('tenant_slug', $tenant->slug)
            ->update(['action' => 'forjado']);

        $this->assertSame(0, $updated);
        $this->assertDatabaseHas('platform_audit_logs', [
            'tenant_slug' => $tenant->slug,
            'action' => 'suspend',
        ]);
    }
}
