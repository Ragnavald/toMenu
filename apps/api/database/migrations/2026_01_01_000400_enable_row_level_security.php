<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Camada 3 do isolamento: Row Level Security no Postgres.
 *
 * As camadas 1 (middleware) e 2 (global scope) vivem na aplicação e podem ser
 * contornadas por engano — `withoutGlobalScopes()`, um `DB::raw` mal escrito,
 * um join direto. Esta camada é aplicada pelo banco e não tem escapatória.
 *
 * FORCE é obrigatório: o dono da tabela ignora RLS por padrão no Postgres, e a
 * aplicação normalmente conecta justamente como dono. Sem FORCE, a policy existe
 * mas nunca é avaliada — falsa sensação de segurança.
 *
 * `app.tenant_id` é setado por request no TenantContext via SET LOCAL.
 * `nullif(...,'')` trata o caso em que a variável nunca foi definida (contexto
 * central, jobs, migrations), evitando erro de cast em string vazia.
 */
return new class extends Migration
{
    private const TABLES = [
        'tenant_settings', 'categories', 'products',
        'modifier_groups', 'modifiers',
        'customers', 'addresses',
        'orders', 'order_items', 'payments',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // RLS é específico do Postgres.
        }

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            DB::statement("
                CREATE POLICY tenant_isolation ON {$table}
                USING (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint
                    OR nullif(current_setting('app.tenant_id', true), '') IS NULL
                )
                WITH CHECK (
                    tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint
                    OR nullif(current_setting('app.tenant_id', true), '') IS NULL
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
