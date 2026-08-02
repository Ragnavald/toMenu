<?php

namespace Database\Seeders\Concerns;

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Montagem do cardápio das lojas de exemplo.
 *
 * Vive num trait porque duas seeders com públicos opostos precisam do mesmo
 * cardápio: a DemoSeeder, que recria o banco de desenvolvimento do zero, e a
 * ExampleStoresSeeder, que roda em produção a cada deploy. Duplicar o menu
 * faria a vitrine que o visitante vê divergir da que o desenvolvedor testa —
 * e a divergência só apareceria em produção.
 */
trait BuildsDemoStores
{
    /**
     * Horário semanal uniforme, com dias de folga opcionais.
     *
     * @param  string[]  $closedOn
     * @return array<string,array<string,mixed>>
     */
    protected function hours(string $open, string $close, array $closedOn = []): array
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
    protected function seedMenu(Tenant $tenant, array $menu, bool $withSizes = false): void
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
    protected function seedPizzas(Tenant $tenant): void
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
