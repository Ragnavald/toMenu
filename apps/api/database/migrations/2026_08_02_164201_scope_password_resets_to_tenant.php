<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recorta a redefinição de senha por tenant.
 *
 * A tabela nasce do Laravel com `email` como CHAVE PRIMÁRIA, o que assume um
 * e-mail por instalação. Aqui não é o caso: `users` é único por
 * (tenant_id, email), então o mesmo endereço pode ser dono de uma pizzaria e
 * gerente de um sushi.
 *
 * Com a chave só no e-mail, o pedido de redefinição de uma loja sobrescreveria
 * o da outra: quem pedisse primeiro receberia um link já inválido, sem nenhuma
 * mensagem explicando o motivo. Pior, um token gerado no contexto de uma loja
 * seria aceito para trocar a senha da outra — o e-mail casa, e nada mais era
 * verificado.
 *
 * `tenant_id` é anulável de propósito: staff da plataforma (admin.to-menu.com)
 * não pertence a loja alguma. No Postgres, NULL não é igual a NULL para efeito
 * de chave primária, então a coluna entra no índice mas não impede múltiplos
 * registros centrais — o que é aceitável porque o e-mail central já é único
 * por índice parcial em `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('email')
                ->constrained()->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // A primária vira composta. Sem isto o `updateOrInsert` do broker casa
        // só pelo e-mail e sobrescreve o pedido da outra loja.
        DB::statement('ALTER TABLE password_reset_tokens DROP CONSTRAINT password_reset_tokens_pkey');
        DB::statement('ALTER TABLE password_reset_tokens ADD PRIMARY KEY (email, tenant_id)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE password_reset_tokens DROP CONSTRAINT password_reset_tokens_pkey');
        }

        Schema::table('password_reset_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE password_reset_tokens ADD PRIMARY KEY (email)');
        }
    }
};
