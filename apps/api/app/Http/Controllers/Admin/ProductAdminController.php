<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CRUD de produtos.
 *
 * Nenhum método filtra por tenant explicitamente: o global scope de
 * BelongsToTenant já restringe toda query, inclusive o route model binding de
 * {product}. Um id de outro tenant resulta em 404, não em vazamento.
 */
class ProductAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with('category:id,name')
            ->when($request->string('search')->toString(), fn ($q, $term) =>
                $q->where('name', 'ilike', "%{$term}%"))
            ->when($request->integer('category_id'), fn ($q, $id) =>
                $q->where('category_id', $id))
            ->orderBy('position')
            // O painel agrupa por seção e precisa do cardápio inteiro de uma
            // vez; o teto evita que um catálogo grande derrube a resposta.
            ->paginate(min($request->integer('per_page', 30) ?: 30, 200));

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['name']);

        $product = Product::create($data);

        return response()->json($product, 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json($product->load('modifierGroups.modifiers'));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        // Eloquent devolve true mesmo quando 0 linhas são afetadas. Se o RLS
        // recusar a escrita, a resposta 200 seria uma confirmação falsa — o
        // cliente veria "salvo" enquanto o banco permanece intacto.
        abort_if(
            $product->newQueryWithoutScopes()
                ->whereKey($product->getKey())
                ->update($this->validated($request, $product->id)) === 0,
            404,
        );

        return response()->json($product->fresh());
    }

    public function destroy(Product $product): JsonResponse
    {
        abort_if(
            $product->newQueryWithoutScopes()
                ->whereKey($product->getKey())
                ->delete() === 0,
            404,
        );

        return response()->json(status: 204);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'category_id' => [
                'required',
                // Confirma que a categoria é do mesmo tenant. Sem isso seria
                // possível vincular um produto à categoria de outra loja.
                Rule::exists('categories', 'id')->where(
                    'tenant_id',
                    app(\App\Tenancy\TenantContext::class)->id(),
                ),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'promo_price_cents' => ['nullable', 'integer', 'min:0', 'lt:price_cents'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'is_available' => ['boolean'],
            'position' => ['integer', 'min:0'],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (Product::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
