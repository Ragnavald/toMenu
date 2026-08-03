<?php

namespace App\Services;

/**
 * Escreve a matriz do QR como PNG, montando os chunks à mão.
 *
 * Por que não a GD: um QR tem exatamente duas cores, e o PNG sabe representar
 * isso em 1 bit por pixel com paleta de duas entradas. A GD escreve truecolor
 * ou paleta de 8 bits — oito vezes mais dado bruto antes da compressão, e
 * `imagepng` ainda ignora o filtro por linha que aqui rende a maior parte do
 * ganho. Medido com a URL de uma loja em 1024px: ~2.4 KB por este caminho
 * contra ~9 KB pela GD com `imagecolorallocate` e paleta.
 *
 * O arquivo resultante é um PNG válido e completo: assinatura, IHDR, PLTE,
 * IDAT e IEND. Sem metadados, sem gama, sem data de criação — nada que um
 * leitor de QR use e nada que engorde o arquivo.
 */
class QrCodePngWriter
{
    /**
     * Quiet zone em módulos.
     *
     * Quatro é o mínimo da norma. Não é decoração: sem a margem clara, um
     * leitor não consegue delimitar onde o código começa, e o QR falha
     * exatamente no caso mais comum de uso — colado num cartaz colorido.
     */
    private const QUIET_ZONE = 4;

    /**
     * PNG de 1 bit, dois tons.
     *
     * O tamanho pedido é tratado como alvo, não como promessa: o lado final é
     * arredondado para um múltiplo inteiro do número de módulos. Escalar por
     * fração produziria módulos de larguras diferentes ao longo da imagem, o
     * que degrada a leitura em impressão pequena — e o arredondamento também é
     * o que permite gerar a imagem sem nenhuma reamostragem.
     *
     * @param  array<int,array<int,bool>>  $matrix
     * @param  int  $targetSize  lado desejado em pixels
     */
    public function write(array $matrix, int $targetSize): string
    {
        $modules = count($matrix) + (self::QUIET_ZONE * 2);
        $scale = max(1, (int) floor($targetSize / $modules));
        $side = $modules * $scale;

        $raw = $this->rawScanlines($matrix, $modules, $scale, $side);

        return "\x89PNG\r\n\x1a\n"
            .$this->chunk('IHDR', pack('NNccccc', $side, $side, 1, 3, 0, 0, 0))
            // Paleta: índice 0 branco, índice 1 preto. O QR precisa de contraste
            // escuro-sobre-claro nesta ordem; invertido, boa parte dos leitores
            // de celular não reconhece o código.
            .$this->chunk('PLTE', "\xFF\xFF\xFF\x00\x00\x00")
            .$this->chunk('IDAT', gzcompress($raw, 9))
            .$this->chunk('IEND', '');
    }

    /**
     * Linhas de varredura já filtradas, prontas para o deflate.
     *
     * Cada linha carrega o byte de filtro na frente. As linhas repetidas —
     * maioria absoluta, porque cada módulo vira `$scale` linhas idênticas —
     * usam o filtro 2 (Up), que as zera por completo contra a linha anterior. É
     * uma sequência longa de zeros, que é justamente o que o deflate comprime
     * melhor.
     *
     * @param  array<int,array<int,bool>>  $matrix
     */
    private function rawScanlines(array $matrix, int $modules, int $scale, int $side): string
    {
        $bytesPerLine = (int) ceil($side / 8);

        // Uma linha zerada com filtro Up reproduz a linha anterior.
        $repeat = "\x02".str_repeat("\x00", $bytesPerLine);

        $raw = '';

        for ($row = 0; $row < $modules; $row++) {
            $raw .= "\x00".$this->packRow($matrix, $row, $modules, $scale, $bytesPerLine);

            // As demais cópias verticais do mesmo módulo.
            $raw .= str_repeat($repeat, $scale - 1);
        }

        return $raw;
    }

    /**
     * Uma linha de módulos empacotada em bits, com a quiet zone nas bordas.
     *
     * @param  array<int,array<int,bool>>  $matrix
     */
    private function packRow(array $matrix, int $row, int $modules, int $scale, int $bytesPerLine): string
    {
        $inner = $row - self::QUIET_ZONE;
        $quiet = $inner < 0 || $inner >= count($matrix);

        // Linhas da margem superior e inferior são inteiramente claras.
        if ($quiet) {
            return str_repeat("\x00", $bytesPerLine);
        }

        $bits = '';

        for ($col = 0; $col < $modules; $col++) {
            $index = $col - self::QUIET_ZONE;
            $dark = $index >= 0
                && $index < count($matrix)
                && $matrix[$inner][$index];

            $bits .= str_repeat($dark ? '1' : '0', $scale);
        }

        // O PNG preenche a última fração de byte com zeros.
        $bits = str_pad($bits, $bytesPerLine * 8, '0');

        $line = '';
        foreach (str_split($bits, 8) as $byte) {
            $line .= chr(bindec($byte));
        }

        return $line;
    }

    /** Um chunk PNG: tamanho, tipo, dado e CRC32 sobre tipo+dado. */
    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            .$type
            .$data
            .pack('N', crc32($type.$data));
    }
}
