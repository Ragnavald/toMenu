import { BrandLogo } from '@/components/brand-logo';
import { getContrastInk } from '@/lib/contrast';

/**
 * Crédito da plataforma no rodapé do cardápio.
 *
 * Mesma regra do SITE_URL da landing: em desenvolvimento o domínio raiz é
 * `localhost`, onde não há TLS nem wildcard, então o link precisa apontar para
 * a porta local em vez de um https que não resolveria.
 */
const SITE_URL =
  (process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost') === 'localhost'
    ? 'http://localhost:3000'
    : `https://${process.env.NEXT_PUBLIC_ROOT_DOMAIN}`;

/**
 * Assinatura discreta do ToMenu ao final do cardápio.
 *
 * Três decisões de apresentação:
 *
 * 1. Fora da `<main>` do MenuBrowser e depois dela. O cardápio é o conteúdo da
 *    página; o crédito é da plataforma, não da loja, e um `<footer>` próprio é
 *    o que diz isso tanto para o leitor de tela quanto para o buscador.
 *
 * 2. Cor `subtle` e tipografia pequena. O rodapé não disputa atenção com os
 *    pratos — a loja paga pela vitrine, e um crédito berrante no cardápio dela
 *    seria uma troca ruim para os dois lados.
 *
 * 3. O lockup é escolhido pelo fundo da loja, não pela preferência do sistema
 *    — ver a nota sobre `scheme` no corpo do componente.
 *
 * O espaço para a barra do carrinho é reservado pelo `pb-40` da `<main>`, então
 * este bloco não precisa de recuo próprio — mas o `mt-` mantém respiro quando a
 * lista de produtos termina rente.
 */
export function PoweredBy({ surface }: { surface: string }) {
  /*
   * O lockup é escolhido pelo fundo da LOJA, não pela preferência do sistema.
   *
   * O cardápio fixa `colorScheme: light` e pinta o fundo com o tema do
   * lojista — que pode ser claro ou escuro. Deixar o <picture> decidir pela
   * media query fazia o visitante com o celular em modo escuro receber a arte
   * clara sobre o fundo claro da loja: o texto "ToMenu" sumia e sobrava só o
   * ícone.
   *
   * `getContrastInk` já resolve exatamente esta pergunta (o fundo é claro ou
   * escuro?) e é o mesmo cálculo que o layout usa para a tinta do tema, então
   * o logo nunca discorda do resto da página.
   */
  const darkSurface = getContrastInk(surface) === '255 255 255';

  return (
    <footer className="mx-auto max-w-3xl px-4 pb-10">
      <div className="border-t border-[var(--hairline)] pt-6 text-center">
        {/* `<a>` e não `next/link`: o destino é outra origem, onde o prefetch e
            a navegação client-side não se aplicam. Mesma convenção dos demais
            links externos do storefront (ver store-profile). */}
        <a
          href={SITE_URL}
          // Abre em aba nova para não tirar o visitante do meio de um pedido.
          target="_blank"
          rel="noreferrer"
          // `opacity` no conjunto, e não cor fraca só no texto: o logo é um
          // PNG e não responde a `text-*`, então tingir apenas a palavra criava
          // um degrau — "Feito com" quase apagado ao lado de um logo em força
          // total. Atenuar os dois juntos mantém o par legível e discreto.
          className="inline-flex flex-col items-center gap-2 text-muted opacity-70 transition-opacity hover:opacity-100"
        >
          <span className="text-[11px] font-medium uppercase tracking-wider">Feito com</span>
          <BrandLogo
            variant="wordmark"
            scheme={darkSurface ? 'dark' : 'light'}
            className="h-4 w-auto"
          />
        </a>

        {/*
          Privacidade também no cardápio: quem faz um pedido entrega nome,
          telefone e endereço, e precisa poder descobrir o que acontece com
          esses dados sem sair procurando pela plataforma.

          URL absoluta e não `next/link`: no subdomínio da loja o `/privacidade`
          relativo seria reescrito para `/{slug}/privacidade` pelo proxy, que
          não existe. O documento é da plataforma e vive no domínio raiz.
        */}
        <p className="mt-4 text-[11px] text-subtle">
          <a
            href={`${SITE_URL}/privacidade`}
            target="_blank"
            rel="noreferrer"
            className="underline underline-offset-2 opacity-70 transition-opacity hover:opacity-100"
          >
            Privacidade
          </a>
        </p>
      </div>
    </footer>
  );
}
