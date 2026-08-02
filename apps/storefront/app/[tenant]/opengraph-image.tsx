import { ImageResponse } from 'next/og';
import { fetchMenuResult } from '@/lib/api';
import { getContrastInk } from '@/lib/contrast';

/**
 * Imagem de compartilhamento da loja.
 *
 * Cobre o caso que é maioria no começo: loja sem capa e sem logo, cujo link no
 * WhatsApp aparecia como um retângulo cinza sem nome. Como link de restaurante
 * circula em grupo e conversa muito mais do que em busca, o cartão sem imagem é
 * onde a marca do lojista some com mais frequência.
 *
 * O cartão é gerado com o tema da loja — cor da marca, nome e chamada — para
 * que o link seja reconhecível antes do clique.
 *
 * IMPORTANTE: este arquivo tem precedência sobre o `openGraph.images` definido
 * em generateMetadata. Verificado no HTML renderizado: com uma capa cadastrada,
 * a og:image continuava apontando para cá. Por isso a capa da loja é
 * reaproveitada por redirect quando existe — sem isso, publicar este arquivo
 * substituiria a foto real de toda loja que já subiu uma, que é justamente a
 * loja mais bem cuidada da base.
 */
export const alt = 'Cardápio digital';
export const size = { width: 1200, height: 630 };
export const contentType = 'image/png';

type Props = { params: Promise<{ tenant: string }> };

export default async function OpengraphImage({ params }: Props) {
  const { tenant } = await params;
  const result = await fetchMenuResult(tenant);

  if (result.status !== 'ok') {
    // Loja inexistente ou suspensa: cartão neutro, sem marca inventada.
    return new ImageResponse(
      (
        <div
          style={{
            width: '100%',
            height: '100%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            background: 'rgb(17 24 39)',
            color: 'white',
            fontSize: 48,
            fontWeight: 600,
          }}
        >
          ToMenu
        </div>
      ),
      size,
    );
  }

  const { menu } = result;
  const { theme } = menu;
  const { name, description, acceptsOrders, coverUrl, logoUrl } = menu.tenant;

  /*
   * A imagem própria da loja vence a gerada.
   *
   * Um redirect, e não um <img> dentro do ImageResponse: a capa é uma URL
   * externa arbitrária, e embuti-la faria este endpoint baixar e recodificar a
   * imagem a cada request do rastreador. O redirect entrega o arquivo original,
   * no tamanho em que o lojista o enviou.
   */
  const own = coverUrl ?? logoUrl;

  if (own) {
    return Response.redirect(own, 307);
  }

  const brand = theme.brand;
  const brandInk = theme.brandInk ?? getContrastInk(brand);

  // A chamada acompanha o plano: prometer pedido online numa loja vitrine
  // levaria o cliente a uma página sem carrinho, e o cartão é justamente o
  // que define a expectativa antes do clique.
  const tagline = acceptsOrders
    ? 'Peça online · entrega e retirada'
    : 'Cardápio digital atualizado';

  return new ImageResponse(
    (
      <div
        style={{
          width: '100%',
          height: '100%',
          display: 'flex',
          flexDirection: 'column',
          justifyContent: 'space-between',
          background: `rgb(${brand})`,
          color: `rgb(${brandInk})`,
          padding: 80,
        }}
      >
        <div style={{ display: 'flex', flexDirection: 'column' }}>
          <div
            style={{
              fontSize: 68,
              fontWeight: 700,
              lineHeight: 1.1,
              // Nome longo não pode empurrar a tagline para fora do cartão.
              display: 'flex',
              maxWidth: 1000,
            }}
          >
            {truncate(name, 46)}
          </div>

          {description && (
            <div
              style={{
                marginTop: 24,
                fontSize: 30,
                lineHeight: 1.35,
                opacity: 0.85,
                display: 'flex',
                maxWidth: 900,
              }}
            >
              {truncate(description, 110)}
            </div>
          )}
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
          <div
            style={{
              display: 'flex',
              fontSize: 28,
              fontWeight: 600,
              padding: '12px 24px',
              borderRadius: 999,
              background: `rgb(${brandInk} / 0.14)`,
            }}
          >
            {tagline}
          </div>
        </div>
      </div>
    ),
    size,
  );
}

/** Corta no limite de caracteres sem partir palavra no meio. */
function truncate(text: string, max: number): string {
  const clean = text.trim().replace(/\s+/g, ' ');
  if (clean.length <= max) return clean;

  const cut = clean.slice(0, max);
  const lastSpace = cut.lastIndexOf(' ');

  return `${(lastSpace > max * 0.6 ? cut.slice(0, lastSpace) : cut).trimEnd()}…`;
}
