<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motivo informado pelo lojista ao excluir a loja.
 *
 * Fica no tenant e não em log porque a linha sobrevive ao soft delete e é o
 * que o suporte consulta quando o lojista pede a conta de volta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('deletion_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('deletion_reason');
        });
    }
};
