<?php

namespace App\Jobs;

use App\Models\TenantSettings;
use App\Services\StreetMapBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Redesenha o mapa da vizinhança depois que o endereço da loja muda.
 *
 * Vai para a fila porque são duas chamadas a serviços públicos lentos
 * (Nominatim e Overpass, juntos até dezenas de segundos): no caminho síncrono
 * elas entrariam no tempo de resposta do "Salvar" do painel, fazendo o lojista
 * esperar por um trabalho que não muda o resultado do salvamento.
 *
 * Recebe o id, não o model — payload menor e estado sempre relido fresco.
 */
class RefreshStreetMap implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Espera longa entre tentativas, e não os segundos habituais.
     *
     * A falha típica aqui é 429 do Overpass — ou seja, "você pediu demais".
     * Repetir em 5s garantiria outro 429; recuar para minutos dá ao serviço o
     * tempo que ele está justamente pedindo. O lojista não está esperando por
     * isto, então a demora não custa nada a ele.
     *
     * @var int[]
     */
    public array $backoff = [60, 600, 1800];

    public function __construct(public int $tenantId) {}

    /**
     * Salvar o perfil grava vários campos de uma vez e o lojista costuma
     * corrigir o endereço em duas ou três tentativas seguidas. Sem isto, cada
     * gravação viraria uma consulta nova a um serviço que já responde com 429.
     */
    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public int $uniqueFor = 120;

    public function handle(StreetMapBuilder $builder): void
    {
        $settings = TenantSettings::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->first();

        if (! $settings) {
            return;
        }

        $address = $settings->address;
        $paths = $builder->build($address);

        /*
         * Só grava o traçado quando ele existe, mas sempre registra o endereço
         * processado.
         *
         * Se o Overpass falhou, manter o desenho anterior é melhor que apagá-lo
         * — o mapa antigo do mesmo endereço continua correto, e um serviço fora
         * do ar não deveria fazer a loja perder o mapa que já tinha. O que não
         * pode acontecer é o endereço registrado avançar sem o desenho
         * correspondente: por isso, quando o endereço mudou de fato e não veio
         * traçado novo, o antigo é descartado junto.
         */
        $addressChanged = $settings->street_map_address !== $address;

        if ($paths !== null) {
            $settings->street_map = $paths;
            $settings->street_map_address = $address;
        } elseif ($addressChanged) {
            $settings->street_map = null;
            $settings->street_map_address = $address;
        } else {
            // Mesmo endereço e falha temporária: não mexe em nada e deixa a
            // próxima tentativa resolver.
            return;
        }

        $settings->save();
    }
}
