'use client';

import { useEffect, useState } from 'react';
import { todayKey } from '@/lib/hours';
import type { BusinessHours } from '@/lib/types';

/**
 * Selo "Aberto agora" / "Fechado agora".
 *
 * É client por um motivo específico: a página é cacheada por uma hora, então o
 * `isOpen` que veio do servidor envelhece — uma loja que fecha às 23h ainda
 * apareceria aberta às 23h40 para quem pegasse a versão cacheada.
 *
 * O valor do servidor continua sendo a fonte inicial, e é o que vai no HTML:
 * ele é o único que conhece o override manual ("fechar agora"), que nenhum
 * horário revela. Depois da hidratação o componente só se atreve a corrigir o
 * selo quando o relógio discorda do horário declarado, o que cobre exatamente
 * o caso da página velha sem descartar o override.
 */
export function StoreStatus({
  isOpen: serverIsOpen,
  hours,
}: {
  isOpen: boolean;
  hours: BusinessHours;
}) {
  const [isOpen, setIsOpen] = useState(serverIsOpen);

  useEffect(() => {
    function sync() {
      const computed = isOpenAt(hours, new Date());

      // `null` = não dá para saber pelo horário (dia sem configuração). Nesse
      // caso o servidor continua mandando.
      if (computed !== null) setIsOpen(computed);
    }

    sync();

    // Um minuto: a granularidade do horário declarado é o minuto, então checar
    // mais vezes não muda o resultado.
    const timer = setInterval(sync, 60_000);

    return () => clearInterval(timer);
  }, [hours]);

  return (
    <span
      className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
      style={{
        background: isOpen ? 'rgb(22 163 74 / 0.12)' : 'rgb(var(--ink) / 0.07)',
        color: isOpen ? 'rgb(21 128 61)' : 'var(--ink-muted)',
      }}
    >
      <span
        aria-hidden
        className="size-1.5 rounded-full"
        style={{
          background: isOpen ? 'rgb(22 163 74)' : 'var(--ink-subtle)',
        }}
      />
      {isOpen ? 'Aberto agora' : 'Fechado agora'}
    </span>
  );
}

/**
 * A loja está aberta neste instante, segundo o horário declarado?
 *
 * Espelha `MenuService::isOpen` da API, inclusive a faixa que cruza a
 * meia-noite (19:00–02:00) — sem a comparação invertida a loja apareceria
 * fechada justamente no pico da noite.
 *
 * Devolve `null` quando o dia não tem configuração, para que o chamador saiba
 * distinguir "fechado" de "não sei".
 */
function isOpenAt(hours: BusinessHours, now: Date): boolean | null {
  const slot = hours[todayKey()];

  if (!slot) return null;

  if (!slot.enabled) return false;

  const current = new Intl.DateTimeFormat('pt-BR', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: 'America/Sao_Paulo',
  }).format(now);

  const open = slot.open ?? '00:00';
  const close = slot.close ?? '23:59';

  return open <= close
    ? current >= open && current <= close
    : current >= open || current <= close;
}
