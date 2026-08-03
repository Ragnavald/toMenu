<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\StoreQrCode;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * QR Code do endereço da loja, para impressão e download.
 *
 * Fora do grupo `plan.orders`: o QR leva ao cardápio, que existe nos dois
 * planos — e é no somente-cardápio que ele carrega mais peso, por ser o único
 * caminho entre a mesa e o link.
 *
 * Responde a imagem direto, sem JSON envolvendo base64: o `<img>` da prévia e
 * o download usam a mesma URL, o navegador cacheia por ETag e a página não
 * carrega o binário dentro do seu próprio payload.
 */
class QrCodeController extends Controller
{
    public function show(Request $request, TenantContext $context, StoreQrCode $qr): Response
    {
        $tenant = $context->getOrFail();
        $size = $qr->resolveSize($request->query('size', StoreQrCode::DEFAULT_SIZE));

        $etag = '"'.$qr->fingerprint($tenant, $size).'"';

        /*
         * Cache privado, e nunca compartilhado.
         *
         * O QR não é segredo — está impresso no balcão —, mas a resposta é
         * autenticada e endereçada por uma rota comum a todos os tenants. Um
         * cache intermediário que guardasse por URL entregaria o QR de uma loja
         * ao painel de outra, que é o tipo de vazamento cruzado que esta
         * arquitetura inteira existe para impedir.
         */
        $headers = [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => $etag,
        ];

        // O navegador já tem esta versão: 304 e nenhum byte de imagem no fio.
        if (trim($request->headers->get('If-None-Match', '')) === $etag) {
            return response('', 304, $headers);
        }

        $png = $qr->png($tenant, $size);

        // `download` só quando pedido; sem isso a prévia na tela dispararia um
        // download a cada render.
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'
                .$qr->filename($tenant, $size).'"';
        }

        return response($png, 200, $headers + [
            'Content-Length' => (string) strlen($png),
        ]);
    }
}
