<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A suíte mais importante do sistema.
 *
 * Num modelo single-database, o isolamento é garantido por código — e código
 * tem bugs. Estes testes existem para que qualquer regressão que exponha dados
 * entre estabelecimentos quebre o build antes de chegar à produção.
 *
 * Cada camada é testada isoladamente, porque cada uma pode falhar sozinha:
 *   Camada 2 (global scope) — testes de "não enxerga"
 *   Camada 2 (auto-fill)    — testes de atribuição de tenant_id
 *   Camada 3 (RLS)          — testes que burlam o Eloquent de propósito
 *   Camada 1 (middleware)   — testes de HTTP
 */
beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->tenantB = Tenant::factory()->create(['slug' => 'loja-b']);
});

// ---------------------------------------------------------------------------
// Camada 2 — Global scope
// ---------------------------------------------------------------------------

it('não enxerga produtos de outro tenant', function () {
    actingAsTenant($this->tenantB);
    $productOfB = Product::factory()->create();

    actingAsTenant($this->tenantA);

    expect(Product::find($productOfB->id))->toBeNull()
        ->and(Product::count())->toBe(0);
});

/*
 * Cobre cada model tenant-scoped com o mesmo teste. Ao adicionar um novo model
 * com BelongsToTenant, inclua-o aqui — é o que impede que uma tabela nova
 * escape do isolamento por esquecimento.
 *
 * Os nomes são passados como string e resolvidos dentro do teste: o dataset é
 * avaliado antes do boot do app, e chamar ::factory() ali resultaria em erro
 * de conexão nula.
 */
it('isola todos os models tenant-scoped', function (string $model) {
    actingAsTenant($this->tenantB);
    $record = $model::factory()->create();

    actingAsTenant($this->tenantA);

    expect($model::find($record->id))->toBeNull();
})->with([
    'produtos' => [Product::class],
    'categorias' => [Category::class],
]);

it('atribui o tenant do contexto automaticamente ao criar', function () {
    actingAsTenant($this->tenantA);

    $product = Product::factory()->create();

    expect($product->tenant_id)->toBe($this->tenantA->id);
});

it('impede que uma busca por id vaze registro de outro tenant', function () {
    actingAsTenant($this->tenantA);
    $categoryOfA = Category::factory()->create();

    actingAsTenant($this->tenantB);
    $found = Category::where('id', $categoryOfA->id)->first();

    expect($found)->toBeNull();
});

// ---------------------------------------------------------------------------
// Camada 3 — Row Level Security
// ---------------------------------------------------------------------------

it('bloqueia leitura via query builder mesmo sem o global scope', function () {
    actingAsTenant($this->tenantB);
    $productOfB = Product::factory()->create();

    actingAsTenant($this->tenantA);

    // Query crua: passa por baixo do Eloquent e do global scope.
    // Só o RLS impede o vazamento aqui.
    $rows = DB::table('products')->where('id', $productOfB->id)->get();

    expect($rows)->toBeEmpty();
});

it('bloqueia leitura mesmo quando o global scope é removido explicitamente', function () {
    actingAsTenant($this->tenantB);
    $productOfB = Product::factory()->create();

    actingAsTenant($this->tenantA);

    // withoutGlobalScopes desarma a camada 2 de propósito — simula o erro
    // humano mais provável em código de manutenção.
    $found = Product::withoutGlobalScopes()->find($productOfB->id);

    expect($found)->toBeNull();
});

it('impede gravar registro com tenant_id de outro tenant', function () {
    actingAsTenant($this->tenantA);
    $category = Category::factory()->create();

    // A cláusula WITH CHECK da policy recusa a escrita cruzada.
    expect(fn () => DB::table('products')->insert([
        'tenant_id' => $this->tenantB->id,
        'category_id' => $category->id,
        'name' => 'Contrabando',
        'slug' => 'contrabando',
        'price_cents' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('impede update cruzado via query builder', function () {
    actingAsTenant($this->tenantB);
    $productOfB = Product::factory()->create(['name' => 'Original']);

    actingAsTenant($this->tenantA);
    $affected = DB::table('products')->where('id', $productOfB->id)->update(['name' => 'Invadido']);

    expect($affected)->toBe(0);

    actingAsTenant($this->tenantB);
    expect(Product::find($productOfB->id)->name)->toBe('Original');
});

it('impede delete cruzado via query builder', function () {
    actingAsTenant($this->tenantB);
    $productOfB = Product::factory()->create();

    actingAsTenant($this->tenantA);
    $deleted = DB::table('products')->where('id', $productOfB->id)->delete();

    expect($deleted)->toBe(0);

    actingAsTenant($this->tenantB);
    expect(Product::find($productOfB->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Contexto e vazamento entre requests (risco sob Octane)
// ---------------------------------------------------------------------------

it('restaura o contexto anterior após runFor', function () {
    actingAsTenant($this->tenantA);

    app(TenantContext::class)->runFor($this->tenantB, function () {
        expect(app(TenantContext::class)->id())->toBe($this->tenantB->id);
    });

    expect(app(TenantContext::class)->id())->toBe($this->tenantA->id);
});

it('limpa a variável de sessão do postgres ao esquecer o tenant', function () {
    actingAsTenant($this->tenantA);
    forgetTenant();

    $value = DB::selectOne("SELECT current_setting('app.tenant_id', true) AS v")->v;

    expect($value)->toBeIn(['', null]);
});
