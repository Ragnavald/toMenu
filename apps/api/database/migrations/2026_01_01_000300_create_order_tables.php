<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('street');
            $table->string('number')->nullable();
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city');
            $table->string('state', 2);
            $table->string('zip', 12)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();

            // Sequencial POR TENANT: o restaurante espera "#42", não "#918273".
            $table->unsignedInteger('number');

            $table->string('status')->default('pending_payment');
            $table->string('fulfillment')->default('delivery'); // delivery|pickup
            $table->string('payment_method');
            $table->string('payment_status')->default('pending');

            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents');

            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->text('notes')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Produto pode ser deletado; o histórico do pedido não muda.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot: preço congelado no momento da compra.
            $table->string('product_name');
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('quantity');
            $table->jsonb('modifiers_snapshot')->nullable();
            $table->unsignedInteger('total_cents');
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('stripe');
            $table->string('provider_payment_id')->nullable()->index();
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('application_fee_cents')->default(0);
            $table->string('status');
            $table->jsonb('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
        });

        // Idempotência de webhooks. Sem tenant_id: eventos chegam fora de contexto.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event_id');
            $table->string('type');
            $table->timestamp('processed_at')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('customers');
    }
};
