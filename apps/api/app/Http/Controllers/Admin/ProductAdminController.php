<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ImageStorage;
use App\Tenancy\TenantContext;
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

    public function update(Request $request, Product $product, ImageStorage $images): JsonResponse
    {
        $data = $this->validated($request, $product->id);

        // Lida antes da escrita: depois do update a coluna já foi sobrescrita e
        // não há mais como saber qual arquivo ficou órfão.
        $previousImage = $product->image_url;

        // Eloquent devolve true mesmo quando 0 linhas são afetadas. Se o RLS
        // recusar a escrita, a resposta 200 seria uma confirmação falsa — o
        // cliente veria "salvo" enquanto o banco permanece intacto.
        abort_if(
            $product->newQueryWithoutScopes()
                ->whereKey($product->getKey())
                ->update($data) === 0,
            404,
        );

        /*
         * Só depois do update confirmado, e só se a foto realmente mudou: o
         * painel manda o produto inteiro a cada salvamento, então na maioria das
         * edições `image_url` chega igual ao que já estava lá. Apagar sem
         * comparar destruiria a foto de quem só corrigiu o preço.
         *
         * A ordem também importa. Apagar antes da escrita deixaria o produto
         * apontando para um arquivo inexistente se o update falhasse — imagem
         * quebrada no cardápio é pior do que arquivo órfão no bucket.
         */
        if (array_key_exists('image_url', $data) && $data['image_url'] !== $previousImage) {
            $images->delete($previousImage, $product->tenant_id);
        }

        return response()->json($product->fresh());
    }

    public function destroy(Product $product, ImageStorage $images): JsonResponse
    {
        $image = $product->image_url;
        $tenantId = $product->tenant_id;

        abort_if(
            $product->newQueryWithoutScopes()
                ->whereKey($product->getKey())
                ->delete() === 0,
            404,
        );

        $images->delete($image, $tenantId);

        return response()->json(status: 204);
    }

    public function uploadImage(Request $request, TenantContext $context, ImageStorage $images): JsonResponse
    {
        $tenant = $context->getOrFail();

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp,svg', 'max:5120'],
        ]);

        $file = $request->file('image');
        $filename = "products/{$tenant->id}-".time().'-'.Str::random(6).'.'.$file->getClientOriginalExtension();

        $url = $images->put($file, $filename);

        return response()->json([
            'url' => $url,
            'message' => 'Imagem enviada com sucesso.',
        ]);
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
