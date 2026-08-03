<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove `report_jobs`, órfã depois que a exportação virou CSV em streaming.
 *
 * A tabela existia para acompanhar um trabalho assíncrono: o PDF era gerado na
 * fila, gravado em disco e baixado depois, então era preciso guardar status,
 * caminho do arquivo e tamanho. O CSV é escrito direto na resposta — não há
 * fila, nem arquivo, nem estado a consultar, e nada mais lê estas linhas.
 *
 * O histórico se perde com o drop, e é intencional: eram registros de arquivos
 * que já não existem (a retenção era de 7 dias) apontando para um formato que
 * não geramos mais. Preservá-los só deixaria a tela oferecendo downloads
 * quebrados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('report_jobs');
    }

    /**
     * Recria a estrutura da tabela, sem os dados.
     *
     * Cópia fiel da migration de criação, incluindo o RLS: um rollback que
     * devolvesse a tabela sem as policies reabriria o vazamento entre lojas
     * que a terceira camada de isolamento fecha.
     */
    public function down(): void
    {
        Schema::create('report_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('financial');
            $table->string('status')->default('queued');
            $table->date('from_date');
            $table->date('to_date');
            $table->string('search')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE report_jobs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE report_jobs FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON report_jobs
            USING (
                tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint
                OR nullif(current_setting('app.tenant_id', true), '') IS NULL
            )
            WITH CHECK (
                tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint
                OR nullif(current_setting('app.tenant_id', true), '') IS NULL
            )
        SQL);
    }
};
