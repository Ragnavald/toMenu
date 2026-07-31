import { useEffect, useState } from 'react';
import { formatMoney } from '@/lib/api';
import type { FinancePoint } from '@/lib/types';

/**
 * Gráficos do painel financeiro em SVG puro.
 *
 * Sem biblioteca de charts: são duas formas simples, e uma dependência como a
 * Recharts custaria ~100KB no bundle para desenhar retângulos. As cores saem de
 * uma paleta validada para daltonismo e para os dois temas.
 */

/** Slots categóricos validados (ΔE adjacente ≥ 8 em protan/deutan/tritan). */
export const CATEGORICAL = {
  light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4'],
  dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181'],
} as const;

/** Série única de receita: uma cor só, sem legenda — o título já diz o que é. */
const REVENUE_HUE = { light: '#2a78d6', dark: '#3987e5' } as const;

function useIsDark(): boolean {
  // Lê a classe .dark do <html>, a mesma chave que o toggle do admin controla.
  const [dark, setDark] = useState(
    () => document.documentElement.classList.contains('dark'),
  );

  // As cores dos gráficos são passadas em atributo (SVG não herda de CSS aqui),
  // então o componente precisa reagir à troca de tema em vez de só ler no
  // primeiro render — senão o gráfico fica com a paleta do tema anterior.
  useEffect(() => {
    const observer = new MutationObserver(() =>
      setDark(document.documentElement.classList.contains('dark')),
    );

    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class'],
    });

    return () => observer.disconnect();
  }, []);

  return dark;
}

/**
 * Colunas de receita ao longo do tempo.
 *
 * Barras crescem de uma linha de base única, com topo arredondado em 4px e
 * base reta. O vão de 2px entre colunas é o que as separa — sem contorno.
 */
export function RevenueBars({
  points,
  emptyLabel = 'Sem receita no período.',
}: {
  points: FinancePoint[];
  emptyLabel?: string;
}) {
  const isDark = useIsDark();
  const [hover, setHover] = useState<number | null>(null);

  const max = Math.max(...points.map((p) => p.revenueCents), 0);

  if (points.length === 0 || max === 0) {
    return (
      <p className="py-10 text-center text-xs text-muted">{emptyLabel}</p>
    );
  }

  const hue = isDark ? REVENUE_HUE.dark : REVENUE_HUE.light;
  const active = hover !== null ? points[hover] : null;

  // Com poucas colunas cabe um rótulo sob cada uma; em séries longas isso
  // viraria uma faixa ilegível, e só os extremos aparecem.
  const labelEvery = points.length <= 14;

  return (
    <div className="relative">
      {/* Barras em HTML, não em SVG: com viewBox esticado na horizontal a mesma
          largura relativa vira um bloco largo em séries curtas e um traço em
          séries longas. Em pixels a espessura é a mesma nos dois casos. */}
      <div
        className="flex h-40 items-end gap-[2px] border-b border-line"
        role="img"
        aria-label="Receita por período"
      >
        {points.map((point, index) => {
          const ratio = point.revenueCents / max;
          // Piso de 2px para que um dia com receita baixa continue visível.
          const barHeight = point.revenueCents > 0 ? Math.max(ratio * 100, 1.5) : 0;

          return (
            <div
              key={point.day ?? point.month ?? index}
              className="flex h-full min-w-0 flex-1 items-end justify-center"
              onMouseEnter={() => setHover(index)}
              onMouseLeave={() => setHover(null)}
            >
              <div
                className="w-full max-w-6 rounded-t transition-opacity"
                style={{
                  height: `${barHeight}%`,
                  background: hue,
                  opacity: hover === null || hover === index ? 1 : 0.45,
                }}
              />
            </div>
          );
        })}
      </div>

      {labelEvery ? (
        <div className="mt-1.5 flex text-[10px] text-muted">
          {points.map((point, index) => (
            <span
              key={point.day ?? point.month ?? index}
              className="min-w-0 flex-1 truncate text-center"
            >
              {point.label}
            </span>
          ))}
        </div>
      ) : (
        // Séries longas: só os extremos, o resto fica no tooltip.
        <div className="mt-1.5 flex justify-between text-[10px] text-muted">
          <span>{points[0]?.label}</span>
          <span>{points[points.length - 1]?.label}</span>
        </div>
      )}

      {active && (
        <div className="pointer-events-none absolute -top-1 left-1/2 -translate-x-1/2 rounded-lg border border-line bg-panel px-2.5 py-1.5 text-xs shadow-lg">
          <span className="font-medium">{active.label}</span>
          <span className="mx-1.5 text-muted">·</span>
          <span className="font-semibold tabular-nums">
            {formatMoney(active.revenueCents)}
          </span>
          <span className="ml-1.5 text-muted">
            ({active.ordersCount} {active.ordersCount === 1 ? 'pedido' : 'pedidos'})
          </span>
        </div>
      )}
    </div>
  );
}

/**
 * Composição da receita por forma de pagamento.
 *
 * Barra empilhada horizontal: são poucas categorias de nomes longos, e a
 * horizontal acomoda os rótulos sem girar texto. O vão de 2px em cor de
 * superfície separa os segmentos.
 */
export function PaymentMixBar({
  items,
  labels,
}: {
  items: { method: string; revenueCents: number; ordersCount: number }[];
  labels: Record<string, string>;
}) {
  const isDark = useIsDark();
  const palette = isDark ? CATEGORICAL.dark : CATEGORICAL.light;

  const total = items.reduce((sum, item) => sum + item.revenueCents, 0);

  if (total === 0) {
    return (
      <p className="py-6 text-center text-xs text-muted">
        Sem receita para compor no período.
      </p>
    );
  }

  return (
    <div>
      <div className="flex h-3 w-full gap-[2px] overflow-hidden rounded-full">
        {items.map((item, index) => (
          <div
            key={item.method}
            style={{
              width: `${(item.revenueCents / total) * 100}%`,
              // A cor segue a posição da forma de pagamento na lista, que é
              // estável — filtrar não repinta as demais.
              background: palette[index % palette.length],
            }}
            title={`${labels[item.method] ?? item.method}: ${formatMoney(item.revenueCents)}`}
          />
        ))}
      </div>

      {/* Legenda sempre presente: identidade nunca depende só da cor. */}
      <ul className="mt-3 grid gap-1.5">
        {items.map((item, index) => (
          <li key={item.method} className="flex items-center gap-2 text-xs">
            <span
              aria-hidden
              className="size-2.5 shrink-0 rounded-full"
              style={{ background: palette[index % palette.length] }}
            />
            <span className="flex-1 truncate">
              {labels[item.method] ?? item.method}
            </span>
            <span className="tabular-nums text-muted">
              {((item.revenueCents / total) * 100).toFixed(1)}%
            </span>
            <span className="w-24 text-right font-medium tabular-nums">
              {formatMoney(item.revenueCents)}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
