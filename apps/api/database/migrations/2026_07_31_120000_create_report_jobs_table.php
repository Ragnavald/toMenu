<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type')->default('financial');
            $table->string('status')->default('queued'); // queued|processing|done|failed

            // O intervalo e os filtros que geraram o arquivo. Guardados para que
            // o histórico mostre o que cada PDF contém sem precisar reabri-lo.
            $table->date('from_date');
            $table->date('to_date');
            $table->string('search')->nullable();

            $table->string('file_path')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // O histórico é sempre lido por tenant, do mais recente ao mais antigo.
            $table->index(['tenant_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Mesma política das demais tabelas de tenant: sem isto a tabela nova
        // seria o único ponto do schema sem isolamento no nível do banco.
        DB::statement('ALTER TABLE report_jobs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE report_jobs FORCE ROW LEVEL SECURITY');
        DB::statement("
            CREATE POLICY tenant_isolation ON report_jobs
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

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON report_jobs');
        }

        Schema::dropIfExists('report_jobs');
    }
};
