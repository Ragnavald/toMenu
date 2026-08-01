<?php

use App\Events\OrderReceived;
use App\Jobs\NotifyNewOrder;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

/**
 * O broadcast é o único aviso que a cozinha recebe. Se ele não sair, o pedido
 * fica invisível até alguém recarregar a tela — por isso o caminho é testado
 * do job até a autorização do canal.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'loja-a']);

    actingAsTenant($this->tenant);

    $category = Category::factory()->create();
    Product::factory()->create(['category_id' => $category->id]);

    $this->order = Order::create([
        'tenant_id' => $this->tenant->id,
        'number' => 42,
        'status' => 'confirmed',
        'fulfillment' => 'delivery',
        'payment_method' => 'cash',
        'payment_status' => 'pending',
        'subtotal_cents' => 4500,
        'delivery_fee_cents' => 0,
        'total_cents' => 4500,
        'placed_at' => now(),
    ]);
});

it('anuncia o pedido no canal da loja', function () {
    Event::fake([OrderReceived::class]);

    forgetTenant();

    (new NotifyNewOrder($this->order->id, $this->tenant->id))
        ->handle(app(TenantContext::class));

    Event::assertDispatched(
        OrderReceived::class,
        fn (OrderReceived $event) => $event->orderId === $this->order->id
            && $event->tenantId === $this->tenant->id
            && $event->orderNumber === 42,
    );
});

it('transmite no canal privado do tenant', function () {
    $event = new OrderReceived($this->order->id, $this->tenant->id, 42);

    expect($event->broadcastAs())->toBe('order.received')
        ->and($event->broadcastOn()[0]->name)
        ->toBe("private-tenant.{$this->tenant->id}.orders");
});

it('não avisa quando o pedido sumiu antes do job rodar', function () {
    Event::fake([OrderReceived::class]);

    $orderId = $this->order->id;
    $this->order->forceDelete();

    forgetTenant();

    (new NotifyNewOrder($orderId, $this->tenant->id))
        ->handle(app(TenantContext::class));

    Event::assertNotDispatched(OrderReceived::class);
});

/*
|--------------------------------------------------------------------------
| Autorização do canal
|--------------------------------------------------------------------------
| Exercitada pelo callback de routes/channels.php, e não por uma chamada a
| /api/broadcasting/auth: nos testes o BROADCAST_CONNECTION é `null`, e o
| NullBroadcaster responde 200 a qualquer canal sem consultar o callback — a
| rota passaria mesmo com a autorização errada.
*/
function authorizeOrdersChannel(User $user, int $tenantId): bool
{
    $channel = Broadcast::getChannels()['tenant.{tenantId}.orders'];

    return (bool) $channel($user, $tenantId);
}

it('autoriza o dono da loja a ouvir o próprio canal', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    expect(authorizeOrdersChannel($user, $this->tenant->id))->toBeTrue();
});

it('recusa quem tenta ouvir o canal de outra loja', function () {
    // O vazamento aqui seria silencioso: a loja invasora acompanharia o
    // movimento da concorrente em tempo real, sem tocar em nenhuma rota HTTP.
    $outra = Tenant::factory()->create(['slug' => 'loja-b']);

    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    expect(authorizeOrdersChannel($user, $outra->id))->toBeFalse();
});

it('deixa a equipe da plataforma acompanhar qualquer loja', function () {
    // tenant_id nulo é o que define staff da plataforma — o suporte precisa
    // enxergar a operação da loja que está atendendo.
    $staff = User::factory()->create(['tenant_id' => null]);

    expect(authorizeOrdersChannel($staff, $this->tenant->id))->toBeTrue();
});

it('exige autenticação na rota de autorização do canal', function () {
    $this->postJson('/api/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-tenant.{$this->tenant->id}.orders",
    ])
        ->assertUnauthorized();
});
