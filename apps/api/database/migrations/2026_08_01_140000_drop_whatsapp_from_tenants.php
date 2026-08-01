<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove o canal WhatsApp: o alerta da cozinha passa a ser exclusivamente o
 * painel em tempo real (evento OrderReceived via Reverb), com som.
 *
 * O `merchant_phone` sai junto do delivery_config — ele só existia como destino
 * das mensagens. O telefone de contato exibido no cardápio é outro campo
 * (`tenant_settings.whatsapp`) e permanece.
 *
 * O down() não restaura os valores: `whatsapp_token` era encrypted e o conteúdo
 * se perde no drop. Recriar as colunas vazias é o máximo honesto aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Condicional porque a migration que criava as colunas também foi
        // limpa: num banco novo elas nunca existem, e um drop incondicional
        // derrubaria toda a suíte e qualquer instalação do zero.
        $columns = array_filter(
            ['whatsapp_phone_id', 'whatsapp_token'],
            fn (string $column) => Schema::hasColumn('tenants', $column),
        );

        if ($columns !== []) {
            Schema::table('tenants', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }

        // jsonb: remove a chave sem reescrever o resto da configuração.
        //
        // `jsonb_exists` em vez do operador `?`: o PDO lê a interrogação como
        // placeholder de bind e quebra a query com "syntax error at or near $1".
        DB::statement("
            UPDATE tenant_settings
            SET delivery_config = delivery_config - 'merchant_phone'
            WHERE jsonb_exists(delivery_config, 'merchant_phone')
        ");
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'whatsapp_phone_id')) {
                $table->string('whatsapp_phone_id')->nullable();
            }

            if (! Schema::hasColumn('tenants', 'whatsapp_token')) {
                $table->text('whatsapp_token')->nullable();
            }
        });
    }
};
