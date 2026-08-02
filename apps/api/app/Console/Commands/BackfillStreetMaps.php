<?php

namespace App\Console\Commands;

use App\Jobs\RefreshStreetMap;
use App\Models\TenantSettings;
use Illuminate\Console\Command;

/**
 * Gera o mapa da vizinhança das lojas que ainda não têm um.
 *
 * O traçado passou a ser gravado no banco depois que as lojas já existiam, e o
 * job só dispara quando o lojista salva o endereço — sem este comando, uma loja
 * que nunca mais editar o perfil ficaria para sempre sem mapa.
 *
 * Serve também como conserto: as falhas do Overpass (429 e 504 são rotina)
 * deixam `street_map` nulo, e rodar isto de novo mais tarde retoma só o que
 * faltou.
 */
class BackfillStreetMaps extends Command
{
    protected $signature = 'maps:backfill
        {--force : Refaz também as lojas que já têm mapa}';

    protected $description = 'Enfileira o desenho do mapa das lojas com endereço cadastrado';

    public function handle(): int
    {
        $query = TenantSettings::withoutGlobalScopes()
            ->whereNotNull('address')
            ->where('address', '!=', '');

        if (! $this->option('force')) {
            // Sem `--force`, refazer o que já está pronto seria consumir a cota
            // de um serviço público de graça.
            $query->where(function ($q) {
                $q->whereNull('street_map')
                    ->orWhereColumn('street_map_address', '!=', 'address')
                    ->orWhereNull('street_map_address');
            });
        }

        $settings = $query->get(['tenant_id']);

        if ($settings->isEmpty()) {
            $this->info('Nenhuma loja precisa de mapa.');

            return self::SUCCESS;
        }

        foreach ($settings as $row) {
            RefreshStreetMap::dispatch($row->tenant_id);
        }

        $this->info("{$settings->count()} loja(s) enfileirada(s).");

        // O aviso importa: o Overpass limita por IP, e o operador precisa saber
        // que o resultado não aparece de imediato nem todo de uma vez.
        $this->line('O desenho é feito em fila e pode levar alguns minutos.');

        return self::SUCCESS;
    }
}
