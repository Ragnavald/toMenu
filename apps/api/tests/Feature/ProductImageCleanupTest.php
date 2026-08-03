<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Faxina da foto do produto no object storage.
 *
 * O upload grava no bucket na hora em que o lojista escolhe o arquivo, mas a
 * URL só vai para o banco quando ele salva. Quem apaga é o salvamento, e é por
 * isso que os testes daqui giram em torno do PUT e do DELETE, não da rota de
 * upload.
 *
 * O disco é falso, mas o contrato é o do R2: sem diretório por tenant, todos os
 * produtos convivem em `products/` e o id no começo do nome é a única coisa que
 * liga um objeto à sua loja.
 */
beforeEach(function () {
    /*
     * O `url` explícito não é detalhe de arrumação. O `Storage::fake('public')`
     * puro monta o disco do zero e ignora o `url` do config, devolvendo caminho
     * relativo (`/storage/...`) — o que quebraria os testes por um motivo que
     * não existe em produção (a validação `url` do controller recusa caminho
     * relativo) e, pior, esconderia a comparação de host do ImageStorage: com
     * URL relativa o host é null dos dois lados e casaria sempre.
     */
    Storage::fake('public', ['url' => 'http://localhost:8000/storage']);

    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    actingAsTenant($this->tenant);
    $this->category = Category::factory()->create(['tenant_id' => $this->tenant->id]);
    forgetTenant();

    Sanctum::actingAs($this->user);
});

function headers(): array
{
    return ['X-Tenant' => 'loja-a'];
}

/** Grava um objeto no disco falso e devolve a URL pública dele. */
function fakeImage(string $basename): string
{
    Storage::disk('public')->put("products/{$basename}", 'x');

    return Storage::disk('public')->url("products/{$basename}");
}

function makeProduct(Tenant $tenant, int $categoryId, ?string $imageUrl): Product
{
    actingAsTenant($tenant);

    $product = Product::factory()->create([
        'tenant_id' => $tenant->id,
        'category_id' => $categoryId,
        'image_url' => $imageUrl,
    ]);

    forgetTenant();

    return $product;
}

/** Payload completo do editor: o painel manda o produto inteiro a cada save. */
function payload(Product $product, array $overrides = []): array
{
    return array_merge([
        'category_id' => $product->category_id,
        'name' => $product->name,
        'description' => $product->description,
        'price_cents' => $product->price_cents,
        'promo_price_cents' => $product->promo_price_cents,
        'image_url' => $product->image_url,
        'is_available' => $product->is_available,
    ], $overrides);
}

it('apaga a foto antiga do bucket quando o lojista troca a imagem', function () {
    $old = fakeImage("{$this->tenant->id}-111-abc.jpg");
    $new = fakeImage("{$this->tenant->id}-222-def.jpg");

    $product = makeProduct($this->tenant, $this->category->id, $old);

    $this->withHeaders(headers())
        ->putJson("/api/admin/products/{$product->id}", payload($product, ['image_url' => $new]))
        ->assertOk();

    Storage::disk('public')->assertMissing("products/{$this->tenant->id}-111-abc.jpg");
    Storage::disk('public')->assertExists("products/{$this->tenant->id}-222-def.jpg");
});

it('apaga a foto quando o lojista remove a imagem sem colocar outra', function () {
    $old = fakeImage("{$this->tenant->id}-111-abc.jpg");
    $product = makeProduct($this->tenant, $this->category->id, $old);

    $this->withHeaders(headers())
        ->putJson("/api/admin/products/{$product->id}", payload($product, ['image_url' => null]))
        ->assertOk();

    Storage::disk('public')->assertMissing("products/{$this->tenant->id}-111-abc.jpg");
});

it('mantém a foto quando a edição não mexeu na imagem', function () {
    // O caso mais comum de todos: corrigir o preço. Se a comparação com a URL
    // anterior sumisse, todo salvamento apagaria a foto do próprio produto.
    $url = fakeImage("{$this->tenant->id}-111-abc.jpg");
    $product = makeProduct($this->tenant, $this->category->id, $url);

    $this->withHeaders(headers())
        ->putJson("/api/admin/products/{$product->id}", payload($product, ['price_cents' => 4200]))
        ->assertOk();

    Storage::disk('public')->assertExists("products/{$this->tenant->id}-111-abc.jpg");
});

it('mantém a foto quando o payload não traz o campo de imagem', function () {
    // É o formato que o botão "Esgotar/Repor" do painel envia: produto inteiro
    // menos a image_url. Sem a checagem de chave ausente, alternar a
    // disponibilidade apagaria a foto.
    $url = fakeImage("{$this->tenant->id}-111-abc.jpg");
    $product = makeProduct($this->tenant, $this->category->id, $url);

    $body = payload($product);
    unset($body['image_url']);

    $this->withHeaders(headers())
        ->putJson("/api/admin/products/{$product->id}", $body)
        ->assertOk();

    Storage::disk('public')->assertExists("products/{$this->tenant->id}-111-abc.jpg");
});

it('apaga a foto ao excluir o produto', function () {
    $url = fakeImage("{$this->tenant->id}-111-abc.jpg");
    $product = makeProduct($this->tenant, $this->category->id, $url);

    $this->withHeaders(headers())
        ->deleteJson("/api/admin/products/{$product->id}")
        ->assertNoContent();

    Storage::disk('public')->assertMissing("products/{$this->tenant->id}-111-abc.jpg");
});

it('não apaga arquivo de outra loja com url forjada', function () {
    // O campo aceita URL livre, então nada impede o lojista de colar no editor
    // o endereço da foto de outra loja e salvar. O que ele não pode é conseguir
    // apagá-la ao trocar de imagem depois.
    $victim = fakeImage('999-111-abc.jpg');
    $product = makeProduct($this->tenant, $this->category->id, $victim);

    $this->withHeaders(headers())
        ->putJson("/api/admin/products/{$product->id}", payload($product, ['image_url' => null]))
        ->assertOk();

    Storage::disk('public')->assertExists('products/999-111-abc.jpg');
});

it('não apaga o logo da loja pela edição de um produto', function () {
    // Mesma loja, mesmo bucket, prefixo diferente. Editar produto não pode
    // alcançar o `logos/`, senão o lojista derruba a marca da própria loja ao
    // colar a URL dela no campo de imagem e depois trocar a foto.
    Storage::disk('public')->put("logos/{$this->tenant->id}-logo-111.png", 'x');
    $logo = Storage::disk('public')->url("logos/{$this->tenant->id}-logo-111.png");

    $product = makeProduct($this->tenant, $this->category->id, $logo);

    $this->withHeaders(headers())
        ->putJson("/api/admin/products/{$product->id}", payload($product, ['image_url' => null]))
        ->assertOk();

    Storage::disk('public')->assertExists("logos/{$this->tenant->id}-logo-111.png");
});

it('ignora url externa que imita o nome de um arquivo nosso', function () {
    // Basename com o id certo, host de terceiro. Só o nome não basta: o que
    // decide é a URL apontar para o nosso disco.
    $mine = fakeImage("{$this->tenant->id}-111-abc.jpg");
    $product = makeProduct(
        $this->tenant,
        $this->category->id,
        "https://exemplo-externo.test/products/{$this->tenant->id}-111-abc.jpg",
    );

    $this->withHeaders(headers())
        ->putJson("/api/admin/products/{$product->id}", payload($product, ['image_url' => $mine]))
        ->assertOk();

    Storage::disk('public')->assertExists("products/{$this->tenant->id}-111-abc.jpg");
});
