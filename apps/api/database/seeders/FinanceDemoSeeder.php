<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Pedidos espalhados por vários meses, para exercitar o painel financeiro.
 *
 * Só para desenvolvimento: gera volume suficiente para conferir agregações,
 * gráficos e paginação sem precisar de um banco de produção.
 */
class FinanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();

        if (! $tenant) {
            $this->command->warn('Nenhum tenant encontrado; rode o DemoSeeder antes.');

            return;
        }

        app(TenantContext::class)->runFor($tenant, function () {
            $customers = collect(['Ana Souza', 'Bruno Lima', 'Carla Dias', 'Diego Reis', 'Elena Costa'])
                ->map(fn (string $name, int $i) => Customer::firstOrCreate(
                    ['phone' => '1199000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)],
                    ['name' => $name],
                ));

            $products = ['Pizza Margherita', 'Lasanha', 'Refrigerante 2L', 'Tiramisu', 'Espaguete'];
            $methods = ['cash', 'card_on_delivery', 'pix_on_delivery', 'stripe_card', 'stripe_pix'];

            $number = (int) Order::max('number');
            $created = 0;

            // Oito meses para trás: o suficiente para a tendência mensal ter forma.
            for ($monthsAgo = 7; $monthsAgo >= 0; $monthsAgo--) {
                // NoOverflow: em dia 31, `subMonths` transborda e pula meses.
                $month = Carbon::now()->startOfMonth()->subMonthsNoOverflow($monthsAgo);
                $ordersInMonth = random_int(12, 30);

                for ($i = 0; $i < $ordersInMonth; $i++) {
                    $placedAt = $month->copy()
                        ->startOfMonth()
                        ->addDays(random_int(0, $month->daysInMonth - 1))
                        ->addHours(random_int(11, 22))
                        ->addMinutes(random_int(0, 59));

                    if ($placedAt->isFuture()) {
                        continue;
                    }

                    $subtotal = random_int(3500, 18000);
                    $fee = random_int(0, 1) ? 700 : 0;

                    // ~12% dos pedidos ficam fora da receita (cancelados ou não
                    // pagos), para que o filtro tenha o que excluir de verdade.
                    $roll = random_int(1, 100);
                    $status = $roll <= 88 ? 'delivered' : ($roll <= 94 ? 'cancelled' : 'confirmed');
                    $paymentStatus = $status === 'delivered' ? 'paid' : 'pending';

                    $order = Order::create([
                        'customer_id' => $customers->random()->getKey(),
                        'number' => ++$number,
                        'status' => $status,
                        'fulfillment' => random_int(0, 1) ? 'delivery' : 'pickup',
                        'payment_method' => $methods[array_rand($methods)],
                        'payment_status' => $paymentStatus,
                        'subtotal_cents' => $subtotal,
                        'delivery_fee_cents' => $fee,
                        'total_cents' => $subtotal + $fee,
                        'placed_at' => $placedAt,
                        'delivered_at' => $status === 'delivered' ? $placedAt->copy()->addHour() : null,
                    ]);

                    OrderItem::create([
                        'order_id' => $order->getKey(),
                        'product_name' => $products[array_rand($products)],
                        'quantity' => random_int(1, 3),
                        'unit_price_cents' => $subtotal,
                        'total_cents' => $subtotal,
                    ]);

                    $created++;
                }
            }

            $this->command->info("Pedidos criados: {$created}");
        });
    }
}
