import {
  WEEKDAYS,
  type BusinessHours,
  type DaySchedule,
  type Weekday,
} from './types';

/**
 * Leitura do horário de funcionamento para exibição.
 *
 * Aqui não se decide se a loja está aberta — isso vem pronto da API, em
 * `tenant.isOpen`, que considera o override manual e usa o fuso do servidor.
 * Refazer a conta no cliente daria divergência entre o selo e o que o checkout
 * aceita, justamente no caso em que a loja fechou a cozinha na mão.
 */

export type HoursRow = {
  day: Weekday;
  schedule: DaySchedule | null;
  isToday: boolean;
};

/** Dia da semana no fuso de Brasília, independente do relógio do visitante. */
export function todayKey(): Weekday {
  const label = new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    timeZone: 'America/Sao_Paulo',
  }).format(new Date());

  return label.toLowerCase() as Weekday;
}

/**
 * A semana inteira, começando por hoje.
 *
 * Ver "hoje" no topo é o que o cliente veio conferir; a ordem canônica de
 * domingo a sábado obriga a procurar a linha certa na lista.
 */
export function weekFromToday(hours: BusinessHours): HoursRow[] {
  const today = todayKey();
  const offset = WEEKDAYS.indexOf(today);
  const start = offset < 0 ? 0 : offset;

  return WEEKDAYS.map((_, index) => {
    const day = WEEKDAYS[(start + index) % WEEKDAYS.length];
    const schedule = hours[day];

    return {
      day,
      schedule: schedule?.enabled ? schedule : null,
      isToday: index === 0,
    };
  });
}

/** "18:00 às 23:00" — ou o aviso de fechado, quando o dia está desligado. */
export function formatSchedule(schedule: DaySchedule | null): string {
  if (!schedule) return 'Fechado';

  return `${schedule.open} às ${schedule.close}`;
}

export function hasAnyHours(hours: BusinessHours): boolean {
  return WEEKDAYS.some((day) => hours[day]?.enabled);
}
