import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ApiError, apiFetch, clearSession, isOwner } from '@/lib/api';
import { Field, Section } from '@/components/ui';

type DeletionPreview = {
  store: { name: string; slug: string };
  willLose: { products: number; orders: number; users: number };
  stripeConnected: boolean;
};

/**
 * Zona de perigo: exclusão da loja.
 *
 * O preview é carregado só quando o lojista abre o diálogo — é uma contagem
 * que ninguém precisa pagar ao abrir a página de configurações.
 */
export function DeleteStore() {
  const [open, setOpen] = useState(false);

  // Esconder para quem não é dono evita oferecer um caminho que termina em 403.
  // Não é controle de acesso — esse fica no servidor.
  if (!isOwner()) return null;

  return (
    <Section
      title="Excluir loja"
      description="Encerra a loja e remove o acesso de todos os usuários."
    >
      {open ? (
        <DeleteDialog onCancel={() => setOpen(false)} />
      ) : (
        <div className="flex items-center justify-between gap-4">
          <p className="text-xs text-muted">
            A loja sai do ar imediatamente. O histórico de pedidos é preservado
            para fins fiscais, e o endereço da loja não volta a ficar
            disponível.
          </p>
          <button
            type="button"
            onClick={() => setOpen(true)}
            className="shrink-0 rounded-lg border border-red-300 px-3 py-2 text-xs font-medium text-red-600 hover:bg-red-50"
          >
            Excluir loja
          </button>
        </div>
      )}
    </Section>
  );
}

function DeleteDialog({ onCancel }: { onCancel: () => void }) {
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [reason, setReason] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);

  const preview = useQuery({
    queryKey: ['store-deletion-preview'],
    queryFn: () => apiFetch<DeletionPreview>('/admin/store/deletion-preview'),
  });

  const remove = useMutation({
    mutationFn: () =>
      apiFetch<{ deleted: boolean }>('/admin/store', {
        method: 'DELETE',
        body: JSON.stringify({ password, confirmation, reason: reason || null }),
      }),
    onSuccess: () => {
      // A sessão morreu junto com a loja: o token foi revogado no servidor.
      // Recarregar em vez de navegar garante que nenhum estado em memória
      // sobreviva apontando para uma loja que não existe mais.
      clearSession();
      window.location.href = '/';
    },
    onError: (err) => {
      // 422 já é exibido campo a campo; qualquer outro status vira mensagem
      // geral, senão o erro (403, 429, 500) sumiria da tela.
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

  const slug = preview.data?.store.slug ?? '';
  const canSubmit =
    password.length > 0 && confirmation === slug && !remove.isPending;

  return (
    <div className="grid gap-4 rounded-xl border border-red-200 bg-red-50/50 p-4">
      {preview.isLoading && <p className="text-xs text-muted">Carregando…</p>}

      {preview.data && (
        <>
          <div className="text-xs text-fg">
            <p className="font-medium">
              Isto encerra a loja {preview.data.store.name} e não pode ser
              desfeito por aqui.
            </p>
            <ul className="mt-2 grid gap-1 text-muted">
              <li>
                {preview.data.willLose.products} produto(s) e{' '}
                {preview.data.willLose.orders} pedido(s) saem do ar
              </li>
              <li>
                {preview.data.willLose.users} usuário(s) perdem o acesso
                imediatamente
              </li>
              <li>
                O endereço <strong>{slug}</strong> fica reservado e não poderá
                ser reutilizado
              </li>
              {preview.data.stripeConnected && (
                <li>
                  Sua conta Stripe <strong>não</strong> é excluída. Saldos a
                  receber continuam seus e são repassados normalmente.
                </li>
              )}
            </ul>
          </div>

          <Field
            label="Sua senha"
            error={fieldErrors.password?.[0]}
          >
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="field"
              autoComplete="current-password"
            />
          </Field>

          <Field
            label={`Digite “${slug}” para confirmar`}
            error={fieldErrors.confirmation?.[0]}
          >
            <input
              value={confirmation}
              onChange={(e) => setConfirmation(e.target.value)}
              className="field"
              autoComplete="off"
            />
          </Field>

          <Field label="Motivo (opcional)">
            <textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              className="field"
              rows={2}
              placeholder="Ajuda a gente a melhorar."
            />
          </Field>

          {error && (
            <p role="alert" className="text-xs text-red-600">
              {error}
            </p>
          )}

          <div className="flex items-center justify-end gap-2">
            <button
              type="button"
              onClick={onCancel}
              className="rounded-lg border border-line px-3 py-2 text-xs font-medium"
            >
              Cancelar
            </button>
            <button
              type="button"
              disabled={!canSubmit}
              onClick={() => remove.mutate()}
              className="rounded-lg bg-red-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-40"
            >
              {remove.isPending ? 'Excluindo…' : 'Excluir permanentemente'}
            </button>
          </div>
        </>
      )}
    </div>
  );
}
