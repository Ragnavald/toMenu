<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Traçado das ruas ao redor do endereço da loja, para o mapa do perfil.
 *
 * Dois serviços públicos do ecossistema OpenStreetMap em sequência: o Nominatim
 * converte o endereço em coordenadas, o Overpass devolve as vias ao redor. Os
 * dados são ODbL — livres para uso comercial mediante atribuição, que o
 * storefront exibe junto ao mapa.
 *
 * Por que não tiles prontos: os estilos sóbrios de prateleira (CARTO light_all,
 * Stadia Alidade) exigem licença comercial, e os tiles gratuitos do OSM trazem
 * ícones de ponto de interesse e vias coloridas que competiam com a identidade
 * visual de cada loja. Desenhando a partir do dado bruto, o storefront controla
 * exatamente o que aparece e pinta o pino com a cor da marca do lojista.
 *
 * Roda em fila, nunca no caminho de uma requisição: medido daqui, o Overpass
 * respondeu entre 3s e 10s e devolveu 429 ou 504 em cerca de metade das
 * tentativas.
 */
class StreetMapBuilder
{
    /** Só as vias que formam o desenho reconhecível de um bairro. */
    private const HIGHWAYS = 'motorway|trunk|primary|secondary|tertiary'
        .'|residential|unclassified|living_street|pedestrian';

    /**
     * Raio consultado. 420m cobre o quarteirão e os arredores — o bastante para
     * reconhecer a esquina sem sugerir precisão de GPS.
     */
    private const RADIUS_M = 420;

    /** Lado do quadrado de desenho, em unidades de viewBox do SVG. */
    public const MAP_SIZE = 560;

    /**
     * Extensão em graus de longitude coberta pelo desenho.
     *
     * Casa com o raio acima: ~0.0075° ≈ 830m de largura no Brasil, com margem
     * para as vias não terminarem cortadas exatamente na borda.
     */
    private const SPAN_DEG = 0.0075;

    /**
     * Piso de vias para o desenho valer a pena.
     *
     * Meia dúzia de riscos soltos não lê como mapa, lê como defeito. Abaixo
     * disso é melhor o perfil mostrar só o cartão de endereço.
     */
    private const MIN_WAYS = 6;

    /** Espessura por classe de via — a hierarquia é o que torna o desenho legível. */
    private const STROKE_BY_CLASS = [
        'motorway' => 3.4,
        'trunk' => 3.4,
        'primary' => 2.8,
        'secondary' => 2.2,
        'tertiary' => 1.8,
    ];

    private const DEFAULT_STROKE = 1.1;

    /**
     * Identificação exigida pelas políticas de uso do Nominatim e do Overpass:
     * ambos recusam ou limitam clientes sem User-Agent que permita contato.
     */
    private const USER_AGENT = 'ToMenu/1.0 (+https://to-menu.com)';

    /**
     * Traçado pronto para desenhar, ou `null` quando não há mapa possível.
     *
     * `null` não é erro: endereço vazio, endereço que o Nominatim não reconhece,
     * região sem vias mapeadas e serviço fora do ar caem todos aqui, e em todos
     * o perfil da loja simplesmente não mostra mapa.
     *
     * @return array<int, array{d: string, width: float}>|null
     */
    public function build(?string $address): ?array
    {
        if (blank($address)) {
            return null;
        }

        $point = $this->geocode($address);

        if (! $point) {
            return null;
        }

        $ways = $this->fetchWays($point['lat'], $point['lng']);

        if ($ways === null) {
            return null;
        }

        $paths = $this->project($ways, $point['lat'], $point['lng']);

        return count($paths) >= self::MIN_WAYS ? $paths : null;
    }

    /** @return array{lat: float, lng: float}|null */
    private function geocode(string $address): ?array
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->acceptJson()
                ->timeout(15)
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $address,
                    'format' => 'jsonv2',
                    'limit' => 1,
                    // O produto é brasileiro; restringir evita que "Rua
                    // Oratório, 344" case com uma via homônima em Portugal.
                    'countrycodes' => 'br',
                ]);

            $first = $response->successful() ? $response->json(0) : null;

            if (! isset($first['lat'], $first['lon'])) {
                return null;
            }

            return ['lat' => (float) $first['lat'], 'lng' => (float) $first['lon']];
        } catch (\Throwable $e) {
            Log::info('Geocodificação falhou para o mapa da loja.', [
                'erro' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Vias ao redor do ponto, como devolvidas pelo Overpass.
     *
     * O timeout é folgado porque isto roda em fila: o custo de esperar é de um
     * worker, não de um lojista olhando para um formulário travado. Apertá-lo
     * só trocaria "demora" por "mapa que nunca aparece".
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchWays(float $lat, float $lng): ?array
    {
        $radius = self::RADIUS_M;
        $highways = self::HIGHWAYS;

        $query = '[out:json][timeout:25];'
            ."way(around:{$radius},{$lat},{$lng})[highway~\"^({$highways})$\"];"
            .'out geom;';

        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->acceptJson()
                ->timeout(40)
                ->asForm()
                ->post('https://overpass-api.de/api/interpreter', ['data' => $query]);

            if (! $response->successful()) {
                // 429 e 504 são rotina neste serviço; o job tenta de novo mais
                // tarde, e enquanto isso a loja fica sem mapa.
                Log::info('Overpass indisponível para o mapa da loja.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json('elements') ?? [];
        } catch (\Throwable $e) {
            Log::info('Consulta ao Overpass falhou.', ['erro' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Projeta lat/lng em coordenadas de tela e monta o `d` de cada `<path>`.
     *
     * Equirretangular com correção de cosseno na latitude, e não Mercator: num
     * quadrado de menos de 1km a diferença entre as duas projeções é muito
     * menor que a espessura de um traço. O cosseno, esse sim, é indispensável —
     * sem ele o bairro apareceria esticado na horizontal.
     *
     * @param  array<int, array<string, mixed>>  $ways
     * @return array<int, array{d: string, width: float}>
     */
    private function project(array $ways, float $centerLat, float $centerLng): array
    {
        $latSpan = self::SPAN_DEG * cos(deg2rad($centerLat));
        $half = self::MAP_SIZE / 2;
        $paths = [];

        foreach ($ways as $way) {
            $points = $way['geometry'] ?? null;

            if (! is_array($points) || count($points) < 2) {
                continue;
            }

            $commands = [];

            foreach ($points as $index => $point) {
                $x = ($point['lon'] - $centerLng) / self::SPAN_DEG * self::MAP_SIZE + $half;
                // Y cresce para baixo na tela e para cima na latitude: daí o sinal.
                $y = -($point['lat'] - $centerLat) / $latSpan * self::MAP_SIZE + $half;

                $commands[] = ($index === 0 ? 'M' : 'L')
                    .number_format($x, 1, '.', '')
                    .' '
                    .number_format($y, 1, '.', '');
            }

            $paths[] = [
                'd' => implode('', $commands),
                'width' => self::STROKE_BY_CLASS[$way['tags']['highway'] ?? ''] ?? self::DEFAULT_STROKE,
            ];
        }

        return $paths;
    }
}
