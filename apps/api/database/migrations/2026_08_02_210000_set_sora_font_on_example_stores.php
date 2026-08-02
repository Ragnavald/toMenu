<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Troca a fonte das lojas de exemplo para Sora.
 *
 * Mudar o seeder não basta: ExampleStoresSeeder é idempotente e pula a loja
 * cujo slug já existe ("mantida como está"), justamente para não sobrescrever
 * em produção uma loja já publicada. Como as duas lojas de exemplo já estão no
 * ar, o valor novo do seeder só valeria num banco criado do zero — ou seja,
 * nunca em produção.
 *
 * Por isso a atualização acontece aqui, no dado existente.
 *
 * Restrita aos dois slugs de exemplo: é uma decisão sobre a vitrine da
 * plataforma, não sobre a identidade visual dos clientes. Um UPDATE sem WHERE
 * reescreveria a marca de toda loja pagante.
 */
return new class extends Migration
{
    /** Os mesmos slugs de ExampleStoresSeeder::SLUGS. */
    private const SLUGS = ['forno-di-napoli', 'grao-e-folha'];

    public function up(): void
    {
        $this->setFont('sora');
    }

    /**
     * Devolve a pizzaria à Playfair.
     *
     * A cafeteria já usava Sora antes desta migration, então o down() a deixa
     * como está — reverter os dois para o mesmo valor inventaria um estado que
     * nunca existiu.
     */
    public function down(): void
    {
        DB::table('tenant_settings')
            ->whereIn('tenant_id', $this->exampleTenantIds(['forno-di-napoli']))
            ->update([
                'theme' => DB::raw("jsonb_set(theme, '{font}', '\"playfair\"')"),
                'updated_at' => now(),
            ]);
    }

    private function setFont(string $font): void
    {
        $ids = $this->exampleTenantIds(self::SLUGS);

        if ($ids === []) {
            return;
        }

        // jsonb_set troca só a chave `font` e preserva cores, raio e layout.
        // Reescrever o objeto inteiro apagaria qualquer ajuste que a loja de
        // exemplo tenha recebido depois do seed.
        DB::table('tenant_settings')
            ->whereIn('tenant_id', $ids)
            ->update([
                'theme' => DB::raw("jsonb_set(theme, '{font}', '\"{$font}\"')"),
                'updated_at' => now(),
            ]);
    }

    /**
     * Ids dos tenants de exemplo.
     *
     * Sem o global scope de tenancy: migration roda fora de qualquer contexto
     * de loja, e o scope descartaria todas as linhas.
     *
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function exampleTenantIds(array $slugs): array
    {
        return DB::table('tenants')
            ->whereIn('slug', $slugs)
            ->pluck('id')
            ->all();
    }
};
