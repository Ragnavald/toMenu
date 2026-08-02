<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('position')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'is_active' => ['boolean'],
            // Categoria-insumo: abastece grupos compostos (sabores de pizza) e
            // não aparece como seção do cardápio.
            'is_option_only' => ['boolean'],
        ]);

        $category = Category::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'is_active' => $data['is_active'] ?? true,
            'is_option_only' => $data['is_option_only'] ?? false,
            // Nova categoria entra no fim da lista, não no topo.
            'position' => (int) Category::max('position') + 1,
        ]);

        return response()->json($category, 201);
    }

    public function batchStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'categories' => ['required', 'array', 'min:1'],
            'categories.*.name' => ['required', 'string', 'max:80'],
        ]);

        $created = [];
        $maxPos = (int) Category::max('position');

        DB::transaction(function () use ($data, $maxPos, &$created) {
            foreach ($data['categories'] as $index => $item) {
                $created[] = Category::create([
                    'name' => $item['name'],
                    'slug' => $this->uniqueSlug($item['name']),
                    'is_active' => true,
                    'position' => $maxPos + $index + 1,
                ]);
            }
        });

        return response()->json(['data' => $created], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'is_active' => ['boolean'],
            'is_option_only' => ['boolean'],
        ]);

        // Update pela query, não pelo model: o RLS pode recusar a escrita e
        // `save()` ainda retornaria true, confirmando algo que não aconteceu.
        abort_if(
            $category->newQueryWithoutScopes()
                ->whereKey($category->getKey())
                ->update([
                    'name' => $data['name'],
                    'is_active' => $data['is_active'] ?? $category->is_active,
                    'is_option_only' => $data['is_option_only'] ?? $category->is_option_only,
                    'updated_at' => now(),
                ]) === 0,
            404,
        );

        return response()->json($category->fresh());
    }

    public function destroy(Category $category): JsonResponse
    {
        // Categoria com produtos não pode sumir levando o cardápio junto.
        abort_if(
            $category->products()->exists(),
            422,
            'Mova ou remova os produtos desta categoria antes de excluí-la.',
        );

        abort_if(
            $category->newQueryWithoutScopes()
                ->whereKey($category->getKey())
                ->delete() === 0,
            404,
        );

        return response()->json(status: 204);
    }

    /** Reordenação por arrastar-e-soltar no painel. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        // Só reordena o que pertence ao tenant: ids alheios são descartados
        // pelo global scope antes de chegar ao update.
        $owned = Category::whereIn('id', $data['ids'])->pluck('id')->all();

        DB::transaction(function () use ($data, $owned) {
            foreach ($data['ids'] as $position => $id) {
                if (in_array($id, $owned, true)) {
                    Category::whereKey($id)->update(['position' => $position]);
                }
            }
        });

        return response()->json(['message' => 'Ordem atualizada.']);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;
        $suffix = 2;

        while (Category::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
