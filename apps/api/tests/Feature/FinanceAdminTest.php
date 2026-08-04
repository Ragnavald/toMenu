<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FinanceReportService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    Sanctum::actingAs($this->user);
});

/** Cria um pedido no contexto do tenant informado. */
function makeOrder(Tenant $tenant, array $attributes = []): Order
{
    return actingAsTenantAnd($tenant, function () use ($attributes) {
        $customer = Customer::firstOrCreate(
            ['phone' => $attributes['phone'] ?? '11990000000'],
            ['name' => $attributes['customer'] ?? 'Ana Souza'],
        );

        return Order::create(array_merge([
            'customer_id' => $customer->id,
            'number' => Order::max('number') + 1,
            'status' => 'delivered',
            'fulfillment' => 'delivery',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal_cents' => 10000,
            'delivery_fee_cents' => 500,
            'total_cents' => 10500,
            'placed_at' => now(),
        ], array_diff_key($attributes, array_flip(['customer', 'phone']))));
    });
}

function actingAsTenantAnd(Tenant $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->runFor($tenant, $callback);
}

// ---------------------------------------------------------------------------
// Receita
// ---------------------------------------------------------------------------

it('conta apenas pedidos entregues e pagos na receita', function () {
    makeOrder($this->tenant, ['total_cents' => 10000]);
    makeOrder($this->tenant, ['status' => 'cancelled', 'total_cents' => 99900]);
    makeOrder($this->tenant, ['payment_status' => 'pending', 'total_cents' => 88800]);

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/finance/overview')
        ->assertOk()
        ->assertJsonPath('summary.revenueCents', 10000)
        ->assertJsonPath('summary.ordersCount', 1);
});

it('calcula o ticket médio sem dividir por zero quando não há pedidos', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/finance/overview')
        ->assertOk()
        ->assertJsonPath('summary.averageTicketCents', 0)
        ->assertJsonPath('summary.revenueCents', 0);
});

it('filtra pelo intervalo de datas informado', function () {
    makeOrder($this->tenant, ['placed_at' => Carbon::parse('2026-03-15'), 'total_cents' => 5000]);
    makeOrder($this->tenant, ['placed_at' => Carbon::parse('2026-05-15'), 'total_cents' => 7000]);

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/finance/overview?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('summary.revenueCents', 5000);
});

/*
 * Regressão: o cursor da série mensal usava addMonth(). Partindo de um dia 31,
 * `addMonth` transborda (31/01 → 03/03) e meses inteiros sumiam do gráfico.
 */
it('inclui todos os meses do intervalo mesmo partindo de um dia 31', function () {
    makeOrder($this->tenant, ['placed_at' => Carbon::parse('2026-01-31 12:00'), 'total_cents' => 5000]);
    makeOrder($this->tenant, ['placed_at' => Carbon::parse('2026-02-10 12:00'), 'total_cents' => 3000]);

    $response = $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/finance/overview?from=2026-01-31&to=2026-04-30')
        ->assertOk();

    $months = collect($response->json('monthly'))->pluck('month');

    expect($months)->toContain('2026-01', '2026-02', '2026-03', '2026-04');
});

it('busca por nome do cliente sem diferenciar maiúsculas', function () {
    makeOrder($this->tenant, ['customer' => 'Maria Silva', 'phone' => '11988887777', 'total_cents' => 4000]);
    makeOrder($this->tenant, ['customer' => 'João Souza', 'phone' => '11977776666', 'total_cents' => 6000]);

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/finance/overview?search=maria')
        ->assertOk()
        ->assertJsonPath('summary.revenueCents', 4000);
});

it('troca a granularidade de diária para mensal em intervalos longos', function () {
    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/finance/overview?from=2026-01-01&to=2026-01-31')
        ->assertJsonPath('range.granularity', 'day');

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/finance/overview?from=2025-01-01&to=2026-01-31')
        ->assertJsonPath('range.granularity', 'month');
});

// ---------------------------------------------------------------------------
// Exportação
// ---------------------------------------------------------------------------

it('exporta os pedidos do período em CSV', function () {
    makeOrder($this->tenant, ['total_cents' => 12345, 'placed_at' => '2026-07-10 12:00:00']);

    $response = $this->withHeader('X-Tenant', 'loja-a')
        ->get('/api/admin/finance/export?from=2026-07-01&to=2026-07-31');

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload('financeiro-2026-07-01-a-2026-07-31.csv');

    $csv = $response->streamedContent();

    // BOM na frente, senão o Excel no Windows quebra os acentos.
    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('Pedido;Data;Cliente')
        // Decimal com vírgula e sem separador de milhar: é o que o Excel
        // pt-BR soma. Com ponto, a coluna vira texto.
        ->and($csv)->toContain('123,45');
});

/*
 * O período vazio precisa devolver um CSV válido, não um arquivo de zero byte.
 *
 * Uma planilha só com cabeçalho comunica "não houve venda"; um arquivo vazio
 * parece falha de download e gera chamado de suporte.
 */
it('exporta apenas o cabeçalho quando não há pedidos no período', function () {
    $response = $this->withHeader('X-Tenant', 'loja-a')
        ->get('/api/admin/finance/export?from=2026-07-01&to=2026-07-31');

    $response->assertOk();

    $linhas = array_filter(explode("\n", trim($response->streamedContent())));

    expect($linhas)->toHaveCount(1);
});

/*
 * O que sustenta o consumo constante de memória.
 *
 * `toBase()` devolve stdClass em vez de model hidratado, e `cursor()` percorre
 * em streaming. Se alguém trocar por `get()` num refactor, a memória volta a
 * crescer com o período — e o estouro só apareceria em produção, na loja com
 * mais pedidos. Este teste falha antes disso.
 */
it('percorre a exportação sem hidratar models', function () {
    makeOrder($this->tenant);

    $rows = actingAsTenantAnd($this->tenant, fn () => app(FinanceReportService::class)
        ->exportCursor(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31')));

    expect($rows)->toBeInstanceOf(LazyCollection::class);

    foreach ($rows as $row) {
        expect($row)->toBeInstanceOf(stdClass::class)
            ->and($row)->not->toBeInstanceOf(Order::class);
    }
});

// ---------------------------------------------------------------------------
// Isolamento entre lojas
// ---------------------------------------------------------------------------

it('não soma no financeiro os pedidos de outra loja', function () {
    $other = Tenant::factory()->create(['slug' => 'loja-b']);
    makeOrder($other, ['total_cents' => 999999]);
    makeOrder($this->tenant, ['total_cents' => 1000]);

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/finance/overview')
        ->assertJsonPath('summary.revenueCents', 1000);
});

/*
 * Regressão de vazamento entre lojas, agora na exportação.
 *
 * A versão em PDF guardava cada relatório numa linha com id sequencial, e o
 * route model binding resolvia o registro ANTES de o middleware popular o
 * tenant: bastava trocar o id na URL para baixar o financeiro de outra loja.
 *
 * O CSV não tem id na URL — o recorte vem do contexto de tenant —, então aquele
 * vetor deixou de existir. O que continua valendo a pena travar é a garantia
 * equivalente: o conteúdo do arquivo só pode conter pedidos da loja que pediu.
 */
it('não inclui no CSV os pedidos de outra loja', function () {
    $other = Tenant::factory()->create(['slug' => 'loja-b']);
    makeOrder($other, ['total_cents' => 999999, 'placed_at' => '2026-07-10 12:00:00']);
    makeOrder($this->tenant, ['total_cents' => 1000, 'placed_at' => '2026-07-10 12:00:00']);

    $csv = $this->withHeader('X-Tenant', 'loja-a')
        ->get('/api/admin/finance/export?from=2026-07-01&to=2026-07-31')
        ->streamedContent();

    expect($csv)->toContain('10,00')
        ->and($csv)->not->toContain('9999,99');
});
