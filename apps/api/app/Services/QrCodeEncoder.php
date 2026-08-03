<?php

namespace App\Services;

/**
 * Encoder de QR Code, do texto à matriz de módulos.
 *
 * Implementação própria em vez de dependência: o caso de uso aqui é único e
 * estreito — a URL de uma loja, sempre ASCII, sempre curta. Isso permite fixar
 * o modo byte, a correção de erro M e escolher a menor versão que couber, que é
 * o que mantém o PNG resultante pequeno. As bibliotecas de prateleira trazem
 * kanji, ECI, structured append e renderizadores que nada disso usaria.
 *
 * O que fica de fora, deliberadamente: modos numérico/alfanumérico (uma URL com
 * "https://" e o slug já cai em byte de qualquer jeito) e versões acima da 10
 * (uma URL de loja não passa de ~150 bytes; ver MAX_VERSION).
 *
 * Referência: ISO/IEC 18004. Os nomes dos passos abaixo seguem a norma para que
 * a comparação com ela seja direta.
 */
class QrCodeEncoder
{
    /**
     * Nível de correção de erro M (~15%).
     *
     * O equilíbrio certo para QR impresso e colado numa parede ou balcão: L
     * falha com pouca sujeira ou desgaste, Q e H incham a matriz — mais módulos
     * para o mesmo texto significa módulo menor no mesmo papel, e aí o celular
     * do cliente sofre para ler de longe.
     */
    private const EC_LEVEL_M = 0;

    /**
     * Teto de versão suportado.
     *
     * A versão 10 comporta 214 bytes em nível M — muito acima de qualquer URL
     * de loja (`https://` + slug + domínio raiz). O teto existe para que as
     * tabelas abaixo permaneçam pequenas e auditáveis; acima dele o encoder
     * falha alto em vez de gerar um código que ninguém vai conseguir ler.
     */
    private const MAX_VERSION = 10;

    /** Capacidade em bytes, no modo byte e correção M, por versão (índice = versão). */
    private const BYTE_CAPACITY_M = [
        1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
        6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 214,
    ];

    /**
     * Blocos de correção de erro por versão, em nível M.
     *
     * Formato: [códigos de EC por bloco, [[quantidade de blocos, dados por bloco], ...]].
     * As versões com dois grupos (7, 8, 9, 10) têm blocos de tamanhos
     * diferentes, e é justamente por isso que o entrelaçamento existe.
     */
    private const EC_BLOCKS_M = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /**
     * Centros dos padrões de alinhamento por versão.
     *
     * O produto cartesiano das coordenadas dá as posições; as três que colidem
     * com os localizadores (os cantos) são descartadas na hora de desenhar.
     */
    private const ALIGNMENT_CENTERS = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /**
     * Bits de versão pré-calculados (versões 7+).
     *
     * Abaixo da 7 o QR não carrega esse bloco. São 18 bits: 6 de versão e 12 de
     * correção BCH, constantes definidas pela norma.
     */
    private const VERSION_BITS = [
        7 => 0x07C94, 8 => 0x085BC, 9 => 0x09A99, 10 => 0x0A4D3,
    ];

    /** Log e antilog em GF(256), preenchidos uma vez por processo. */
    private static ?array $expTable = null;

    private static ?array $logTable = null;

    /**
     * Matriz booleana de módulos: `true` é módulo escuro.
     *
     * O retorno é a matriz nua, sem quiet zone — quem renderiza decide a
     * margem, que depende do meio (tela, papel, adesivo).
     *
     * @return array<int,array<int,bool>>
     *
     * @throws \InvalidArgumentException quando o texto não cabe em MAX_VERSION
     */
    public function encode(string $text): array
    {
        $version = $this->smallestVersionFor(strlen($text));
        $codewords = $this->buildCodewords($text, $version);

        return $this->buildMatrix($codewords, $version);
    }

    /** Menor versão cuja capacidade comporta o texto. */
    private function smallestVersionFor(int $length): int
    {
        foreach (self::BYTE_CAPACITY_M as $version => $capacity) {
            if ($length <= $capacity) {
                return $version;
            }
        }

        throw new \InvalidArgumentException(
            "Texto de {$length} bytes excede a capacidade da versão ".self::MAX_VERSION.'.'
        );
    }

    /**
     * Fluxo final de codewords: dados codificados, preenchidos e entrelaçados
     * com a correção de erro.
     *
     * @return array<int,int>
     */
    private function buildCodewords(string $text, int $version): array
    {
        [$ecPerBlock, $groups] = self::EC_BLOCKS_M[$version];

        $totalData = 0;
        foreach ($groups as [$count, $perBlock]) {
            $totalData += $count * $perBlock;
        }

        $bits = $this->encodeDataBits($text, $version, $totalData);

        // Divide o fluxo em blocos e calcula a correção de cada um.
        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;

        foreach ($groups as [$count, $perBlock]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($bits, $offset, $perBlock);
                $offset += $perBlock;

                $dataBlocks[] = $block;
                $ecBlocks[] = $this->reedSolomon($block, $ecPerBlock);
            }
        }

        return array_merge(
            $this->interleave($dataBlocks),
            $this->interleave($ecBlocks),
        );
    }

    /**
     * Codewords de dados: cabeçalho de modo, tamanho, conteúdo e preenchimento.
     *
     * @return array<int,int>
     */
    private function encodeDataBits(string $text, int $version, int $totalData): array
    {
        $length = strlen($text);

        // Modo byte (0100) + contador. O contador tem 8 bits até a versão 9 e
        // 16 a partir da 10 — a fronteira é definida pela norma.
        $bits = '0100';
        $bits .= str_pad(decbin($length), $version >= 10 ? 16 : 8, '0', STR_PAD_LEFT);

        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        $capacityBits = $totalData * 8;

        // Terminador: até quatro zeros, ou menos se o espaço acabar antes.
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));

        // Alinha em byte.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        $codewords = [];
        foreach (str_split($bits, 8) as $byte) {
            $codewords[] = bindec($byte);
        }

        // Preenchimento até encher a capacidade, alternando os dois bytes que a
        // norma fixa (0xEC, 0x11).
        $pad = [0xEC, 0x11];
        $i = 0;
        while (count($codewords) < $totalData) {
            $codewords[] = $pad[$i++ % 2];
        }

        return $codewords;
    }

    /**
     * Correção de erro Reed-Solomon sobre GF(256).
     *
     * @param  array<int,int>  $data
     * @return array<int,int>
     */
    private function reedSolomon(array $data, int $ecLength): array
    {
        $this->initGaloisTables();

        $generator = $this->generatorPolynomial($ecLength);
        $remainder = array_merge($data, array_fill(0, $ecLength, 0));

        for ($i = 0; $i < count($data); $i++) {
            $lead = $remainder[$i];

            if ($lead === 0) {
                continue;
            }

            $logLead = self::$logTable[$lead];

            for ($j = 0; $j <= $ecLength; $j++) {
                $remainder[$i + $j] ^= self::$expTable[($logLead + $generator[$j]) % 255];
            }
        }

        return array_slice($remainder, count($data), $ecLength);
    }

    /**
     * Polinômio gerador de grau `$degree`, com os coeficientes já em forma
     * logarítmica — é assim que o reedSolomon acima os consome.
     *
     * @return array<int,int>
     */
    private function generatorPolynomial(int $degree): array
    {
        $poly = [1];

        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);

            foreach ($poly as $index => $coefficient) {
                $next[$index] ^= $coefficient;

                if ($coefficient !== 0) {
                    $next[$index + 1] ^= self::$expTable[(self::$logTable[$coefficient] + $i) % 255];
                }
            }

            $poly = $next;
        }

        return array_map(fn (int $c) => self::$logTable[$c], $poly);
    }

    /** Tabelas de log/antilog do corpo finito, com o primitivo 0x11D da norma. */
    private function initGaloisTables(): void
    {
        if (self::$expTable !== null) {
            return;
        }

        self::$expTable = array_fill(0, 256, 0);
        self::$logTable = array_fill(0, 256, 0);

        $value = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$expTable[$i] = $value;
            self::$logTable[$value] = $i;

            $value <<= 1;
            if ($value >= 256) {
                $value ^= 0x11D;
            }
        }
    }

    /**
     * Entrelaça os blocos, tomando um codeword de cada por vez.
     *
     * Os blocos podem ter tamanhos diferentes (versões 8+ em nível M): os
     * curtos simplesmente não contribuem nas últimas rodadas. É esse
     * espalhamento que faz um arranhão localizado atingir poucos codewords de
     * cada bloco, em vez de destruir um bloco inteiro.
     *
     * @param  array<int,array<int,int>>  $blocks
     * @return array<int,int>
     */
    private function interleave(array $blocks): array
    {
        $longest = max(array_map('count', $blocks));
        $result = [];

        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * Desenha a matriz: padrões fixos, dados e a máscara de melhor pontuação.
     *
     * @param  array<int,int>  $codewords
     * @return array<int,array<int,bool>>
     */
    private function buildMatrix(array $codewords, int $version): array
    {
        $size = $version * 4 + 17;

        // `null` marca módulo livre. A distinção entre livre e "reservado pelo
        // padrão fixo" é o que impede os dados de sobrescrever localizador,
        // temporizador ou área de formato.
        $matrix = array_fill(0, $size, array_fill(0, $size, null));

        $this->drawFinders($matrix, $size);
        $this->drawTiming($matrix, $size);
        $this->drawAlignment($matrix, $version, $size);
        $this->reserveFormatAreas($matrix, $size, $version);

        // Módulo escuro fixo, exigido pela norma.
        $matrix[$size - 8][8] = true;

        $this->placeData($matrix, $codewords, $size);

        $mask = $this->bestMask($matrix, $size);

        $this->applyMask($matrix, $mask, $size);
        $this->drawFormatBits($matrix, $mask, $size);

        if ($version >= 7) {
            $this->drawVersionBits($matrix, $version, $size);
        }

        // Neste ponto todo módulo já foi decidido; o cast só troca o tipo.
        return array_map(
            fn (array $row) => array_map(fn (?bool $cell) => (bool) $cell, $row),
            $matrix,
        );
    }

    /** Os três localizadores dos cantos, com a faixa separadora ao redor. */
    private function drawFinders(array &$matrix, int $size): void
    {
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$row, $col]) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $y = $row + $r;
                    $x = $col + $c;

                    if ($y < 0 || $y >= $size || $x < 0 || $x >= $size) {
                        continue;
                    }

                    // Anel externo, centro cheio; o resto do quadrado é claro.
                    $onRing = ($r === 0 || $r === 6) && $c >= 0 && $c <= 6;
                    $onColumn = ($c === 0 || $c === 6) && $r >= 0 && $r <= 6;
                    $inCenter = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;

                    $matrix[$y][$x] = $onRing || $onColumn || $inCenter;
                }
            }
        }
    }

    /** Temporizadores: a linha e a coluna alternadas que dão a escala ao leitor. */
    private function drawTiming(array &$matrix, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $dark = $i % 2 === 0;

            $matrix[6][$i] = $dark;
            $matrix[$i][6] = $dark;
        }
    }

    /** Padrões de alinhamento, exceto onde colidiriam com os localizadores. */
    private function drawAlignment(array &$matrix, int $version, int $size): void
    {
        $centers = self::ALIGNMENT_CENTERS[$version];

        foreach ($centers as $row) {
            foreach ($centers as $col) {
                // Os três cantos já pertencem aos localizadores.
                $atFinder = ($row === 6 && $col === 6)
                    || ($row === 6 && $col === $size - 7)
                    || ($row === $size - 7 && $col === 6);

                if ($atFinder) {
                    continue;
                }

                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $matrix[$row + $r][$col + $c] = max(abs($r), abs($c)) !== 1;
                    }
                }
            }
        }
    }

    /**
     * Reserva as áreas de formato e de versão.
     *
     * `false` (e não `null`) porque o que importa aqui é ocupar o módulo: o
     * valor real entra depois de escolhida a máscara, e os dados precisam
     * enxergar estas posições como indisponíveis desde já.
     */
    private function reserveFormatAreas(array &$matrix, int $size, int $version): void
    {
        for ($i = 0; $i < 9; $i++) {
            if ($matrix[8][$i] === null) {
                $matrix[8][$i] = false;
            }
            if ($matrix[$i][8] === null) {
                $matrix[$i][8] = false;
            }
        }

        for ($i = 0; $i < 8; $i++) {
            $matrix[8][$size - 1 - $i] = $matrix[8][$size - 1 - $i] ?? false;
            $matrix[$size - 1 - $i][8] = $matrix[$size - 1 - $i][8] ?? false;
        }

        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $matrix[$size - 11 + $j][$i] = false;
                    $matrix[$i][$size - 11 + $j] = false;
                }
            }
        }
    }

    /**
     * Percorre a matriz em zigue-zague e grava os bits nos módulos livres.
     *
     * O caminho é o da norma: colunas de duas em duas, da direita para a
     * esquerda, subindo e descendo alternadamente, pulando a coluna 6 (o
     * temporizador vertical).
     *
     * @param  array<int,int>  $codewords
     */
    private function placeData(array &$matrix, array $codewords, int $size): void
    {
        $bits = '';
        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $index = 0;
        $upward = true;

        for ($right = $size - 1; $right > 0; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($step = 0; $step < $size; $step++) {
                $row = $upward ? $size - 1 - $step : $step;

                foreach ([$right, $right - 1] as $col) {
                    if ($matrix[$row][$col] !== null) {
                        continue;
                    }

                    // Bits podem acabar antes dos módulos livres; o resto fica
                    // claro, que é o comportamento previsto pela norma.
                    $matrix[$row][$col] = ($bits[$index] ?? '0') === '1';
                    $index++;
                }
            }

            $upward = ! $upward;
        }
    }

    /**
     * Escolhe a máscara de menor penalidade entre as oito.
     *
     * A pontuação existe para evitar matrizes com grandes áreas uniformes ou
     * com desenhos que imitem o localizador — ambos atrapalham o leitor.
     */
    private function bestMask(array $matrix, int $size): int
    {
        $best = 0;
        $bestScore = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $matrix;

            $this->applyMask($candidate, $mask, $size);
            $this->drawFormatBits($candidate, $mask, $size);

            $score = $this->penalty($candidate, $size);

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $mask;
            }
        }

        return $best;
    }

    /**
     * Inverte os módulos de dados conforme a fórmula da máscara.
     *
     * Só os módulos de dados entram: padrões fixos e áreas de formato ficam
     * intactos, e por isso o percurso repete a lógica de "posição reservada".
     */
    private function applyMask(array &$matrix, int $mask, int $size): void
    {
        $reserved = $this->reservedMap($size, $matrix);

        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($reserved[$row][$col]) {
                    continue;
                }

                if ($this->maskCondition($mask, $row, $col)) {
                    $matrix[$row][$col] = ! $matrix[$row][$col];
                }
            }
        }
    }

    /**
     * Mapa das posições que a máscara não pode tocar.
     *
     * Recalculado geometricamente (e não guardado durante o desenho) para que
     * `applyMask` continue válido sobre uma cópia da matriz — é o que permite
     * testar as oito máscaras sem reconstruir tudo.
     *
     * @return array<int,array<int,bool>>
     */
    private function reservedMap(int $size, array $matrix): array
    {
        $version = ($size - 17) / 4;
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // Localizadores e separadores, nos três cantos.
        foreach ([[0, 0], [$size - 8, 0], [0, $size - 8]] as [$row, $col]) {
            for ($r = 0; $r < 8; $r++) {
                for ($c = 0; $c < 8; $c++) {
                    $reserved[$row + $r][$col + $c] = true;
                }
            }
        }

        // Temporizadores.
        for ($i = 0; $i < $size; $i++) {
            $reserved[6][$i] = true;
            $reserved[$i][6] = true;
        }

        // Áreas de formato e o módulo escuro fixo.
        for ($i = 0; $i < 9; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }

        // Padrões de alinhamento.
        $centers = self::ALIGNMENT_CENTERS[$version];
        foreach ($centers as $row) {
            foreach ($centers as $col) {
                $atFinder = ($row === 6 && $col === 6)
                    || ($row === 6 && $col === $size - 7)
                    || ($row === $size - 7 && $col === 6);

                if ($atFinder) {
                    continue;
                }

                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $reserved[$row + $r][$col + $c] = true;
                    }
                }
            }
        }

        // Bloco de versão (7+).
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $reserved[$size - 11 + $j][$i] = true;
                    $reserved[$i][$size - 11 + $j] = true;
                }
            }
        }

        return $reserved;
    }

    /** As oito fórmulas de máscara da norma. */
    private function maskCondition(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
            6 => ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            7 => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
        };
    }

    /** Soma das quatro penalidades definidas pela norma. */
    private function penalty(array $matrix, int $size): int
    {
        return $this->penaltyRuns($matrix, $size)
            + $this->penaltyBlocks($matrix, $size)
            + $this->penaltyFinderLike($matrix, $size)
            + $this->penaltyBalance($matrix, $size);
    }

    /** N1: sequências de 5 ou mais módulos iguais, em linha ou coluna. */
    private function penaltyRuns(array $matrix, int $size): int
    {
        $score = 0;

        for ($i = 0; $i < $size; $i++) {
            foreach ([true, false] as $horizontal) {
                $run = 1;

                for ($j = 1; $j < $size; $j++) {
                    $current = $horizontal ? $matrix[$i][$j] : $matrix[$j][$i];
                    $previous = $horizontal ? $matrix[$i][$j - 1] : $matrix[$j - 1][$i];

                    if ($current === $previous) {
                        $run++;

                        continue;
                    }

                    if ($run >= 5) {
                        $score += $run - 2;
                    }

                    $run = 1;
                }

                if ($run >= 5) {
                    $score += $run - 2;
                }
            }
        }

        return $score;
    }

    /** N2: cada bloco 2x2 de módulos da mesma cor. */
    private function penaltyBlocks(array $matrix, int $size): int
    {
        $score = 0;

        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $value = $matrix[$row][$col];

                if ($value === $matrix[$row][$col + 1]
                    && $value === $matrix[$row + 1][$col]
                    && $value === $matrix[$row + 1][$col + 1]
                ) {
                    $score += 3;
                }
            }
        }

        return $score;
    }

    /** N3: sequências que imitam o localizador (1:1:3:1:1 com faixa clara). */
    private function penaltyFinderLike(array $matrix, int $size): int
    {
        $patterns = [
            [true, false, true, true, true, false, true, false, false, false, false],
            [false, false, false, false, true, false, true, true, true, false, true],
        ];

        $score = 0;

        for ($i = 0; $i < $size; $i++) {
            for ($j = 0; $j <= $size - 11; $j++) {
                foreach ($patterns as $pattern) {
                    $horizontal = true;
                    $vertical = true;

                    for ($k = 0; $k < 11; $k++) {
                        if ($matrix[$i][$j + $k] !== $pattern[$k]) {
                            $horizontal = false;
                        }
                        if ($matrix[$j + $k][$i] !== $pattern[$k]) {
                            $vertical = false;
                        }
                    }

                    $score += ($horizontal ? 40 : 0) + ($vertical ? 40 : 0);
                }
            }
        }

        return $score;
    }

    /** N4: desvio da proporção de módulos escuros em relação a 50%. */
    private function penaltyBalance(array $matrix, int $size): int
    {
        $dark = 0;

        foreach ($matrix as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $dark++;
                }
            }
        }

        $percent = ($dark * 100) / ($size * $size);

        return (int) (abs($percent - 50) / 5) * 10;
    }

    /**
     * Grava os 15 bits de formato (nível de correção + máscara), nas duas
     * cópias que a norma exige.
     */
    private function drawFormatBits(array &$matrix, int $mask, int $size): void
    {
        $data = (self::EC_LEVEL_M << 3) | $mask;
        $bch = $data << 10;

        // Resto da divisão pelo polinômio gerador 0x537.
        for ($i = 4; $i >= 0; $i--) {
            if ($bch & (1 << ($i + 10))) {
                $bch ^= 0x537 << $i;
            }
        }

        // A máscara 0x5412 impede que um formato todo zerado gere uma área
        // uniforme, que o leitor confundiria com dano.
        $bits = (($data << 10) | $bch) ^ 0x5412;

        /*
         * Posições da primeira cópia, do bit 0 ao 14, ao redor do localizador
         * superior esquerdo: sobe a coluna 8 e depois corre a linha 8 para a
         * esquerda. Os saltos existem para desviar do temporizador, que ocupa a
         * linha e a coluna 6.
         *
         * A tabela é explícita porque a forma fechada esconde justamente esses
         * dois saltos — e um erro aqui não quebra o QR de modo visível: a
         * matriz de dados sai perfeita e só o formato fica ilegível, que é o
         * bug mais difícil de enxergar olhando a imagem.
         */
        $primary = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];

        for ($i = 0; $i < 15; $i++) {
            /*
             * A norma numera os bits do mais significativo para o menos, e a
             * tabela abaixo segue essa ordem — daí o `14 - $i`.
             *
             * Ler na direção errada aqui produz um QR de aparência impecável e
             * ilegível: os dados e a máscara saem corretos, só os 15 bits de
             * formato ficam embaralhados, e nenhuma inspeção visual acusa.
             */
            $bit = (bool) (($bits >> (14 - $i)) & 1);

            [$row, $col] = $primary[$i];
            $matrix[$row][$col] = $bit;

            /*
             * Segunda cópia, para que o formato sobreviva a dano num canto.
             *
             * Os 7 primeiros bits (os mais significativos) sobem a coluna 8 a
             * partir da borda inferior; os 8 restantes correm a linha 8 a
             * partir da borda direita. O corte em 7 — e não em 8 — é o que
             * alinha as duas cópias: verificado módulo a módulo contra a norma.
             */
            if ($i < 7) {
                $matrix[$size - 1 - $i][8] = $bit;
            } else {
                $matrix[8][$size - 15 + $i] = $bit;
            }
        }
    }

    /** Bloco de versão, presente apenas da versão 7 em diante. */
    private function drawVersionBits(array &$matrix, int $version, int $size): void
    {
        $bits = self::VERSION_BITS[$version];

        for ($i = 0; $i < 18; $i++) {
            $bit = (bool) (($bits >> $i) & 1);

            $row = intdiv($i, 3);
            $col = $i % 3;

            $matrix[$row][$size - 11 + $col] = $bit;
            $matrix[$size - 11 + $col][$row] = $bit;
        }
    }
}
