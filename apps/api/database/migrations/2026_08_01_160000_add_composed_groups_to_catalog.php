<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subcategorias compostas — pizza meio a meio e afins.
 *
 * O modelo original só sabia representar opção de lista fixa: o lojista
 * cadastrava "Margherita" e "Calabresa" à mão dentro do grupo, com um preço
 * digitado ali. Para sabor de pizza isso duplica o cardápio — o mesmo sabor
 * já existe como produto, com foto, descrição e preço próprios — e as duas
 * cópias divergem no primeiro reajuste.
 *
 * Aqui o grupo passa a poder *apontar* para uma categoria: as opções são os
 * produtos daquela categoria, lidos na hora. Cadastrar um sabor novo na
 * categoria "Sabores de Pizza" o faz aparecer em todos os tamanhos, sem
 * nenhum passo extra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            /*
             * Categoria-insumo: existe para abastecer grupos compostos, não
             * para ser vendida direto.
             *
             * Sem isto, "Sabores de Pizza" apareceria como uma seção normal do
             * cardápio logo abaixo de "Pizzas" — o cliente veria cada sabor
             * duas vezes e poderia pedir meio sabor solto.
             */
            $table->boolean('is_option_only')->default(false);
        });

        Schema::table('modifier_groups', function (Blueprint $table) {
            /*
             * De onde saem as opções deste grupo.
             *
             * 'list'     — modifiers cadastrados no próprio grupo (borda, adicional).
             * 'category' — produtos da categoria apontada (sabores).
             *
             * Default 'list' mantém idêntico o comportamento de todo grupo que
             * já existe: nada precisa ser migrado.
             */
            $table->string('source')->default('list');

            // Só usado quando source = 'category'. nullOnDelete e não cascade:
            // apagar a categoria de sabores não deve levar junto o grupo
            // "Sabores" — o lojista perderia a configuração de min/max e a
            // vinculação com os produtos de tamanho.
            $table->foreignId('source_category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            /*
             * Como precificar quando o cliente escolhe mais de um item.
             *
             * 'sum'     — soma os deltas. Regra de adicional; é o que os grupos
             *             de lista sempre fizeram, então é o default.
             * 'highest' — cobra o mais caro dos escolhidos. Meio a meio de dois
             *             sabores de preços diferentes sai pelo maior.
             * 'average' — média dos escolhidos, arredondada. Modelo de parte das
             *             pizzarias tradicionais.
             */
            $table->string('pricing_rule')->default('sum');
        });

        /*
         * Um produto pode ser ofertado como opção composta com preço diferente
         * do preço de venda avulsa: a Margherita custa R$59 sozinha, mas como
         * sabor de uma pizza família entra por outro valor. Sem esta tabela o
         * lojista teria que escolher entre um preço e outro.
         *
         * A linha é opcional — sem override, vale o preço efetivo do produto.
         */
        Schema::create('modifier_group_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price_cents')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['modifier_group_id', 'product_id'], 'mgp_unique');
            $table->index(['tenant_id', 'modifier_group_id']);
        });

        /*
         * A tabela nasce sob RLS como todas as outras que carregam tenant_id.
         * Criar tabela nova com tenant_id sem policy é o modo silencioso de
         * furar o isolamento: as camadas de aplicação continuam parecendo
         * corretas e só o banco revelaria o vazamento — tarde demais.
         *
         * Policy idêntica à de 2026_01_01_000400; ver aquele arquivo para o
         * porquê do FORCE e do nullif.
         */
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE modifier_group_product ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE modifier_group_product FORCE ROW LEVEL SECURITY');

            DB::statement("
                CREATE POLICY tenant_isolation ON modifier_group_product
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
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON modifier_group_product');
        }

        Schema::dropIfExists('modifier_group_product');

        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_category_id');
            $table->dropColumn(['source', 'pricing_rule']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_option_only');
        });
    }
};
