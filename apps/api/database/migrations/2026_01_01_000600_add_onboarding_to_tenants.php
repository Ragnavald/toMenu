<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Passo atual do wizard. A loja só fica visível ao público depois
            // de concluído — publicar um cardápio vazio queima o cliente final.
            $table->unsignedTinyInteger('onboarding_step')->default(0)->after('status');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_step');
        });

        Schema::table('tenant_settings', function (Blueprint $table) {
            // Contato e endereço da loja, exibidos no storefront e usados nas
            // notificações. Ficam fora do jsonb por serem consultados direto.
            $table->string('phone')->nullable()->after('tenant_id');
            $table->string('whatsapp')->nullable()->after('phone');
            $table->text('address')->nullable()->after('whatsapp');
            $table->text('description')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['phone', 'whatsapp', 'address', 'description']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['onboarding_step', 'onboarding_completed_at']);
        });
    }
};
