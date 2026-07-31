<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Recusa rodar fora do banco de teste.
     *
     * O RefreshDatabase derruba e recria todas as tabelas. Quando as variáveis
     * DB_* do ambiente venciam o phpunit.xml, a suíte apontava para o banco de
     * desenvolvimento e apagava os dados locais a cada `artisan test` — sem
     * aviso nenhum, porque do ponto de vista do Laravel estava tudo certo.
     *
     * A configuração já foi corrigida (phpunit.xml usa force="true"), mas isto
     * fica como rede de proteção: se alguém reintroduzir a variável errada, a
     * suíte falha na hora em vez de destruir o banco.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();

        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException(
                "A suíte está conectada ao banco \"{$database}\", que não é um banco de teste. "
                .'Abortando antes que o RefreshDatabase apague os dados. '
                .'Confira DB_DATABASE no ambiente — o phpunit.xml espera tomenu_test.'
            );
        }

        /*
         * O cache é Redis e sobrevive ao RefreshDatabase, que só reverte o
         * Postgres. O IdentifyTenant guarda slug -> id por uma hora: sem esta
         * limpeza, um teste herda o id de um tenant criado (e já revertido) por
         * outro, e a resolução do tenant devolve 404 dependendo da ORDEM em que
         * os testes rodaram. Isolado passa, na suíte falha.
         */
        Cache::flush();
    }
}
