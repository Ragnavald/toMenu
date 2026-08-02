<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSettings;
use App\Models\User;
use Database\Seeders\Concerns\BuildsDemoStores;
use Illuminate\Database\Seeder;

/**
 * Lojas com identidades visuais distintas — o objetivo é demonstrar que o
 * mesmo build do frontend produz aparências completamente diferentes, sem
 * rebuild, apenas trocando os tokens vindos da API.
 *
 * Seeder de DESENVOLVIMENTO: assume banco vazio (é chamada pelo `make fresh`,
 * logo depois do `migrate:fresh`) e cria usuários com senha conhecida. As duas
 * lojas que a landing linka em produção são responsabilidade da
 * ExampleStoresSeeder, que é idempotente e não cria login nenhum.
 */
class DemoSeeder extends Seeder
{
    use BuildsDemoStores;

    public function run(): void
    {
        // Os planos vêm do PlanSeeder e não de um firstOrCreate local: a
        // definição inline que existia aqui não tinha `allows_orders`, então a
        // loja de demonstração do plano vitrine nasceria aceitando pedidos —
        // justamente o que ela precisa NÃO fazer para servir de exemplo.
        (new PlanSeeder)->run();

        $pro = Plan::where('slug', Plan::PRO)->firstOrFail();
        $menuOnly = Plan::where('slug', Plan::MENU_ONLY)->firstOrFail();

        $this->createPizzaria($pro);
        $this->createSushi($pro);
        $this->createCafeteria($menuOnly);
    }

    private function createPizzaria(Plan $plan): void
    {
        $tenant = Tenant::create([
            'name' => 'Forno di Napoli',
            'slug' => 'forno-di-napoli',
            'plan_id' => $plan->id,
            'status' => 'active',
            // Lojas de demonstração já nascem publicadas: sem isto o painel
            // abriria no wizard em vez da tela de pedidos.
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
                // Só uma das lojas de demonstração atende no salão: assim o
                // seed cobre os dois lados do seletor de modalidade.
                'accepts_dine_in' => true,
            ],
            'payment_methods' => ['cash', 'card_on_delivery', 'pix_on_delivery'],
            'business_hours' => $this->hours('18:00', '23:30', closedOn: ['mon']),
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Marco Rossi',
            'email' => 'admin@fornodinapoli.test',
            'password' => 'password',
            'role' => 'owner',
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

    private function createSushi(Plan $plan): void
    {
        $tenant = Tenant::create([
            'name' => 'Aoi Sushi',
            'slug' => 'aoi-sushi',
            'plan_id' => $plan->id,
            'status' => 'active',
            'onboarding_step' => 5,
            'onboarding_completed_at' => now(),
        ]);

        TenantSettings::create([
            'tenant_id' => $tenant->id,
            'theme' => [
                'brand' => '23 79 122',      // índigo profundo
                'brandSoft' => '226 239 248',
                'surface' => '250 252 254',
                'ink' => '17 28 38',
                'font' => 'sora',
                'radius' => '8px',
                'layout' => 'grid',
            ],
            'phone' => '1144445555',
            'whatsapp' => '11999990002',
            'address' => 'Alameda Santos, 800 — Jardins, São Paulo',
            'description' => 'Peixe fresco selecionado diariamente pelo chef.',
            'delivery_config' => [
                'fee_cents' => 900,
                'min_order_cents' => 5000,
                'eta_minutes' => 55,
                'free_above_cents' => null,
                'radius_km' => 5,
                'accepts_pickup' => true,
                'accepts_delivery' => true,
            ],
            'payment_methods' => ['cash', 'card_on_delivery'],
            'business_hours' => $this->hours('19:00', '01:00'),
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Yuki Tanaka',
            'email' => 'admin@aoisushi.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        $this->seedMenu($tenant, [
            'Entradas' => [
                ['Edamame', 'Vagem de soja com flor de sal', 2200],
                ['Gyoza', 'Seis unidades, recheio de porco e cebolinha', 3200],
            ],
            'Sushi' => [
                ['Combinado Aoi', '16 peças selecionadas pelo chef', 8900],
                ['Sashimi Salmão', '10 fatias de salmão fresco', 6400],
                ['Uramaki Philadelphia', '8 peças com cream cheese e salmão', 4800],
            ],
            'Bebidas' => [
                ['Chá Verde Gelado', 'Sencha artesanal, 500ml', 1400],
                ['Sake Junmai', 'Dose de 90ml', 3600],
            ],
        ], withSizes: false);
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
            'name' => 'Grão & Folha',
            'slug' => 'grao-e-folha',
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

        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Marina Alves',
            'email' => 'admin@graoefolha.test',
            'password' => 'password',
            'role' => 'owner',
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
