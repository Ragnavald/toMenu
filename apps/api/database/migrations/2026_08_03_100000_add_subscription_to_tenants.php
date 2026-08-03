<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assinatura do lojista na plataforma.
 *
 * Até aqui a cobrança da mensalidade não existia: `tenants.stripe_customer_id`
 * já estava na tabela desde o início, mas nunca recebia valor — não havia
 * `customers.create` nem `subscriptions.create` em lugar nenhum do código. O
 * trial de 14 dias vencia e nada acontecia.
 *
 * O que falta para fechar o ciclo é guardar a assinatura em si, e não só o
 * cliente: sem o `stripe_subscription_id` não há como saber qual assinatura
 * pertence a qual loja quando o webhook chega, nem o que cancelar na exclusão.
 *
 * Cada plano ganha um preço no Stripe (`stripe_price_id`). Ele vive na tabela e
 * não em config porque o id difere entre os ambientes de teste e produção — o
 * mesmo motivo que fez as chaves virarem dois conjuntos. Ver
 * `PlanSeeder::definitions()`, que lê os ids do ambiente ativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Assinatura ativa no Stripe. Null = nunca assinou (trial puro).
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id');

            /*
             * Espelho do status da assinatura no Stripe (trialing, active,
             * past_due, canceled...). Separado de `tenants.status`, que é o
             * estado da loja na plataforma: uma loja pode estar `past_due` na
             * cobrança e continuar `active` no ar, e é exatamente essa a
             * política escolhida — a suspensão segue sendo decisão do staff.
             */
            $table->string('subscription_status')->nullable()->after('stripe_subscription_id');

            // Fim do período pago. Serve ao aviso no painel e evita cortar
            // quem pagou e ainda tem dias a usar.
            $table->timestamp('current_period_ends_at')->nullable()->after('subscription_status');

            // Índice para o webhook, que chega com o id da assinatura e nenhum
            // contexto de tenant.
            $table->index('stripe_subscription_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('stripe_price_id')->nullable()->after('price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropColumn([
                'stripe_subscription_id',
                'subscription_status',
                'current_period_ends_at',
            ]);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('stripe_price_id');
        });
    }
};
