<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Importa um modelo de cardápio inteiro — categorias, produtos e grupos.
 *
 * O `categories/batch` já existente cria só seções vazias. Aqui o modelo chega
 * com itens vendáveis e, no caso da pizzaria, com o grupo composto de sabores
 * já vinculado aos formatos de dois sabores — e com os sabores já ofertados
 * como opções dentro dele, que é o que torna o produto pedível de fato (ver
 * `linkComposedOptions`).
 *
 * Tudo numa transação só. O import parcial é o risco concreto: uma falha entre
 * criar "Pizza Grande 2 Sabores" e vincular o grupo de sabores deixaria no
 * cardápio um produto que o cliente pode pedir sem escolher sabor nenhum — e o
 * lojista teria que descobrir isso sozinho, item por item.
 *
 * O payload referencia os próprios itens por `ref` (string do template) em vez
 * de id: o front não conhece os ids antes da escrita, e resolver isso aqui
 * evita o ida-e-volta de uma chamada por entidade.
 */
class MenuImportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $result = DB::transaction(function () use ($data) {
            $categories = $this->createCategories($data['categories'] ?? []);
            $groups = $this->createGroups($data['groups'] ?? [], $categories);
            $products = $this->createProducts($data['products'] ?? [], $categories, $groups);

            // Depois dos produtos, necessariamente: as opções de um grupo
            // composto SÃO os produtos da categoria de origem, que não existem
            // enquanto o grupo é criado.
            $this->linkComposedOptions($data['groups'] ?? [], $categories, $groups);

            return [
                'categories' => array_values($categories),
                'products' => array_values($products),
                'groups' => array_values($groups),
            ];
        });

        return response()->json([
            'data' => [
                'categories_created' => count($result['categories']),
                'products_created' => count($result['products']),
                'groups_created' => count($result['groups']),
            ],
        ], 201);
    }

    /**
     * @return array<string,Category> indexado pelo ref do template
     */
    private function createCategories(array $items): array
    {
        // Uma leitura só do fim da lista; incrementar em memória evita um
        // MAX(position) por item importado.
        $position = (int) Category::max('position');
        $created = [];

        foreach ($items as $item) {
            $created[$item['ref']] = Category::create([
                'name' => $item['name'],
                'slug' => $this->uniqueSlug(Category::class, $item['name'], 'categoria'),
                'is_active' => true,
                'is_option_only' => $item['is_option_only'] ?? false,
                'position' => ++$position,
            ]);
        }

        return $created;
    }

    /**
     * @param  array<string,Category>  $categories
     * @return array<string,ModifierGroup>
     */
    private function createGroups(array $items, array $categories): array
    {
        $created = [];

        foreach ($items as $item) {
            $sourceRef = $item['source_category_ref'] ?? null;

            $created[$item['ref']] = ModifierGroup::create([
                'name' => $item['name'],
                'min_select' => $item['min_select'],
                'max_select' => $item['max_select'],
                'is_required' => $item['is_required'] ?? false,
                'source' => $item['source'],
                // Grupo composto sem categoria de origem não teria de onde tirar
                // opção nenhuma; a validação já garante o ref, aqui só resolvemos.
                'source_category_id' => $sourceRef ? $categories[$sourceRef]->id : null,
                'pricing_rule' => $item['pricing_rule'],
            ]);
        }

        return $created;
    }

    /**
     * @param  array<string,Category>  $categories
     * @param  array<string,ModifierGroup>  $groups
     * @return array<string,Product>
     */
    private function createProducts(array $items, array $categories, array $groups): array
    {
        // A posição é por categoria, então o contador também.
        $positions = [];
        $created = [];

        foreach ($items as $item) {
            $category = $categories[$item['category_ref']];

            if (! isset($positions[$category->id])) {
                $positions[$category->id] = (int) Product::where('category_id', $category->id)
                    ->max('position');
            }

            $product = Product::create([
                'category_id' => $category->id,
                'name' => $item['name'],
                'slug' => $this->uniqueSlug(Product::class, $item['name'], 'produto'),
                'description' => $item['description'] ?? null,
                'price_cents' => $item['price_cents'],
                'is_available' => true,
                'position' => ++$positions[$category->id],
            ]);

            $created[$item['ref']] = $product;

            $refs = $item['group_refs'] ?? [];

            if ($refs !== []) {
                $product->modifierGroups()->attach(
                    collect($refs)
                        ->values()
                        ->mapWithKeys(fn (string $ref, int $i) => [
                            $groups[$ref]->id => ['position' => $i + 1],
                        ])
                        ->all(),
                );
            }
        }

        return $created;
    }

    /**
     * Oferta os produtos da categoria de origem como opções do grupo composto.
     *
     * `source_category_id` sozinho não oferta nada: quem o cardápio e o pedido
     * leem é a pivot `modifier_group_product`, através do ModifierOptionResolver.
     * Sem esta etapa o grupo "Escolha 2 sabores" nasce vazio e, com min_select
     * 2, o OrderService barra todo pedido do produto por opção obrigatória que
     * o cliente não tem como escolher — o formato de dois sabores ficaria no
     * cardápio, visível e invendável.
     *
     * `price_cents` fica nulo de propósito: vale o preço do próprio sabor,
     * inclusive a promoção dele. O modelo não tem como saber o acréscimo que
     * cada loja cobra, e o lojista ajusta isso na tela do grupo.
     *
     * @param  array<string,Category>  $categories
     * @param  array<string,ModifierGroup>  $groups
     */
    private function linkComposedOptions(
        array $items,
        array $categories,
        array $groups,
    ): void {
        foreach ($items as $item) {
            $sourceRef = $item['source_category_ref'] ?? null;

            if ($item['source'] !== ModifierGroup::SOURCE_CATEGORY || ! $sourceRef) {
                continue;
            }

            $group = $groups[$item['ref']];
            $categoryId = $categories[$sourceRef]->id;

            // Ordenado por `position`, que createProducts gravou na sequência
            // do payload: os sabores são ofertados na ordem do modelo. A busca
            // é por categoria, e não pelos refs do payload, porque a origem do
            // grupo é a categoria inteira — inclusive o que o lojista cadastrar
            // nela depois.
            $options = Product::where('category_id', $categoryId)
                ->orderBy('position')
                ->pluck('id')
                ->values()
                ->mapWithKeys(fn (int $id, int $i) => [
                    $id => [
                        'tenant_id' => $group->tenant_id,
                        'price_cents' => null,
                        'position' => $i,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                ])
                ->all();

            if ($options !== []) {
                $group->optionProducts()->attach($options);
            }
        }
    }

    /**
     * Slug livre dentro do tenant.
     *
     * O global scope de BelongsToTenant restringe o `exists`, então importar o
     * mesmo modelo em duas lojas não colide — e reimportar na mesma loja gera
     * "pizza-media-2" em vez de estourar o unique.
     */
    private function uniqueSlug(string $model, string $name, string $fallback): string
    {
        $base = Str::slug($name) ?: $fallback;
        $slug = $base;
        $suffix = 2;

        while ($model::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function validated(Request $request): array
    {
        $refs = fn (string $key) => collect($request->input($key, []))
            ->pluck('ref')
            ->filter()
            ->all();

        $categoryRefs = $refs('categories');
        $groupRefs = $refs('groups');

        return $request->validate([
            'categories' => ['present', 'array'],
            'categories.*.ref' => ['required', 'string', 'distinct', 'max:60'],
            'categories.*.name' => ['required', 'string', 'max:80'],
            'categories.*.is_option_only' => ['boolean'],

            'groups' => ['present', 'array'],
            'groups.*.ref' => ['required', 'string', 'distinct', 'max:60'],
            'groups.*.name' => ['required', 'string', 'max:80'],
            'groups.*.min_select' => ['required', 'integer', 'min:0'],
            'groups.*.max_select' => ['required', 'integer', 'min:1', 'gte:groups.*.min_select'],
            'groups.*.is_required' => ['boolean'],
            'groups.*.source' => ['required', Rule::in(ModifierGroup::SOURCES)],
            // Obrigatório justamente quando o grupo é composto: sem ele o grupo
            // nasceria sem nenhuma opção para oferecer.
            'groups.*.source_category_ref' => [
                'nullable',
                'required_if:groups.*.source,'.ModifierGroup::SOURCE_CATEGORY,
                Rule::in($categoryRefs),
            ],
            'groups.*.pricing_rule' => ['required', Rule::in(ModifierGroup::PRICING_RULES)],

            'products' => ['present', 'array'],
            'products.*.ref' => ['required', 'string', 'distinct', 'max:60'],
            // Os refs precisam existir no próprio payload: é o que garante que
            // nenhum produto aponte para categoria/grupo que não será criado.
            'products.*.category_ref' => ['required', 'string', Rule::in($categoryRefs)],
            'products.*.name' => ['required', 'string', 'max:120'],
            'products.*.description' => ['nullable', 'string', 'max:500'],
            'products.*.price_cents' => ['required', 'integer', 'min:0'],
            'products.*.group_refs' => ['array'],
            'products.*.group_refs.*' => ['string', Rule::in($groupRefs)],
        ]);
    }
}
