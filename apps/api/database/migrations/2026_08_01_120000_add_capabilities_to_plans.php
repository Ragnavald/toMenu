<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Capacidades por plano.
 *
 * Até aqui só existia um plano e a única capacidade modelada era o pagamento
 * online. O plano "Cardápio digital" corta uma fatia bem maior do produto —
 * pedidos, entrega e o painel de operação — e cada corte precisa ser um dado,
 * não um `if ($plan->slug === 'cardapio')` espalhado pelo código: um terceiro
 * plano no futuro deve ser uma linha na tabela, não uma varredura por
 * comparações de slug.
 *
 * O default `true` é o que mantém a migração aditiva: toda loja existente
 * continua exatamente com o que tinha, e só o plano novo nasce restrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('allows_orders')->default(true)->after('max_products');
            $table->boolean('allows_delivery')->default(true)->after('allows_orders');
            $table->unsignedInteger('sort_order')->default(0);
        });

        // O Pro é o plano de todas as lojas que já existem; deixá-lo explícito
        // aqui evita depender apenas do default da coluna.
        DB::table('plans')->where('slug', 'pro')->update([
            'allows_orders' => true,
            'allows_delivery' => true,
            'sort_order' => 2,
        ]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['allows_orders', 'allows_delivery', 'sort_order']);
        });
    }
};
