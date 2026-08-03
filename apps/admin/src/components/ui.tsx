import { useEffect, useRef, useState } from 'react';

export function PageHeader({
  title,
  description,
  action,
}: {
  title: string;
  description?: string;
  action?: React.ReactNode;
}) {
  return (
    <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="text-lg font-semibold tracking-tight">{title}</h1>
        {description && (
          <p className="mt-0.5 text-sm text-muted">{description}</p>
        )}
      </div>
      {action}
    </div>
  );
}

export function Section({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <section className="panel p-4 sm:p-5">
      <h2 className="text-sm font-semibold">{title}</h2>
      {description && <p className="mt-0.5 text-xs text-muted">{description}</p>}
      <div className="mt-4">{children}</div>
    </section>
  );
}

export function Field({
  label,
  hint,
  error,
  children,
}: {
  label: string;
  hint?: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="grid gap-1.5">
      <span className="text-xs font-medium text-muted">{label}</span>
      {children}
      {error ? (
        <span className="text-xs text-red-600">{error}</span>
      ) : hint ? (
        <span className="text-xs text-muted">{hint}</span>
      ) : null}
    </label>
  );
}

/**
 * Entrada monetária.
 *
 * Mantém o texto digitado no estado local e só emite centavos para cima. Sem
 * isso, formatar a cada tecla atrapalha a digitação (o cursor pula) e o valor
 * trafega como float pelo formulário.
 */
export function MoneyInput({
  valueCents,
  onChange,
  placeholder = '0,00',
  id,
}: {
  valueCents: number | null;
  onChange: (cents: number | null) => void;
  placeholder?: string;
  id?: string;
}) {
  const [text, setText] = useState(() => centsToText(valueCents));

  useEffect(() => {
    setText(centsToText(valueCents));
  }, [valueCents]);

  return (
    <div className="flex items-center gap-2">
      <span className="text-sm font-medium text-muted shrink-0">R$</span>
      <input
        id={id}
        type="text"
        inputMode="numeric"
        value={text}
        placeholder={placeholder}
        onChange={(event) => {
          const rawDigits = event.target.value.replace(/\D/g, '');
          if (!rawDigits) {
            setText('');
            onChange(null);
            return;
          }
          const cents = parseInt(rawDigits, 10);
          const formatted = centsToText(cents);
          setText(formatted);
          onChange(cents);
        }}
        className="field font-mono"
      />
    </div>
  );
}

export function centsToText(cents: number | null | undefined): string {
  if (cents === null || cents === undefined || Number.isNaN(cents)) return '';
  return (cents / 100).toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

export function textToCents(text: string): number | null {
  const digits = text.replace(/\D/g, '');
  if (!digits) return null;
  const parsed = parseInt(digits, 10);
  return Number.isNaN(parsed) ? null : parsed;
}

export function Toggle({
  checked,
  onChange,
  label,
  description,
}: {
  checked: boolean;
  onChange: (value: boolean) => void;
  label: string;
  description?: string;
}) {
  return (
    <label className="flex cursor-pointer items-start gap-3">
      <input
        type="checkbox"
        checked={checked}
        onChange={(event) => onChange(event.target.checked)}
        className="mt-0.5 size-4 shrink-0 accent-[rgb(var(--accent))]"
      />
      <span className="min-w-0">
        <span className="block text-sm">{label}</span>
        {description && (
          <span className="block text-xs text-muted">{description}</span>
        )}
      </span>
    </label>
  );
}

export function SaveBar({
  dirty,
  saving,
  onSave,
  savedAt,
}: {
  dirty: boolean;
  saving: boolean;
  onSave: () => void;
  savedAt: number | null;
}) {
  const justSaved = savedAt !== null && Date.now() - savedAt < 2500;

  return (
    <div className="sticky bottom-0 z-10 -mx-4 mt-5 border-t border-line bg-panel/90 px-4 py-3 backdrop-blur-md sm:-mx-5 sm:px-5">
      <div className="flex items-center justify-end gap-3">
        {justSaved && !dirty && (
          <span className="text-xs font-medium text-emerald-600">Salvo ✓</span>
        )}
        <button
          type="button"
          onClick={onSave}
          disabled={!dirty || saving}
          className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-45"
        >
          {saving ? 'Salvando…' : 'Salvar alterações'}
        </button>
      </div>
    </div>
  );
}

/**
 * Modal sobre o conteúdo da página.
 *
 * Usa o <dialog> nativo em vez de uma div posicionada: ele já entrega foco
 * preso dentro da caixa, fechamento no Esc e inertização do resto da página —
 * três coisas que uma reimplementação à mão costuma errar em parte.
 *
 * `showModal()` precisa ser chamado por efeito, e não no JSX, porque o
 * elemento tem que existir no DOM antes. Desmontar o conteúdo quando fechado
 * (em vez de só escondê-lo) garante que reabrir comece do estado inicial.
 */
export function Modal({
  open,
  onClose,
  title,
  children,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
}) {
  const ref = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;

    if (open && !dialog.open) dialog.showModal();
    if (!open && dialog.open) dialog.close();
  }, [open]);

  if (!open) return null;

  return (
    <dialog
      ref={ref}
      // `close` cobre as saídas que não passam por um clique nosso: Esc e o
      // fechamento programático. Sem isto o estado do pai ficaria `open` com a
      // caixa já fechada, e o próximo clique no botão não reabriria nada.
      onClose={onClose}
      // Clique no backdrop fecha. O <dialog> reporta o próprio elemento como
      // alvo quando o clique cai fora da caixa de conteúdo.
      onClick={(event) => {
        if (event.target === ref.current) onClose();
      }}
      className="panel m-auto w-[calc(100%-2rem)] max-w-lg p-0 backdrop:bg-black/50"
    >
      <div className="flex items-start justify-between gap-4 border-b border-line px-5 py-3.5">
        <h2 className="text-sm font-semibold">{title}</h2>
        <button
          type="button"
          onClick={onClose}
          aria-label="Fechar"
          className="-mr-1 grid size-7 shrink-0 place-items-center rounded-md text-muted transition-colors hover:bg-black/5 dark:hover:bg-white/10"
        >
          ✕
        </button>
      </div>

      <div className="px-5 py-4">{children}</div>
    </dialog>
  );
}

export function EmptyState({
  title,
  description,
  action,
}: {
  title: string;
  description: string;
  action?: React.ReactNode;
}) {
  return (
    <div className="panel grid place-items-center px-6 py-14 text-center">
      <p className="text-sm font-medium">{title}</p>
      <p className="mt-1 max-w-sm text-sm text-muted">{description}</p>
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}
