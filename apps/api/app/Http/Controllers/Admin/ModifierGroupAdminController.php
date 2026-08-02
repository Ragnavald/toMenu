<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Grupos de opções — de lista (borda, adicional) e compostos (sabores).
 *
 * Como no resto do painel, nenhum método filtra por tenant à mão: o global
 * scope de BelongsToTenant restringe toda query, e o route model binding de
 * {group} devolve 404 para id de outra loja.
 */
class ModifierGroupAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $groups = ModifierGroup::query()
            ->with([
                'modifiers',
                'optionProducts:id,name,price_cents',
                // Só os ids: o painel do produto precisa saber quais grupos já
                // estão marcados, e carregar o produto inteiro aqui inflaria a
                // resposta sem uso.
                'products:id',
            ])
            ->withCount('products')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $groups]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $group = DB::transaction(function () use ($data) {
            $group = ModifierGroup::create($data);
            $this->syncOptions($group, $data);

            return $group;
        });

        return response()->json(
            $group->load(['modifiers', 'optionProducts:id,name,price_cents']),
            201,
        );
    }

    public function show(ModifierGroup $group): JsonResponse
    {
        return response()->json(
            $group->load(['modifiers', 'optionProducts:id,name,price_cents']),
        );
    }

    public function update(Request $request, ModifierGroup $group): JsonResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($group, $data) {
            // Update pela query, não por save(): sob RLS uma escrita recusada
            // ainda faria save() retornar true e o painel exibiria "salvo".
            abort_if(
                $group->newQueryWithoutScopes()
                    ->whereKey($group->getKey())
                    ->update([
                        'name' => $data['name'],
                        'min_select' => $data['min_select'],
                        'max_select' => $data['max_select'],
                        'is_required' => $data['is_required'] ?? false,
                        'source' => $data['source'],
                        'source_category_id' => $data['source_category_id'] ?? null,
                        'pricing_rule' => $data['pricing_rule'],
                        'updated_at' => now(),
                    ]) === 0,
                404,
            );

            $this->syncOptions($group->refresh(), $data);

            // syncOptions mexe na pivot, que não dispara evento de model.
            $this->invalidateMenu($group);
        });

        return response()->json(
            $group->fresh()->load(['modifiers', 'optionProducts:id,name,price_cents']),
        );
    }

    public function destroy(ModifierGroup $group): JsonResponse
    {
        // Grupo em uso não some levando junto a configuração dos produtos: o
        // lojista precisa desvinculá-lo antes e perceber o que está mudando.
        abort_if(
            $group->products()->exists(),
            422,
            'Este grupo está vinculado a produtos. Desvincule-o antes de excluir.',
        );

        abort_if(
            $group->newQueryWithoutScopes()
                ->whereKey($group->getKey())
                ->delete() === 0,
            404,
        );

        return response()->json(status: 204);
    }

    /**
     * Vincula ou desvincula o grupo de um produto.
     *
     * Fica aqui, e não no ProductAdminController, porque a operação é sobre a
     * composição do grupo — a mesma "Borda recheada" serve os três tamanhos de
     * pizza, e é assim que ela é editada uma vez só.
     */
    public function attach(Request $request, ModifierGroup $group): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['present', 'array'],
            'product_ids.*' => [
                'integer',
                Rule::exists('products', 'id')->where(
                    'tenant_id',
                    app(TenantContext::class)->id(),
                ),
            ],
        ]);

        $group->products()->sync(
            collect($data['product_ids'])
                ->values()
                ->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i]])
                ->all(),
        );

        $this->invalidateMenu($group);

        return response()->json(['message' => 'Vínculos atualizados.']);
    }

    /**
     * Invalida o cardápio depois de mexer nas tabelas pivot.
     *
     * O observer InvalidatesMenuCache cobre save e delete dos models, mas
     * `sync()` escreve direto na pivot e não dispara evento nenhum. Sem este
     * empurrão, trocar o preço de um sabor ou desvincular um grupo não mudaria
     * nada no site até o TTL expirar — exatamente o tipo de "salvei e não
     * aconteceu nada" que o observer existe para evitar.
     */
    private function invalidateMenu(ModifierGroup $group): void
    {
        $group->touch();
    }

    /**
     * Grava as opções conforme a origem do grupo.
     *
     * Os dois lados são exclusivos: um grupo de lista não guarda produtos-opção
     * e um composto não guarda modifiers próprios. Trocar a origem limpa o lado
     * que deixou de valer, senão restariam opções órfãs que ninguém vê no
     * painel mas que voltariam a aparecer se a origem fosse trocada de volta.
     */
    private function syncOptions(ModifierGroup $group, array $data): void
    {
        if ($group->isComposed()) {
            $group->modifiers()->delete();

            $group->optionProducts()->sync(
                collect($data['options'] ?? [])
                    ->values()
                    ->mapWithKeys(fn (array $option, int $i) => [
                        $option['product_id'] => [
                            'tenant_id' => $group->tenant_id,
                            // Nulo faz valer o preço do próprio sabor,
                            // inclusive a promoção dele.
                            'price_cents' => $option['price_cents'] ?? null,
                            'position' => $i,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ])
                    ->all(),
            );

            return;
        }

        $group->optionProducts()->detach();

        // Recriar em vez de casar por id mantém o método simples e a ordem
        // exatamente igual à enviada; o volume aqui é de dezenas de linhas.
        $group->modifiers()->delete();

        foreach ($data['modifiers'] ?? [] as $i => $modifier) {
            Modifier::create([
                'modifier_group_id' => $group->id,
                'name' => $modifier['name'],
                'price_delta_cents' => $modifier['price_delta_cents'] ?? 0,
                'is_available' => $modifier['is_available'] ?? true,
                'position' => $i,
            ]);
        }
    }

    private function validated(Request $request): array
    {
        $tenantId = app(TenantContext::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'min_select' => ['required', 'integer', 'min:0', 'max:50'],
            'max_select' => ['required', 'integer', 'min:1', 'max:50', 'gte:min_select'],
            'is_required' => ['boolean'],

            'source' => ['required', Rule::in(ModifierGroup::SOURCES)],
            'pricing_rule' => ['required', Rule::in(ModifierGroup::PRICING_RULES)],

            // Só faz sentido no grupo composto, e precisa ser categoria desta
            // loja: sem o where, o id de outra loja passaria no exists.
            'source_category_id' => [
                'required_if:source,'.ModifierGroup::SOURCE_CATEGORY,
                'nullable',
                Rule::exists('categories', 'id')->where('tenant_id', $tenantId),
            ],

            // Opções do grupo composto: produtos ofertados como sabor.
            'options' => ['array'],
            'options.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'options.*.price_cents' => ['nullable', 'integer', 'min:0'],

            // Opções do grupo de lista.
            'modifiers' => ['array'],
            'modifiers.*.name' => ['required', 'string', 'max:80'],
            'modifiers.*.price_delta_cents' => ['integer'],
            'modifiers.*.is_available' => ['boolean'],
        ]);

        /*
         * O mínimo não pode passar do número de opções ofertadas.
         *
         * Um grupo obrigatório pedindo 3 sabores com 2 cadastrados trava o
         * checkout: o cliente não consegue satisfazer a regra e o OrderService
         * recusa todo pedido daquele produto, sem pista do motivo na tela.
         */
        $count = count($data['source'] === ModifierGroup::SOURCE_CATEGORY
            ? ($data['options'] ?? [])
            : ($data['modifiers'] ?? []));

        if ($count > 0 && $data['min_select'] > $count) {
            abort(422, "Este grupo exige {$data['min_select']} escolhas, mas só tem {$count} opções.");
        }

        return $data;
    }
}
