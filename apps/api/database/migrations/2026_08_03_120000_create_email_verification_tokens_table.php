<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de confirmação de e-mail do lojista.
 *
 * Tabela própria em vez de reaproveitar `password_reset_tokens`: são dois
 * fluxos com validade, ciclo de vida e consequência diferentes, e compartilhar
 * a linha faria pedir a confirmação invalidar um link de senha em aberto (a
 * chave primária lá é (email, tenant_id), uma linha por conta).
 *
 * A chave é `user_id` e não o par (email, tenant): aqui a conta já existe e
 * tem id, o que dispensa reconstruir a identidade a partir do e-mail. Uma
 * linha por usuário, então pedir um reenvio substitui o token anterior — links
 * antigos deixam de valer, que é o comportamento desejado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_tokens', function (Blueprint $table) {
            // Primária, não apenas única: uma conta tem no máximo um token em
            // aberto, e o `updateOrInsert` do reenvio depende disso.
            $table->foreignId('user_id')->primary()
                ->constrained()->cascadeOnDelete();
            // Guardado com hash, como o de senha: um dump do banco não pode
            // permitir confirmar a conta alheia.
            $table->string('token');
            $table->timestamp('created_at');
        });

        /*
         * Contas que já existiam entram como confirmadas.
         *
         * O login passa a recusar `email_verified_at` nulo, e sem este
         * backfill o deploy trancaria fora do painel todo lojista já ativo —
         * inclusive quem está no meio do expediente — sem nenhum aviso e sem
         * link no e-mail para resolver. A confirmação vale para quem se
         * cadastrar a partir daqui.
         *
         * `now()` e não a data do cadastro: a coluna registra quando a
         * verificação aconteceu, e inventar um instante passado afirmaria uma
         * confirmação que ninguém fez. O que se está registrando é a decisão
         * desta migration, tomada agora.
         */
        DB::table('users')->whereNull('email_verified_at')->update([
            'email_verified_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');
    }
};
