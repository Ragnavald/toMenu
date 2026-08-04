<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL = staff da plataforma (contexto central), não pertence a tenant.
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner')->after('password');
        });

        // O unique global de email impediria o mesmo email em dois tenants.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
        });

        // Unicidade por tenant. Usuários centrais (tenant_id NULL) ficam de fora
        // deste índice, então recebem um índice parcial próprio.
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['tenant_id', 'email']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                CREATE UNIQUE INDEX users_central_email_unique
                ON users (email) WHERE tenant_id IS NULL
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_central_email_unique');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn('role');
            $table->unique('email');
        });
    }
};
