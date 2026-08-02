<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prova de que o lojista aceitou os Termos e a Privacidade ao criar a loja.
 *
 * O aceite é registrado no tenant, e não no usuário, porque quem contrata a
 * plataforma é a loja: o dono pode ser trocado, e a linha do tenant sobrevive
 * a isso. Também sobrevive ao soft delete, que é justamente quando o registro
 * importa — a defesa de "eu nunca aceitei nada" costuma vir depois do
 * cancelamento.
 *
 * Três colunas em vez de um booleano. A LGPD (art. 8º, §1º) exige que o
 * controlador demonstre o consentimento, e "aceitou" sem dizer *o quê* não
 * demonstra nada: quando a próxima versão dos termos entrar no ar, só a versão
 * gravada aqui distingue quem aceitou o texto novo de quem aceitou o antigo.
 *
 * O IP fica nullable de propósito. É evidência circunstancial, não a essência
 * do aceite, e um proxy mal configurado que devolva um IP vazio não pode
 * impedir alguém de criar a conta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('status');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');
            $table->string('terms_accepted_ip', 45)->nullable()->after('terms_version');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'terms_accepted_at',
                'terms_version',
                'terms_accepted_ip',
            ]);
        });
    }
};
