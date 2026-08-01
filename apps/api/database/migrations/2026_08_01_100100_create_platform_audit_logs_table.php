<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de auditoria das ações do staff da plataforma.
 *
 * Existe por causa da purga: excluir permanentemente uma loja apaga o tenant e,
 * em cascata, pedidos, pagamentos e usuários. Depois disso não sobra nada no
 * banco que explique o que havia ali nem quem mandou apagar. Esta tabela é o
 * único registro que resta, e por isso é deliberadamente independente:
 *
 * - `tenant_id` é um inteiro solto, SEM foreign key. Uma FK com cascade levaria
 *   a própria auditoria junto na purga; uma FK com restrict impediria a purga.
 * - `actor_id` idem: o staff pode ser desligado e removido depois.
 * - Nome e slug da loja ficam desnormalizados aqui, porque a linha original
 *   deixa de existir.
 * - Sem RLS: é tabela central, consultada fora de contexto de tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->string('action'); // impersonate|suspend|reactivate|purge

            // Sem FK de propósito — ver o cabeçalho.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_email')->nullable();

            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('tenant_slug');
            $table->string('tenant_name')->nullable();

            // Contagens do que foi apagado, resultado do detach no Stripe,
            // objetos removidos do R2 e motivo informado pelo staff.
            $table->jsonb('context')->nullable();

            $table->string('ip', 45)->nullable();

            // Só created_at: linha de auditoria não é editável.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_slug', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * Primeira camada: tira UPDATE/DELETE de quem recebeu a permissão por
         * grant. Cobre o arranjo de desenvolvimento, em que as migrations rodam
         * como `tomenu` (o dono, via API_ADMIN no Makefile) e a aplicação
         * conecta como `tomenu_app`.
         *
         * Sozinha ela NÃO basta, e é importante saber por quê: em produção o
         * dono das tabelas é o próprio papel da aplicação — o `01-app-role.sql`
         * transfere o schema public para `tomenu_app` e as migrations rodam com
         * esse mesmo usuário. O dono não perde privilégio sobre a própria
         * tabela, então o REVOKE não o alcança. Daí a segunda camada abaixo.
         *
         * O alvo também não pode ser deduzido de `DB::getConfig('username')`:
         * revogar do usuário da conexão corrente acertaria o papel errado em
         * desenvolvimento.
         */
        $owner = DB::selectOne('SELECT tableowner FROM pg_tables WHERE tablename = ?', [
            'platform_audit_logs',
        ])?->tableowner;

        $grantees = DB::select("
            SELECT DISTINCT grantee
            FROM information_schema.role_table_grants
            WHERE table_name = 'platform_audit_logs'
              AND privilege_type IN ('UPDATE', 'DELETE')
              AND grantee <> ?
              AND grantee <> 'PUBLIC'
        ", [$owner ?? '']);

        foreach ($grantees as $row) {
            // Identificador não aceita placeholder; aspas duplas escapadas são
            // a forma correta de citá-lo com segurança.
            $quoted = '"'.str_replace('"', '""', $row->grantee).'"';

            DB::statement("REVOKE UPDATE, DELETE ON platform_audit_logs FROM {$quoted}");
        }

        /*
         * Segunda camada, e a que de fato sustenta a garantia: RLS com FORCE.
         *
         * FORCE é o detalhe decisivo — sem ele o dono da tabela ignora as
         * policies, e o dono é exatamente o papel da aplicação em produção. Com
         * FORCE, as policies valem para todo mundo sem BYPASSRLS, inclusive
         * para quem criou a tabela.
         *
         * Também cobre o caso que o REVOKE não alcança: o `ALTER DEFAULT
         * PRIVILEGES` do 01-app-role.sql concede as quatro permissões em toda
         * tabela nova, então um banco recriado do zero voltaria a conceder
         * UPDATE/DELETE aqui.
         *
         * O efeito é silencioso por desenho do Postgres: linha que nenhuma
         * policy alcança fica invisível à operação, então o DELETE afeta zero
         * linhas em vez de lançar erro. Quem lê o resultado de um delete
         * esperando exceção não vai encontrá-la — ver o teste
         * `test_auditoria_e_imutavel_no_banco`.
         */
        DB::statement('ALTER TABLE platform_audit_logs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE platform_audit_logs FORCE ROW LEVEL SECURITY');

        // Leitura e escrita liberadas: a tabela é central, sem tenant_id a
        // filtrar. O recorte por loja é feito na query do controller.
        DB::statement('CREATE POLICY audit_readable ON platform_audit_logs FOR SELECT USING (true)');
        DB::statement('CREATE POLICY audit_insertable ON platform_audit_logs FOR INSERT WITH CHECK (true)');

        // Nenhuma policy de UPDATE/DELETE: sem uma que permita, o Postgres
        // recusa a operação — é assim que o RLS funciona por padrão.
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
