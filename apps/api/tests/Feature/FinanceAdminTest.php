<?php

use App\Jobs\GenerateFinancialReport;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ReportJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
    return app(App\Tenancy\TenantContext::class)->runFor($tenant, $callback);
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

it('enfileira a geração do PDF e responde 202', function () {
    Queue::fake();
    makeOrder($this->tenant);

    $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/finance/exports', ['from' => '2026-07-01', 'to' => '2026-07-31'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued');

    Queue::assertPushed(GenerateFinancialReport::class);
});

it('reaproveita a exportação pendente em vez de enfileirar duas iguais', function () {
    Queue::fake();
    makeOrder($this->tenant);

    $payload = ['from' => '2026-07-01', 'to' => '2026-07-31'];

    $first = $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/finance/exports', $payload)->json('id');

    $second = $this->withHeader('X-Tenant', 'loja-a')
        ->postJson('/api/admin/finance/exports', $payload)->json('id');

    expect($second)->toBe($first);
    Queue::assertPushed(GenerateFinancialReport::class, 1);
});

it('gera o PDF de verdade ao rodar o job', function () {
    Storage::fake('local');
    makeOrder($this->tenant, ['total_cents' => 12345]);

    $report = actingAsTenantAnd($this->tenant, fn () => ReportJob::create([
        'user_id' => $this->user->id,
        'status' => 'queued',
        'from_date' => now()->startOfMonth()->toDateString(),
        'to_date' => now()->endOfMonth()->toDateString(),
    ]));

    (new GenerateFinancialReport($report->id, $this->tenant->id))
        ->handle(app(App\Tenancy\TenantContext::class), app(App\Services\FinanceReportService::class));

    $report->refresh();

    expect($report->status)->toBe('done')
        ->and($report->file_path)->not->toBeNull()
        ->and($report->file_size)->toBeGreaterThan(0);

    Storage::disk('local')->assertExists($report->file_path);
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
 * Regressão de vazamento entre lojas.
 *
 * O route model binding roda em `substituteBindings`, ANTES do middleware que
 * popula o tenant. Sem contexto, o global scope não filtra nada e o registro de
 * qualquer loja era resolvido: bastava trocar o id na URL para baixar o PDF
 * financeiro de outro estabelecimento. O controller passou a filtrar tenant_id
 * explicitamente; este teste garante que ninguém volte ao binding implícito.
 */
it('não deixa uma loja baixar o relatório de outra', function () {
    $other = Tenant::factory()->create(['slug' => 'loja-b']);

    $foreignReport = actingAsTenantAnd($other, fn () => ReportJob::create([
        'status' => 'done',
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-31',
        'file_path' => 'reports/999/secreto.pdf',
        'file_size' => 100,
    ]));

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson("/api/admin/finance/exports/{$foreignReport->id}")
        ->assertNotFound();

    $this->withHeader('X-Tenant', 'loja-a')
        ->get("/api/admin/finance/exports/{$foreignReport->id}/download")
        ->assertNotFound();
});

it('lista no histórico apenas as exportações da própria loja', function () {
    $other = Tenant::factory()->create(['slug' => 'loja-b']);

    actingAsTenantAnd($other, fn () => ReportJob::create([
        'status' => 'done', 'from_date' => '2026-07-01', 'to_date' => '2026-07-31',
    ]));
    actingAsTenantAnd($this->tenant, fn () => ReportJob::create([
        'status' => 'done', 'from_date' => '2026-07-01', 'to_date' => '2026-07-31',
    ]));

    $this->withHeader('X-Tenant', 'loja-a')
        ->getJson('/api/admin/finance/exports')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
