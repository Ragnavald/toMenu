<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fim de linha operacional do pedido, separado do status.
 *
 * O painel mostra os pedidos do movimento corrente, e até aqui um pedido
 * entregue ficava na coluna "Entregues" para sempre: no fim de um sábado a
 * coluna tem dezenas de cards que a cozinha já não olha, empurrando para baixo
 * os que ainda importam. Faltava a operação poder dizer "este acabou".
 *
 * Isso é uma coluna nova e não um status `archived` porque as duas coisas
 * respondem perguntas diferentes. `status` é o que aconteceu com o pedido, e o
 * financeiro soma a receita justamente por `status = delivered` (ver
 * FinanceReportService::REVENUE_STATUS) — mover o pedido para outro status ao
 * arquivar apagaria a venda do relatório, que é o oposto do que o lojista
 * espera ao limpar a tela. `archived_at` diz apenas que ninguém mais precisa
 * ver aquele card, e o histórico continua lendo a mesma linha.
 *
 * Nullable com data em vez de booleano porque a aba de histórico filtra por
 * período, e "quando saiu do painel" é uma informação que só existe aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('delivered_at');

            /*
             * O índice é composto e não simples porque toda consulta do painel
             * pergunta as duas coisas juntas: os pedidos deste tenant que ainda
             * não foram arquivados. Sozinha, a coluna teria seletividade baixa
             * demais para valer o índice — com o tempo a maioria das linhas
             * fica arquivada.
             */
            $table->index(['tenant_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
