<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSettings;
use App\Models\User;
use Database\Seeders\Concerns\BuildsDemoStores;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * As duas lojas de exemplo que a landing page linka, em PRODUÇÃO.
 *
 * Existe separada da DemoSeeder porque as duas têm públicos opostos. A
 * DemoSeeder recria o banco de desenvolvimento do zero — cria usuários com
 * senha 'password', assume tabelas vazias e serve para o desenvolvedor ter o
 * que olhar. Esta aqui roda contra o banco de produção, ao lado de lojas de
 * clientes pagantes, e por isso obedece a três regras que a outra não precisa:
 *
 *   1. É idempotente. Roda a cada deploy (via `deploy-migrate`), então precisa
 *      atravessar sem erro um banco que já tem as duas lojas. `Tenant::create`
 *      estouraria no slug único na segunda execução.
 *   2. Nunca reescreve uma loja existente. Se `forno-di-napoli` já está lá, o
 *      seeder sai sem tocar em nada — nem no cardápio, nem no tema. Um deploy
 *      não pode desfazer o que alguém ajustou pelo painel.
 *   3. Nunca embute senha. As lojas têm um owner para que a equipe ajuste o
 *      cardápio pelo painel, mas a senha vem de `EXAMPLE_STORE_PASSWORD` no
 *      ambiente. Sem a variável o seeder cria a loja e PULA o usuário: uma
 *      senha fixa no repositório seria credencial pública de produção, e um
 *      default previsível é pior que login nenhum.
 *
 * As lojas ficam com `status = 'active'` e onboarding concluído para não caírem
 * no wizard, e são as MESMAS duas que `apps/storefront/app/page.tsx` referencia
 * por slug. Mudar um slug aqui quebra o link da landing.
 */
class ExampleStoresSeeder extends Seeder
{
    use BuildsDemoStores;

    /**
     * Slugs das lojas de exemplo.
     *
     * O storefront esconde essas lojas de qualquer listagem pública que venha a
     * existir; aqui elas servem só para o seeder saber o que já criou.
     */
    public const SLUGS = ['to-menu-loja', 'to-menu-cardapio'];

    /**
     * E-mail do owner de cada loja de exemplo.
     *
     * Fixos e derivados do slug: são contas de equipe, não de cliente, e o
     * seeder precisa reconhecê-las para não criar uma segunda a cada deploy.
     */
    private const OWNER_EMAILS = [
        'to-menu-loja' => 'loja@to-menu.com',
        'to-menu-cardapio' => 'cardapio@to-menu.com',
    ];

    public function run(): void
    {
        // Os planos precisam existir antes: é o plano que decide se a loja
        // aceita pedidos, e é justamente esse contraste que os dois exemplos
        // existem para mostrar.
        (new PlanSeeder)->run();

        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $menuOnly = Plan::where('slug', Plan::MENU_ONLY)->firstOrFail();

        $this->createIfMissing('to-menu-loja', fn () => $this->createPizzaria($pro));
        $this->createIfMissing('to-menu-cardapio', fn () => $this->createCafeteria($menuOnly));

        /*
         * Os owners são garantidos FORA do createIfMissing.
         *
         * Aquele guard sai cedo quando a loja já existe, e é o caso normal a
         * partir do segundo deploy. Criar o usuário lá dentro faria a conta
         * nascer só junto com a loja: quem já tem as lojas em produção nunca
         * ganharia o login, que é justamente o motivo desta mudança existir.
         */
        $this->ensureOwner('to-menu-loja', 'Equipe ToMenu');
        $this->ensureOwner('to-menu-cardapio', 'Equipe ToMenu');
    }

    /**
     * Garante o owner da loja de exemplo, sem nunca sobrescrever senha.
     *
     * A senha só é gravada na CRIAÇÃO. Um `updateOrCreate` com a senha no
     * payload desfaria, a cada deploy, qualquer troca feita pelo painel — e
     * pior, silenciosamente: o login voltaria a aceitar a senha antiga do
     * ambiente e ninguém perceberia que a nova deixou de valer.
     */
    private function ensureOwner(string $slug, string $name): void
    {
        $password = env('EXAMPLE_STORE_PASSWORD');

        if (! $password) {
            $this->command?->warn(
                "EXAMPLE_STORE_PASSWORD ausente; loja '{$slug}' fica sem usuário de acesso."
            );

            return;
        }

        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            return;
        }

        $email = self::OWNER_EMAILS[$slug];

        // withoutGlobalScopes: o seeder roda sem tenant no contexto, e sem isto
        // a busca por (tenant_id, email) dependeria de um escopo que não existe
        // aqui — a conta seria recriada a cada deploy e estouraria no unique.
        $exists = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->exists();

        if ($exists) {
            $this->command?->info("Usuário de '{$slug}' já existe; senha mantida.");

            return;
        }

        /*
         * `forceFill` para o email_verified_at, e não `create`.
         *
         * A coluna está FORA do $fillable do User de propósito — é o que impede
         * um payload de request marcar a própria conta como confirmada. Passada
         * ao `create`, ela é descartada em silêncio e o usuário nasce sem
         * confirmação: o LoginController barra com 403 `email_unverified` e a
         * conta fica inutilizável, inclusive para a impersonação, que esbarra na
         * mesma trava. Mesmo caminho que o EmailVerifier usa ao confirmar.
         *
         * Confirmar aqui é correto: ninguém vai clicar no link de um e-mail
         * @to-menu.com que não existe como caixa de entrada.
         */
        User::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'owner',
        ])->forceFill(['email_verified_at' => now()])->save();

        $this->command?->info("Usuário '{$email}' criado para a loja '{$slug}'.");
    }

    /**
     * Cria a loja só se o slug ainda não existe, dentro de uma transação.
     *
     * A transação é o que impede a meia-loja: sem ela, uma falha no meio do
     * cardápio deixaria em produção um tenant ativo, linkado pela landing, com
     * duas categorias das quatro. Como o slug já estaria gravado, a execução
     * seguinte pularia a loja e o defeito ficaria permanente.
     */
    private function createIfMissing(string $slug, callable $build): void
    {
        if (Tenant::where('slug', $slug)->exists()) {
            $this->command?->info("Loja de exemplo '{$slug}' já existe; mantida como está.");

            return;
        }

        DB::transaction($build);

        $this->command?->info("Loja de exemplo '{$slug}' criada.");
    }

    private function createPizzaria(Plan $plan): void
    {
        $tenant = Tenant::create([
            'name' => 'ToMenu Loja',
            'slug' => 'to-menu-loja',
            'plan_id' => $plan->id,
            'status' => 'active',
            'onboarding_step' => 5,
            'onboarding_completed_at' => now(),
        ]);

        TenantSettings::create([
            'tenant_id' => $tenant->id,
            'theme' => [
                'brand' => '198 40 40',      // vermelho tomate
                'brandSoft' => '254 235 235',
                'surface' => '255 251 247',
                'ink' => '38 26 22',
                'font' => 'sora',
                'radius' => '14px',
                'layout' => 'classic',
            ],
            'phone' => '1133334444',
            'whatsapp' => '11999990001',
            'address' => 'Rua Augusta, 1200 — Consolação, São Paulo',
            'description' => 'Massa fermentada por 48h e forno a lenha.',
            'delivery_config' => [
                'fee_cents' => 700,
                'min_order_cents' => 3000,
                'eta_minutes' => 45,
                'free_above_cents' => 12000,
                'radius_km' => 8,
                'accepts_pickup' => true,
                'accepts_delivery' => true,
                'accepts_dine_in' => true,
            ],
            'payment_methods' => ['cash', 'card_on_delivery', 'pix_on_delivery'],
            'business_hours' => $this->hours('18:00', '23:30', closedOn: ['mon']),
        ]);

        $this->seedMenu($tenant, [
            'Entradas' => [
                ['Bruschetta al Pomodoro', 'Pão italiano, tomate, manjericão e azeite', 2400],
                ['Burrata Fresca', 'Burrata cremosa com rúcula e tomate seco', 4200],
            ],
            'Sobremesas' => [
                ['Tiramisù', 'Receita tradicional com mascarpone e café', 2800],
                ['Panna Cotta', 'Com calda de frutas vermelhas', 2400],
            ],
        ], withSizes: true);

        $this->seedPizzas($tenant);
    }

    /**
     * Loja de demonstração do plano somente-cardápio.
     *
     * Uma cafeteria de balcão é o caso que o plano descreve: o cliente consulta
     * o cardápio pelo QR code na mesa e pede no caixa. Não há carrinho, nem
     * checkout, nem entrega — e é isso que a landing usa para contrastar os
     * dois planos lado a lado.
     *
     * `delivery_config` e `payment_methods` ficam de fora de propósito. Se a
     * loja carregasse essa configuração, o exemplo esconderia o único detalhe
     * que ele existe para mostrar: quem decide é a capacidade do plano, não o
     * preenchimento das configurações.
     */
    private function createCafeteria(Plan $plan): void
    {
        $tenant = Tenant::create([
            'name' => 'ToMenu Cardápio',
            'slug' => 'to-menu-cardapio',
            'plan_id' => $plan->id,
            'status' => 'active',
            'onboarding_step' => 5,
            'onboarding_completed_at' => now(),
        ]);

        TenantSettings::create([
            'tenant_id' => $tenant->id,
            'theme' => [
                'brand' => '87 106 66',      // verde oliva
                'brandSoft' => '236 241 229',
                'surface' => '252 251 247',
                'ink' => '31 33 27',
                'font' => 'sora',
                'radius' => '18px',
                'layout' => 'grid',
            ],
            'phone' => '1155556666',
            'whatsapp' => '11999990003',
            'address' => 'Rua Fradique Coutinho, 320 — Pinheiros, São Paulo',
            'description' => 'Torra própria e pães do dia. Peça no balcão.',
            'business_hours' => $this->hours('07:00', '19:00', closedOn: ['sun']),
        ]);

        $this->seedMenu($tenant, [
            'Café' => [
                ['Espresso', 'Blend da casa, torra média', 700],
                ['Coado V60', 'Grão único, método filtrado', 1200],
                ['Cappuccino', 'Espresso, leite vaporizado e cacau', 1400],
            ],
            'Padaria' => [
                ['Croissant', 'Folhado de manteiga, assado pela manhã', 1100],
                ['Pão na Chapa', 'Pão de fermentação natural com manteiga', 900],
                ['Bolo de Fubá', 'Fatia com erva-doce', 800],
            ],
            'Salgados' => [
                ['Quiche de Alho-poró', 'Massa amanteigada, fatia individual', 1600],
                ['Tostex de Queijo', 'Pão de forma artesanal e muçarela', 1300],
            ],
        ]);
    }
}
