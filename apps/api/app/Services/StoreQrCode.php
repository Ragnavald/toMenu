<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * QR Code do endereço público da loja, em PNG.
 *
 * Existe nos dois planos. O plano somente-cardápio é justamente onde o QR mais
 * importa — sem pedido online, a mesa e o balcão são o canal, e o QR é o que
 * leva o cliente até lá.
 *
 * O conteúdo do QR é apenas a `storefrontUrl` do tenant. Nada de parâmetro de
 * campanha ou de redirecionador próprio: o QR acaba impresso em material que
 * dura anos, e um link que dependa de rota interna vira um cartaz morto no dia
 * em que essa rota mudar. O subdomínio da loja é o endereço mais estável que a
 * plataforma tem.
 */
class StoreQrCode
{
    /**
     * Tamanhos ofertados, em pixels.
     *
     * Uma lista fechada, e não um inteiro livre do cliente, por duas razões: o
     * cache tem uma entrada por tamanho, e um parâmetro aberto deixaria
     * qualquer um encher o cache com 4000 variações do mesmo código; e o lado
     * real é arredondado para múltiplo do número de módulos, então valores
     * intermediários dariam no mesmo arquivo com nome diferente.
     *
     * O padrão de 1024 imprime nítido até cerca de A5; 2048 cobre cartaz.
     */
    public const SIZES = [512, 1024, 2048];

    public const DEFAULT_SIZE = 1024;

    /**
     * O QR só muda se a URL da loja mudar, e a URL deriva do slug — que hoje é
     * imutável depois do cadastro. Um mês é curto perto disso; o que ele evita
     * é uma entrada órfã ficar presa para sempre num cache sem despejo.
     */
    private const TTL = 2592000;

    public function __construct(
        private QrCodeEncoder $encoder,
        private QrCodePngWriter $writer,
    ) {}

    /**
     * PNG do QR, do cache quando possível.
     *
     * Gerar custa alguns milissegundos e o resultado é idêntico entre chamadas,
     * então a segunda visita à tela — e o download em si, que é uma requisição
     * separada da que renderizou a prévia — não repetem o trabalho.
     */
    public function png(Tenant $tenant, int $size = self::DEFAULT_SIZE): string
    {
        $url = $tenant->storefrontUrl();

        return Cache::remember(
            $this->key($tenant, $url, $size),
            self::TTL,
            fn () => $this->writer->write($this->encoder->encode($url), $size),
        );
    }

    /** Nome sugerido ao navegador no download. */
    public function filename(Tenant $tenant, int $size): string
    {
        return "qrcode-{$tenant->slug}-{$size}.png";
    }

    /**
     * Identificador estável do conteúdo, para ETag e nome de cache.
     *
     * Deriva da URL e do tamanho — as duas únicas entradas do gerador —, então
     * duas chamadas com o mesmo resultado sempre coincidem, e uma mudança de
     * endereço da loja invalida sozinha.
     */
    public function fingerprint(Tenant $tenant, int $size): string
    {
        return substr(hash('xxh128', $tenant->storefrontUrl()."|{$size}"), 0, 16);
    }

    /**
     * Tamanho pedido, restrito à lista ofertada.
     *
     * Silenciosamente cai no padrão em vez de recusar: o parâmetro vem da
     * própria UI, e um 422 aqui só produziria uma tela quebrada por um valor
     * que a plataforma não usa.
     */
    public function resolveSize(mixed $requested): int
    {
        $size = (int) $requested;

        return in_array($size, self::SIZES, true) ? $size : self::DEFAULT_SIZE;
    }

    /**
     * A URL entra na chave, e não só o id do tenant.
     *
     * Assim uma mudança de domínio raiz (ou de esquema, ou de porta em
     * desenvolvimento) invalida o cache por construção. Guardar só por id
     * deixaria a loja servindo para sempre um QR que aponta para o endereço
     * antigo — e ninguém descobre isso olhando a imagem.
     */
    private function key(Tenant $tenant, string $url, int $size): string
    {
        return "tenant:{$tenant->getKey()}:qrcode:".md5($url)."-{$size}";
    }
}
