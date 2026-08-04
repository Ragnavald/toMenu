<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice em `order_items.order_id` para a busca do painel de pedidos.
 *
 * A tabela JÁ TEM um índice `(tenant_id, order_id)`, criado com ela. Ele não
 * serve aqui, e o motivo é a regra do prefixo: o Postgres só aproveita um
 * índice composto a partir da PRIMEIRA coluna. O `whereHas('items')` do
 * OrderAdminController vira um EXISTS correlacionado que filtra apenas por
 * `order_id` — sem mencionar `tenant_id`, o índice composto fica inelegível e
 * o planejador cai em varredura sequencial.
 *
 * Medido num banco com 120 lojas, 360 mil pedidos e 720 mil itens:
 *
 *   - só o EXISTS de itens:   251 ms (Parallel Seq Scan, 64.916 linhas)
 *                          →   12 ms (Index Scan)          — 20x
 *   - busca completa da tela:  718 ms → 254 ms             — 2,8x
 *
 * Os 254 ms restantes são do `LIKE '%termo%'` sobre `customers`. Testei GIN
 * com pg_trgm nas três colunas de texto e o resultado PIOROU (254 → 364 ms):
 * o planejador troca por um caminho mais caro, e ainda se pagaria escrita e
 * disco por isso. Ficou de fora deliberadamente.
 *
 * O ganho não aparece com pouco volume: numa loja de dezenas de pedidos o Seq
 * Scan é mais barato e o planejador ignora o índice. Ele passa a valer quando
 * as lojas acumulam histórico — que é exatamente quando ninguém quer descobrir
 * isso.
 */
return new class extends Migration
{
    /**
     * PROPRIEDADE, não método: o Migrator lê `$migration->withinTransaction`
     * como atributo (Migrator.php, ~linha 449). Um método com este nome não
     * sobrescreve nada — a propriedade `true` herdada de Migration continua
     * valendo, a transação é aberta, e o CONCURRENTLY morre com
     * "cannot run inside a transaction block".
     *
     * Como é atributo, é resolvido na construção da classe e não pode depender
     * do ambiente. Por isso a transação fica desligada SEMPRE, e é o `up()` que
     * decide usar CONCURRENTLY ou não.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // CONCURRENTLY porque a tabela está em uso: a criação comum toma um
        // lock que segura toda escrita em order_items até terminar, e um pedido
        // novo no meio do expediente ficaria pendurado esperando o índice.
        //
        // Nos testes o CONCURRENTLY é dispensável (tabela vazia, sem escrita
        // concorrente) e indesejável: ele não roda dentro da transação do
        // RefreshDatabase.
        $concurrently = app()->environment('testing') ? '' : 'CONCURRENTLY';

        DB::statement(
            "CREATE INDEX {$concurrently} IF NOT EXISTS order_items_order_id_index
             ON order_items (order_id)"
        );
    }

    public function down(): void
    {
        $concurrently = app()->environment('testing') ? '' : 'CONCURRENTLY';

        DB::statement("DROP INDEX {$concurrently} IF EXISTS order_items_order_id_index");
    }
};
