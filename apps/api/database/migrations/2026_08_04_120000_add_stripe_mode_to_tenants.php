<?php

use App\Services\StripeMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De qual ambiente do Stripe são os ids guardados no tenant.
 *
 * O problema que isto resolve é concreto e já aconteceu em produção: trocar
 * `STRIPE_MODE` deixa `stripe_customer_id`, `stripe_subscription_id`,
 * `subscription_status` e `current_period_ends_at` apontando para objetos do
 * ambiente ANTERIOR. O Checkout então falha com "No such customer" — o id
 * existe no banco, mas não na conta que a chave ativa enxerga.
 *
 * A validação de prefixo do `StripeMode` não pega isso: ela confere a chave
 * (`sk_test_`/`sk_live_`), e ids de objeto (`cus_`, `sub_`) não carregam o
 * ambiente no nome. Só o banco sabe de onde vieram — daí a coluna.
 *
 * Guardar o modo ao lado dos ids, e não duplicar cada campo num par
 * test/live, é deliberado:
 *
 *   1. o webhook chega sem contexto de modo e não teria como escolher em qual
 *      coluna gravar;
 *   2. `subscription_status` duplicado faria um `.env` errado em produção
 *      mostrar TODA loja paga como não-assinante de uma vez. Com uma coluna
 *      só, o descasamento vira recriação de customer para quem clicar em
 *      assinar — e nunca perda de acesso de quem já paga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('stripe_mode', 8)->nullable()->after('stripe_customer_id');
        });

        /*
         * As linhas que já existem receberam seus ids antes desta coluna, num
         * ambiente que só o histórico do `.env` conhece. Assumir o modo ATIVO é
         * a leitura certa: quem já rodava em live continua em live e nada muda;
         * quem está em test e tem id de live vai ser tratado como coerente até
         * o primeiro erro — e é justamente o caso que o operador conserta
         * limpando o vínculo (ver `SubscriptionService::forgetStripeLinkage`).
         *
         * Deixar NULL seria pior: NULL significa "sem modo conhecido", e o
         * código trata isso como descasamento, o que faria toda loja paga em
         * produção recriar customer no próximo clique.
         */
        DB::table('tenants')
            ->whereNotNull('stripe_customer_id')
            ->update(['stripe_mode' => app(StripeMode::class)->current()]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('stripe_mode');
        });
    }
};
