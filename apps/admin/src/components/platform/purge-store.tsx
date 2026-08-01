import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ApiError } from '@/lib/api';
import { platformFetch, type PurgePreview } from '@/lib/platform';
import { Field, Section } from '@/components/ui';

/**
 * Zona de perigo do painel da plataforma: exclusão permanente.
 *
 * Diferente da exclusão feita pelo lojista, que é soft delete e preserva os
 * pedidos como documento fiscal: aqui a linha do tenant é apagada de verdade e
 * as FKs em cascata levam pedidos, pagamentos e usuários. Não há como desfazer
 * pela interface — só restore de backup.
 *
 * Daí a prévia ser carregada só ao abrir o diálogo, e ser exibida item a item:
 * a confirmação precisa ser informada, não apenas difícil.
 */
export function PurgeStoreDialog({
  slug,
  onPurged,
}: {
  slug: string;
  onPurged: () => void;
}) {
  const [open, setOpen] = useState(false);

  return (
    <Section
      title="Excluir permanentemente"
      description="Apaga a loja e todo o conteúdo dela."
    >
      {open ? (
        <PurgeForm
          slug={slug}
          onCancel={() => setOpen(false)}
          onPurged={onPurged}
        />
      ) : (
        <div className="grid gap-2">
          <p className="text-[11px] leading-relaxed text-muted">
            Remove os dados do banco, as imagens do storage e o cache. Os
            pedidos são apagados junto — se houver histórico fiscal a preservar,
            suspenda a loja em vez de excluí-la.
          </p>
          <button
            type="button"
            onClick={() => setOpen(true)}
            className="rounded-lg border border-red-300 px-3 py-2 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"
          >
            Excluir permanentemente
          </button>
        </div>
      )}
    </Section>
  );
}

function PurgeForm({
  slug,
  onCancel,
  onPurged,
}: {
  slug: string;
  onCancel: () => void;
  onPurged: () => void;
}) {
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [reason, setReason] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);

  const preview = useQuery({
    queryKey: ['platform', 'purge-preview', slug],
    queryFn: () => platformFetch<PurgePreview>(`/stores/${slug}/purge-preview`),
  });

  const purge = useMutation({
    mutationFn: () =>
      platformFetch<{ purged: boolean }>(`/stores/${slug}`, {
        method: 'DELETE',
        body: JSON.stringify({
          password,
          confirmation,
          reason: reason || null,
        }),
      }),
    onSuccess: onPurged,
    onError: (err) => {
      // 422 é exibido campo a campo; qualquer outro status (403, 429, 500)
      // sumiria da tela se não virasse mensagem geral.
      const validation = err instanceof ApiError && err.status === 422;

      setFieldErrors(validation ? (err.errors ?? {}) : {});
      setError(
        validation
          ? null
          : err instanceof ApiError
            ? err.message
            : 'Não foi possível excluir a loja.',
      );
    },
  });

  const counts = preview.data?.willDelete;

  return (
    <form
      className="grid gap-3"
      onSubmit={(event) => {
        event.preventDefault();
        purge.mutate();
      }}
    >
      {preview.isPending && (
        <p className="text-xs text-muted">Calculando o que será apagado…</p>
      )}

      {counts && (
        <ul className="grid gap-1 rounded-lg bg-red-50 px-3 py-2.5 text-xs text-red-800 dark:bg-red-950/30 dark:text-red-200">
          <li>
            <strong className="tabular-nums">{counts.orders}</strong> pedido(s) e{' '}
            <strong className="tabular-nums">{counts.payments}</strong>{' '}
            pagamento(s)
          </li>
          <li>
            <strong className="tabular-nums">{counts.products}</strong>{' '}
            produto(s) em{' '}
            <strong className="tabular-nums">{counts.categories}</strong>{' '}
            categoria(s)
          </li>
          <li>
            <strong className="tabular-nums">{counts.customers}</strong>{' '}
            cliente(s) e{' '}
            <strong className="tabular-nums">{counts.users}</strong> usuário(s)
          </li>
          <li>As imagens no storage e o cache do cardápio</li>
        </ul>
      )}

      {preview.data?.stripeConnected && (
        <p className="rounded-lg bg-line/60 px-3 py-2 text-[11px] leading-relaxed text-muted">
          A conta Stripe do lojista <strong>não</strong> é apagada — apenas
          desvinculada. Eventual saldo continua sendo dele.
        </p>
      )}

      <Field
        label="Sua senha"
        error={fieldErrors.password?.[0]}
        hint="Confirma que é você, e não uma sessão esquecida aberta."
      >
        <input
          type="password"
          required
          autoComplete="current-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          className="field"
        />
      </Field>

      <Field
        label={`Digite “${slug}” para confirmar`}
        error={fieldErrors.confirmation?.[0]}
      >
        <input
          type="text"
          required
          autoComplete="off"
          value={confirmation}
          onChange={(event) => setConfirmation(event.target.value)}
          className="field font-mono"
          placeholder={slug}
        />
      </Field>

      <Field label="Motivo (opcional)" hint="Fica registrado na auditoria.">
        <textarea
          rows={2}
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          className="field"
        />
      </Field>

      {error && (
        <p role="alert" className="text-xs text-red-600">
          {error}
        </p>
      )}

      <div className="flex gap-2">
        <button
          type="submit"
          // O slug digitado é conferido no servidor de qualquer forma; travar
          // o botão evita a ida perdida e o susto de um 422.
          disabled={purge.isPending || confirmation !== slug}
          className="rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700 disabled:opacity-50"
        >
          {purge.isPending ? 'Excluindo…' : 'Excluir permanentemente'}
        </button>

        <button
          type="button"
          onClick={onCancel}
          disabled={purge.isPending}
          className="rounded-lg border border-line px-3 py-2 text-xs font-medium disabled:opacity-50"
        >
          Cancelar
        </button>
      </div>
    </form>
  );
}
