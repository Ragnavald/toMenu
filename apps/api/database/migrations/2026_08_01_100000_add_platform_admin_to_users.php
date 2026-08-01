<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marca quem é staff da plataforma (o painel de admin.to-menu.com).
 *
 * `tenant_id IS NULL` já significava "não pertence a nenhuma loja", e o
 * EnsureUserBelongsToTenant trata esse usuário como capaz de operar qualquer
 * tenant. Isso é forte demais para ser implícito: qualquer linha criada sem
 * tenant_id — um seeder distraído, um import — herdaria acesso a todas as
 * lojas. A flag torna o privilégio explícito e auditável.
 *
 * A checagem passa a ser a conjunção das duas: sem tenant E com a flag. Ver
 * `User::isPlatformAdmin`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('role');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * Índice parcial: a listagem do painel filtra por esta coluna, e as
         * linhas verdadeiras são um punhado entre todas as contas de lojistas.
         * Um índice completo custaria espaço para indexar milhares de `false`.
         */
        DB::statement('
            CREATE INDEX users_platform_admin_idx
            ON users (id) WHERE is_platform_admin
        ');

        /*
         * Barra no banco a combinação sem sentido: staff de plataforma vinculado
         * a uma loja. Sem isto, um UPDATE que preencha tenant_id numa conta de
         * staff produziria um usuário que é dono de uma loja E administrador de
         * todas — escalada de privilégio silenciosa, invisível no código PHP.
         */
        DB::statement('
            ALTER TABLE users
            ADD CONSTRAINT users_platform_admin_has_no_tenant
            CHECK (NOT is_platform_admin OR tenant_id IS NULL)
        ');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_platform_admin_has_no_tenant');
            DB::statement('DROP INDEX IF EXISTS users_platform_admin_idx');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
    }
};
