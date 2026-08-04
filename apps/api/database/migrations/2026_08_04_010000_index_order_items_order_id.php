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
     * CONCURRENTLY não roda dentro de transação, e o Laravel envolve cada
     * migration numa por padrão ("CREATE INDEX CONCURRENTLY cannot run inside a
     * transaction block").
     *
     * Só que desligar a transação AQUI quebra a suíte: o RefreshDatabase
     * mantém cada teste dentro de uma transação que é revertida no fim, e uma
     * migration que sai desse encadeamento deixa estado sujo para o teste
     * seguinte. O sintoma não aponta para cá — quem falhava era o StreetMapTest,
     * e só quando a suíte inteira rodava junto.
     *
     * Por isso a transação é desligada apenas onde o CONCURRENTLY é usado.
     */
    public function withinTransaction(): bool
    {
        return ! $this->shouldRunConcurrently();
    }

    public function up(): void
    {
        // CONCURRENTLY porque a tabela está em uso: a criação comum toma um
        // lock que segura toda escrita em order_items até terminar, e um pedido
        // novo no meio do expediente ficaria pendurado esperando o índice.
        //
        // Nos testes a tabela está vazia e ninguém escreve em paralelo: o lock
        // é irrelevante e o índice comum roda dentro da transação, sem
        // contaminar o teste seguinte.
        $concurrently = $this->shouldRunConcurrently() ? 'CONCURRENTLY' : '';

        DB::statement(
            "CREATE INDEX {$concurrently} IF NOT EXISTS order_items_order_id_index
             ON order_items (order_id)"
        );
    }

    public function down(): void
    {
        $concurrently = $this->shouldRunConcurrently() ? 'CONCURRENTLY' : '';

        DB::statement("DROP INDEX {$concurrently} IF EXISTS order_items_order_id_index");
    }

    /**
     * Fora do ambiente de teste — onde a tabela tem tráfego real e o lock
     * custaria pedidos parados no meio do expediente.
     */
    private function shouldRunConcurrently(): bool
    {
        return ! app()->environment('testing');
    }
};
