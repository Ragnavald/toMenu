<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSettings;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Duas lojas com identidades visuais distintas — o objetivo é demonstrar que o
 * mesmo build do frontend produz aparências completamente diferentes, sem
 * rebuild, apenas trocando os tokens vindos da API.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $plan = Plan::firstOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro',
                'price_cents' => 9900,
                'max_products' => 500,
                'allows_online_payment' => true,
            ]
        );

        $this->createPizzaria($plan);
        $this->createSushi($plan);
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
                'font' => 'playfair',
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
     * Horário semanal uniforme, com dias de folga opcionais.
     *
     * @param  string[]  $closedOn
     * @return array<string,array<string,mixed>>
     */
    private function hours(string $open, string $close, array $closedOn = []): array
    {
        return collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
            ->mapWithKeys(fn (string $day) => [
                $day => [
                    'open' => $open,
                    'close' => $close,
                    'enabled' => ! in_array($day, $closedOn, true),
                ],
            ])
            ->all();
    }

    /**
     * O contexto é setado explicitamente porque o global scope preenche
     * tenant_id a partir dele — sem isso os registros nasceriam órfãos.
     */
    private function seedMenu(Tenant $tenant, array $menu, bool $withSizes = false): void
    {
        app(TenantContext::class)->runFor($tenant, function () use ($menu) {
            $position = 0;

            foreach ($menu as $categoryName => $products) {
                $category = Category::create([
                    'name' => $categoryName,
                    'slug' => Str::slug($categoryName),
                    'position' => $position++,
                ]);

                foreach ($products as $i => [$name, $description, $price]) {
                    Product::create([
                        'category_id' => $category->id,
                        'name' => $name,
                        'slug' => Str::slug($name),
                        'description' => $description,
                        'price_cents' => $price,
                        'position' => $i,
                    ]);
                }
            }
        });
    }

    /**
     * Pizzas no modelo composto: o produto é o formato, o sabor é escolhido.
     *
     * Vale a pena contrastar com o modelo anterior, em que cada sabor era um
     * produto e o tamanho era um modifier. Aquilo quebrava no meio a meio — não
     * havia como pedir metade Margherita, metade Diavola sem criar um produto
     * "Margherita/Diavola" para cada par possível, o que explode em combinações.
     *
     * Aqui existem três produtos de formato e uma categoria de sabores marcada
     * como is_option_only. "Meio a Meio" é o mesmo grupo de sabores com
     * max_select 2 e regra 'highest'.
     */
    private function seedPizzas(Tenant $tenant): void
    {
        app(TenantContext::class)->runFor($tenant, function () use ($tenant) {
            $flavourCategory = Category::create([
                'name' => 'Sabores de Pizza',
                'slug' => 'sabores-de-pizza',
                'position' => 10,
                // Não aparece como seção do cardápio: só abastece os grupos.
                'is_option_only' => true,
            ]);

            $flavours = [
                ['Margherita', 'Molho de tomate San Marzano, mozzarella di bufala e manjericão', 5900],
                ['Diavola', 'Molho de tomate, mozzarella e salame picante', 6700],
                ['Quattro Formaggi', 'Mozzarella, gorgonzola, parmesão e provolone', 6900],
                ['Prosciutto e Funghi', 'Presunto de Parma e cogumelos frescos', 7200],
            ];

            $flavourProducts = [];

            foreach ($flavours as $i => [$name, $description, $price]) {
                $flavourProducts[] = Product::create([
                    'category_id' => $flavourCategory->id,
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => $description,
                    'price_cents' => $price,
                    'position' => $i,
                ]);
            }

            /*
             * Um grupo de sabores por formato, e não um grupo compartilhado.
             *
             * A tentação é criar só dois grupos ("1 sabor" e "2 sabores") e
             * reusá-los. Não funciona: o preço do sabor por formato mora na
             * pivot do grupo, então Média e Grande compartilhando um grupo
             * compartilhariam também o preço — a Grande sairia pelo valor da
             * Média, ou o inverso, dependendo de quem gravou por último.
             */
            $makeFlavourGroup = function (string $label, int $min, int $max, int $surcharge) use ($flavourCategory, $flavourProducts, $tenant) {
                $group = ModifierGroup::create([
                    'name' => $label,
                    'min_select' => $min,
                    'max_select' => $max,
                    'is_required' => true,
                    'source' => ModifierGroup::SOURCE_CATEGORY,
                    'source_category_id' => $flavourCategory->id,
                    'pricing_rule' => 'highest',
                ]);

                foreach ($flavourProducts as $i => $flavour) {
                    $group->optionProducts()->attach($flavour->id, [
                        'tenant_id' => $tenant->id,
                        // Sem acréscimo a coluna fica nula e vale o preço do
                        // próprio sabor — inclusive a promoção dele.
                        'price_cents' => $surcharge > 0 ? $flavour->price_cents + $surcharge : null,
                        'position' => $i,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $group;
            };

            // Borda e adicionais continuam sendo grupo de lista com soma: são
            // acréscimos de verdade, e dois deles devem somar.
            $crust = ModifierGroup::create([
                'name' => 'Borda recheada',
                'min_select' => 0,
                'max_select' => 1,
                'is_required' => false,
            ]);

            foreach ([['Sem borda', 0], ['Catupiry', 900], ['Cheddar', 900], ['Chocolate', 1200]] as $i => [$name, $delta]) {
                Modifier::create([
                    'modifier_group_id' => $crust->id,
                    'name' => $name,
                    'price_delta_cents' => $delta,
                    'position' => $i,
                ]);
            }

            $extras = ModifierGroup::create([
                'name' => 'Adicionais',
                'min_select' => 0,
                'max_select' => 3,
                'is_required' => false,
            ]);

            foreach ([['Bacon extra', 700], ['Mozzarella extra', 600], ['Azeitona', 300]] as $i => [$name, $delta]) {
                Modifier::create([
                    'modifier_group_id' => $extras->id,
                    'name' => $name,
                    'price_delta_cents' => $delta,
                    'position' => $i,
                ]);
            }

            $pizzas = Category::create([
                'name' => 'Pizzas',
                'slug' => 'pizzas',
                'position' => 1,
            ]);

            /*
             * O preço do formato é zero: quem define o valor é o sabor
             * escolhido, via regra 'highest'. O tamanho entra como override de
             * preço por sabor — uma família custa mais que uma média do mesmo
             * sabor, e é isso que a coluna price_cents da pivot resolve.
             */
            $formats = [
                ['Pizza Média (30cm)', '8 fatias · 1 sabor', 1, 0],
                ['Pizza Grande (35cm)', '12 fatias · 1 sabor', 1, 1200],
                ['Pizza Meio a Meio (35cm)', '12 fatias · 2 sabores', 2, 1200],
            ];

            foreach ($formats as $i => [$name, $description, $flavourCount, $surcharge]) {
                $product = Product::create([
                    'category_id' => $pizzas->id,
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => $description,
                    'price_cents' => 0,
                    'position' => $i,
                ]);

                $label = $flavourCount === 1 ? 'Escolha o sabor' : "Escolha {$flavourCount} sabores";

                $flavourGroup = $makeFlavourGroup($label, $flavourCount, $flavourCount, $surcharge);

                $product->modifierGroups()->attach([
                    $flavourGroup->id => ['position' => 0],
                    $crust->id => ['position' => 1],
                    $extras->id => ['position' => 2],
                ]);
            }
        });
    }
}
