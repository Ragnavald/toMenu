import { STATUS_LABEL, type StoreStatus } from '@/lib/platform';

/**
 * Etiqueta de situação da loja.
 *
 * As cores carregam significado (vermelho = fora do ar), mas nunca sozinhas: o
 * rótulo textual sempre acompanha, para quem não distingue as cores.
 */
const TONE: Record<StoreStatus, string> = {
  active:
    'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
  trial: 'bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
  past_due:
    'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200',
  suspended: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
  deleted: 'bg-line text-muted',
};

export function StatusPill({ status }: { status: StoreStatus }) {
  return (
    <span
      className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${TONE[status]}`}
    >
      {STATUS_LABEL[status]}
    </span>
  );
}
