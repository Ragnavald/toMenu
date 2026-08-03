<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\TenantAssetPurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Purga das imagens da loja no object storage.
 *
 * O disco é falso aqui, mas o contrato testado é o mesmo do R2: os uploads de
 * todas as lojas convivem nos prefixos `products/` e `logos/`, sem diretório
 * por tenant, e o único vínculo entre um objeto e a loja dele é o id no começo
 * do nome do arquivo.
 */
class TenantAssetPurgerTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(int $id): Tenant
    {
        $plan = Plan::firstOrCreate(['slug' => 'pro'], [
            'name' => 'Pro',
            'price_cents' => 9900,
            'max_products' => 500,
            'allows_online_payment' => true,
        ]);

        $tenant = Tenant::create([
            'name' => "Loja {$id}",
            'slug' => "loja-{$id}",
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        // O id é o que o purger usa para casar os arquivos; forçá-lo torna o
        // teste independente da sequência do banco.
        $tenant->forceFill(['id' => $id])->save();

        return $tenant;
    }

    public function test_apaga_as_imagens_da_loja(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        Storage::disk('public')->put('products/7-111-abc.jpg', 'x');
        Storage::disk('public')->put('products/7-222-def.jpg', 'x');
        Storage::disk('public')->put('logos/7-logo-333.png', 'x');

        $outcome = app(TenantAssetPurger::class)->purge($this->makeTenant(7));

        $this->assertSame(3, $outcome['deleted']);
        $this->assertSame(0, $outcome['failed']);

        Storage::disk('public')->assertMissing('products/7-111-abc.jpg');
        Storage::disk('public')->assertMissing('products/7-222-def.jpg');
        Storage::disk('public')->assertMissing('logos/7-logo-333.png');
    }

    /**
     * O caso que justifica o separador no filtro.
     *
     * Casar por `str_starts_with($base, '7')` pegaria os arquivos das lojas 70
     * e 71 junto. Como a purga é irreversível e roda sem confirmação por
     * arquivo, isso apagaria a imagem de uma loja alheia sem deixar rastro —
     * exatamente o tipo de perda de dados que ninguém notaria até o cardápio de
     * outro cliente aparecer quebrado.
     */
    public function test_nao_toca_em_lojas_com_id_de_prefixo_parecido(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        Storage::disk('public')->put('products/7-111-abc.jpg', 'x');
        Storage::disk('public')->put('products/70-999-zzz.jpg', 'x');
        Storage::disk('public')->put('products/71-999-www.jpg', 'x');
        Storage::disk('public')->put('logos/70-logo-333.png', 'x');

        $outcome = app(TenantAssetPurger::class)->purge($this->makeTenant(7));

        $this->assertSame(1, $outcome['deleted']);

        Storage::disk('public')->assertMissing('products/7-111-abc.jpg');
        Storage::disk('public')->assertExists('products/70-999-zzz.jpg');
        Storage::disk('public')->assertExists('products/71-999-www.jpg');
        Storage::disk('public')->assertExists('logos/70-logo-333.png');
    }

    /** Loja que nunca subiu imagem: caso comum, e não é erro. */
    public function test_loja_sem_imagens_nao_falha(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $outcome = app(TenantAssetPurger::class)->purge($this->makeTenant(7));

        $this->assertSame(0, $outcome['deleted']);
        $this->assertSame(0, $outcome['failed']);
        $this->assertSame('none', $outcome['reports']);
    }

    /**
     * Os PDFs de relatório ficam noutro disco e noutra convenção.
     *
     * São resíduo da época em que a exportação era PDF gerado em fila: nada
     * mais grava ali, mas os arquivos antigos continuam no volume e
     * sobreviveriam a uma exclusão anunciada como permanente.
     */
    public function test_apaga_os_relatorios_da_loja(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        Storage::disk('local')->put('reports/7/financeiro-1.pdf', 'x');
        Storage::disk('local')->put('reports/7/financeiro-2.pdf', 'x');

        $outcome = app(TenantAssetPurger::class)->purge($this->makeTenant(7));

        $this->assertSame('deleted', $outcome['reports']);

        Storage::disk('local')->assertMissing('reports/7/financeiro-1.pdf');
        Storage::disk('local')->assertMissing('reports/7/financeiro-2.pdf');
    }

    /**
     * O equivalente, para relatórios, do teste de id com prefixo parecido.
     *
     * Aqui o diretório por tenant já protege — `reports/7` é comparado inteiro
     * pelo storage, não por `str_starts_with` —, mas o caso fica registrado
     * porque é a suposição que sustenta o uso de `deleteDirectory`: se a
     * convenção de caminho mudar, este teste é que denuncia.
     */
    public function test_nao_apaga_relatorios_de_loja_com_id_parecido(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        Storage::disk('local')->put('reports/7/financeiro-1.pdf', 'x');
        Storage::disk('local')->put('reports/70/financeiro-9.pdf', 'x');
        Storage::disk('local')->put('reports/71/financeiro-9.pdf', 'x');

        app(TenantAssetPurger::class)->purge($this->makeTenant(7));

        Storage::disk('local')->assertMissing('reports/7/financeiro-1.pdf');
        Storage::disk('local')->assertExists('reports/70/financeiro-9.pdf');
        Storage::disk('local')->assertExists('reports/71/financeiro-9.pdf');
    }
}
