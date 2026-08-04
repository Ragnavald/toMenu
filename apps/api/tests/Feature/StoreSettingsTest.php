<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSettings;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    actingAsTenant($this->tenant);
    TenantSettings::create(['tenant_id' => $this->tenant->id]);
    forgetTenant();

    Sanctum::actingAs($this->user);
});

function asStore(): array
{
    return ['X-Tenant' => 'loja-a'];
}

// ---------------------------------------------------------------------------
// Entrega
// ---------------------------------------------------------------------------

it('salva taxa de entrega, pedido mínimo e tempo estimado', function () {
    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/delivery', [
            'feeCents' => 899,
            'minOrderCents' => 3500,
            'etaMinutes' => 50,
            'freeAboveCents' => 9000,
            'acceptsPickup' => true,
            'acceptsDelivery' => true,
        ])
        ->assertOk()
        ->assertJsonPath('delivery.fee_cents', 899)
        ->assertJsonPath('delivery.min_order_cents', 3500);

    $this->withHeaders(asStore())
        ->getJson('/api/admin/settings')
        ->assertJsonPath('delivery.feeCents', 899)
        ->assertJsonPath('delivery.freeAboveCents', 9000);
});

it('salva as modalidades que a loja aceita', function () {
    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/delivery', [
            'feeCents' => 0,
            'minOrderCents' => 0,
            'etaMinutes' => 30,
            'acceptsPickup' => true,
            'acceptsDelivery' => false,
            'acceptsDineIn' => true,
        ])
        ->assertOk();

    $this->withHeaders(asStore())
        ->getJson('/api/admin/settings')
        ->assertJsonPath('delivery.acceptsDelivery', false)
        ->assertJsonPath('delivery.acceptsDineIn', true);
});

it('mantém consumo no local desligado quando o campo não é enviado', function () {
    // Payload no formato anterior ao recurso: um cliente antigo do painel não
    // pode ligar o salão de uma loja sem querer.
    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/delivery', [
            'feeCents' => 0,
            'minOrderCents' => 0,
            'etaMinutes' => 30,
            'acceptsPickup' => true,
            'acceptsDelivery' => true,
        ])
        ->assertOk();

    $this->withHeaders(asStore())
        ->getJson('/api/admin/settings')
        ->assertJsonPath('delivery.acceptsDineIn', false);
});

it('recusa valores negativos de taxa ou pedido mínimo', function () {
    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/delivery', [
            'feeCents' => -100,
            'minOrderCents' => 0,
            'etaMinutes' => 30,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('feeCents');
});

it('a taxa de entrega configurada é aplicada ao total do pedido', function () {
    $this->withHeaders(asStore())->putJson('/api/admin/settings/delivery', [
        'feeCents' => 1200,
        'minOrderCents' => 0,
        'etaMinutes' => 40,
    ])->assertOk();

    actingAsTenant($this->tenant);
    $category = Category::factory()->create();
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'price_cents' => 5000,
    ]);
    forgetTenant();

    $this->withHeaders(asStore())
        ->postJson('/api/orders', [
            'customer' => ['name' => 'João', 'phone' => '11999998888'],
            'fulfillment' => 'delivery',
            'address' => ['street' => 'Rua A', 'city' => 'São Paulo', 'state' => 'SP'],
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->assertJsonPath('totalCents', 6200); // 5000 + 1200
});

// ---------------------------------------------------------------------------
// Horários
// ---------------------------------------------------------------------------

it('salva horário de funcionamento por dia', function () {
    $hours = collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
        ->mapWithKeys(fn ($d) => [$d => ['enabled' => true, 'open' => '11:00', 'close' => '15:00']])
        ->all();

    $hours['sun']['enabled'] = false;

    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/hours', ['hours' => $hours])
        ->assertOk()
        ->assertJsonPath('businessHours.sun.enabled', false)
        ->assertJsonPath('businessHours.mon.open', '11:00');
});

it('recusa horário em formato inválido', function () {
    $hours = collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
        ->mapWithKeys(fn ($d) => [$d => ['enabled' => true, 'open' => '11:00', 'close' => '15:00']])
        ->all();

    $hours['mon']['open'] = '25:99';

    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/hours', ['hours' => $hours])
        ->assertStatus(422);
});

it('o override manual fecha a loja mesmo dentro do horário', function () {
    $hours = collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
        ->mapWithKeys(fn ($d) => [$d => ['enabled' => true, 'open' => '00:00', 'close' => '23:59']])
        ->all();

    $this->withHeaders(asStore())->putJson('/api/admin/settings/hours', [
        'hours' => $hours,
        'isOpenOverride' => false,
    ])->assertOk();

    // O storefront precisa refletir o "fechar agora" imediatamente.
    $this->withHeaders(asStore())
        ->getJson('/api/menu')
        ->assertJsonPath('tenant.isOpen', false);
});

it('sem override, a loja aberta 24h aparece como aberta', function () {
    $hours = collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
        ->mapWithKeys(fn ($d) => [$d => ['enabled' => true, 'open' => '00:00', 'close' => '23:59']])
        ->all();

    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/hours', ['hours' => $hours])
        ->assertOk();

    $this->withHeaders(asStore())
        ->getJson('/api/menu')
        ->assertJsonPath('tenant.isOpen', true);
});

// ---------------------------------------------------------------------------
// Pagamentos
// ---------------------------------------------------------------------------

it('salva métodos de pagamento na entrega', function () {
    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/payments', [
            'methods' => ['cash', 'card_on_delivery', 'pix_on_delivery'],
        ])
        ->assertOk()
        ->assertJsonPath('paymentMethods', ['cash', 'card_on_delivery', 'pix_on_delivery']);
});

it('descarta pagamento online enquanto o Stripe não está habilitado', function () {
    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/payments', [
            'methods' => ['cash', 'stripe_card', 'stripe_pix'],
        ])
        ->assertOk()
        // Ofertar cartão online sem conta Connect levaria o cliente final a um
        // checkout que falha na hora de pagar.
        ->assertJsonPath('paymentMethods', ['cash']);
});

it('aceita pagamento online quando o Stripe Connect está pronto', function () {
    $this->tenant->update([
        'stripe_account_id' => 'acct_test',
        'stripe_charges_enabled' => true,
    ]);

    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/payments', [
            'methods' => ['cash', 'stripe_card'],
        ])
        ->assertOk()
        ->assertJsonPath('paymentMethods', ['cash', 'stripe_card']);
});

it('nunca deixa a loja sem nenhum método de pagamento', function () {
    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/payments', ['methods' => ['stripe_card']])
        ->assertOk()
        ->assertJsonPath('paymentMethods', ['cash']);
});

// ---------------------------------------------------------------------------
// Perfil e onboarding
// ---------------------------------------------------------------------------

it('salva os dados de contato da loja', function () {
    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/profile', [
            'name' => 'Cantina Renovada',
            'phone' => '1133334444',
            'whatsapp' => '11999998888',
            'address' => 'Rua das Flores, 100',
            'description' => 'Comida caseira italiana.',
        ])
        ->assertOk();

    expect($this->tenant->fresh()->name)->toBe('Cantina Renovada');

    $this->withHeaders(asStore())
        ->getJson('/api/menu')
        ->assertJsonPath('tenant.whatsapp', '11999998888')
        ->assertJsonPath('tenant.description', 'Comida caseira italiana.');
});

it('avança o passo do onboarding sem retroceder', function () {
    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/onboarding', ['step' => 3])
        ->assertOk()
        ->assertJsonPath('onboardingStep', 3);

    // Voltar a um passo anterior no wizard não pode desfazer o progresso.
    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/onboarding', ['step' => 2])
        ->assertOk()
        ->assertJsonPath('onboardingStep', 3);
});

it('marca o onboarding como concluído', function () {
    $this->withHeaders(asStore())
        ->putJson('/api/admin/settings/onboarding', ['step' => 5, 'complete' => true])
        ->assertOk()
        ->assertJsonPath('onboardingCompleted', true);

    expect($this->tenant->fresh()->onboarding_completed_at)->not->toBeNull();
});

it('não expõe configurações de uma loja para outra', function () {
    $other = Tenant::factory()->create(['slug' => 'loja-b']);
    actingAsTenant($other);
    TenantSettings::create([
        'tenant_id' => $other->id,
        'phone' => 'SEGREDO-DA-LOJA-B',
    ]);
    forgetTenant();

    $response = $this->withHeaders(asStore())->getJson('/api/admin/settings');

    expect(json_encode($response->json()))->not->toContain('SEGREDO-DA-LOJA-B');
});

it('faz upload da logo da loja', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->create('logo.png', 100, 'image/png');

    $this->withHeaders(asStore())
        ->postJson('/api/admin/settings/logo', [
            'logo' => $file,
        ])
        ->assertOk()
        ->assertJsonStructure(['url', 'message']);

    expect(TenantSettings::find($this->tenant->id)->logo_url)->not->toBeNull();
});

/*
 * Regressão: a logo já foi para o disco local mesmo com o R2 configurado,
 * porque o upload só reconhecia o disco 's3'. O resultado era silencioso — foto
 * de produto na nuvem, logo no container, some no deploy seguinte.
 */
it('envia a logo para o disco remoto quando o R2 está configurado', function () {
    Storage::fake('r2');
    Storage::fake('public');
    config(['filesystems.disks.r2.key' => 'test-key']);

    $this->withHeaders(asStore())
        ->postJson('/api/admin/settings/logo', [
            'logo' => UploadedFile::fake()->create('logo.png', 100, 'image/png'),
        ])
        ->assertOk();

    expect(Storage::disk('r2')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('envia a imagem do produto para o mesmo disco remoto que a logo', function () {
    Storage::fake('r2');
    Storage::fake('public');
    config(['filesystems.disks.r2.key' => 'test-key']);

    $this->withHeaders(asStore())
        ->postJson('/api/admin/products/upload-image', [
            'image' => UploadedFile::fake()->create('prato.png', 100, 'image/png'),
        ])
        ->assertOk();

    expect(Storage::disk('r2')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});
