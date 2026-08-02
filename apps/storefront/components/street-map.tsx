import type { TenantInfo } from '@/lib/types';

/**
 * Mapa da vizinhança desenhado como SVG inline.
 *
 * O traçado vem pronto da API (`tenant.streetMap`), já projetado em
 * coordenadas de viewBox: aqui não há cálculo nem chamada de rede, só desenho.
 * A geração acontece uma vez por endereço, em fila, porque depende de serviços
 * públicos do OpenStreetMap que são lentos e frequentemente devolvem 429.
 *
 * Inline e não `<img>`: assim os traços usam as variáveis de tema do tenant
 * (`--ink`, `--brand`) e acompanham a identidade da loja sem gerar um arquivo
 * por loja. Como é SVG, também escala sem borrar em telas retina.
 *
 * Sem JS no cliente — é markup estático, renderizado no servidor.
 */

/**
 * Lado do quadrado de desenho. Espelha `StreetMapBuilder::MAP_SIZE` na API,
 * que projetou as coordenadas contra este mesmo valor: mudar um sem o outro
 * desloca o traçado em relação ao pino.
 */
const MAP_SIZE = 560;

export function StreetMap({
  paths,
  label,
}: {
  paths: NonNullable<TenantInfo['streetMap']>;
  label: string;
}) {
  return (
    <svg
      viewBox={`0 0 ${MAP_SIZE} ${MAP_SIZE}`}
      // A proporção 16/10 recorta o quadrado do desenho: o pino fica centrado e
      // o excesso vertical sai igualmente de cima e de baixo.
      className="block aspect-[16/10] w-full"
      role="img"
      aria-label={`Mapa da região: ${label}`}
      preserveAspectRatio="xMidYMid slice"
    >
      {/* Fundo levemente distinto do card, para o mapa se ler como uma
          superfície própria sem virar um bloco escuro na página. */}
      <rect width={MAP_SIZE} height={MAP_SIZE} fill="rgb(var(--ink) / 0.045)" />

      {/* As ruas em tinta translúcida: no tema claro leem como cinza suave, no
          escuro como traço claro sobre fundo escuro — sem precisar de duas
          paletas. */}
      <g
        fill="none"
        stroke="rgb(var(--ink) / 0.22)"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        {paths.map((path, index) => (
          <path key={index} d={path.d} strokeWidth={path.width} />
        ))}
      </g>

      {/* Halo + pino na cor da marca: o único elemento saturado do desenho, que
          é justamente o que se quer que o olho encontre primeiro. */}
      <circle
        cx={MAP_SIZE / 2}
        cy={MAP_SIZE / 2}
        r="30"
        fill="rgb(var(--brand) / 0.16)"
      />
      <circle
        cx={MAP_SIZE / 2}
        cy={MAP_SIZE / 2}
        r="8.5"
        fill="rgb(var(--brand))"
        stroke="rgb(var(--surface))"
        strokeWidth="3"
      />
    </svg>
  );
}
