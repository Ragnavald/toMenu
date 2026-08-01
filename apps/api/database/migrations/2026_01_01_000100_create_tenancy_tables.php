<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('max_products')->default(50);
            $table->boolean('allows_online_payment')->default(false);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('plan_id')->constrained();
            $table->string('status')->default('trial'); // trial|active|past_due|suspended
            $table->timestamp('trial_ends_at')->nullable();

            // Assinatura do tenant NA plataforma (você cobra dele).
            $table->string('stripe_customer_id')->nullable();

            // Conta Connect do tenant (ele recebe dos clientes finais).
            $table->string('stripe_account_id')->nullable();
            $table->boolean('stripe_charges_enabled')->default(false);

            // Versionamento de cache: bump invalida o cardápio sem race condition.
            $table->unsignedBigInteger('menu_version')->default(1);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->foreignId('tenant_id')->primary()->constrained()->cascadeOnDelete();
            $table->jsonb('theme')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('cover_url')->nullable();
            $table->jsonb('business_hours')->nullable();
            $table->jsonb('delivery_config')->nullable();
            $table->jsonb('payment_methods')->nullable();
            $table->boolean('is_open_override')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
    }
};
