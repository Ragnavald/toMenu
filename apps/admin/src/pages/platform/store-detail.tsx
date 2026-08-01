import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ApiError } from '@/lib/api';
import {
  formatDate,
  platformFetch,
  type StoreDetail,
} from '@/lib/platform';
import { PageHeader, Section } from '@/components/ui';
import { StatusPill } from '@/components/platform/status-pill';
import { PurgeStoreDialog } from '@/components/platform/purge-store';
import { ImpersonateButton } from '@/components/platform/impersonate';

export function PlatformStoreDetailPage() {
  const { slug = '' } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [actionError, setActionError] = useState<string | null>(null);

  const query = useQuery({
    queryKey: ['platform', 'store', slug],
    queryFn: () => platformFetch<StoreDetail>(`/stores/${slug}`),
  });

  const toggleStatus = useMutation({
    mutationFn: (next: 'suspend' | 'reactivate') =>
      platformFetch<{ status: string }>(`/stores/${slug}/${next}`, {
        method: 'POST',
        body: JSON.stringify({}),
      }),
    onSuccess: () => {
      setActionError(null);
      // Invalida o detalhe e a listagem: os contadores do topo da lista mudam
      // junto com a situação da loja.
      queryClient.invalidateQueries({ queryKey: ['platform'] });
    },
    onError: (error) =>
      setActionError(
        error instanceof ApiError
          ? error.message
          : 'Não foi possível alterar a situação da loja.',
      ),
  });

  if (query.isPending) {
    return <p className="text-sm text-muted">Carregando…</p>;
  }

  if (query.isError || !query.data) {
    return (
      <div>
        <p role="alert" className="panel p-4 text-sm text-red-600">
          {query.error instanceof ApiError && query.error.status === 404
            ? 'Loja não encontrada.'
            : 'Não foi possível carregar a loja.'}
        </p>
        <Link
          to="/plataforma/lojas"
          className="mt-3 inline-block text-sm text-accent"
        >
          Voltar para a lista
        </Link>
      </div>
    );
  }

  const { store, owner, audit } = query.data;
  const isDeleted = store.status === 'deleted';

  return (
    <div>
      <Link
        to="/plataforma/lojas"
        className="mb-3 inline-block text-xs text-muted hover:text-ink"
      >
        ← Todas as lojas
      </Link>

      <PageHeader
        title={store.name}
        description={store.slug}
        action={<StatusPill status={store.status} />}
      />

      {actionError && (
        <p role="alert" className="panel mb-4 p-3 text-sm text-red-600">
          {actionError}
        </p>
      )}

      <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
        <div className="grid gap-4">
          <Section title="Resumo">
            <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
              <Item label="Plano" value={store.plan ?? '—'} />
              <Item label="Pedidos" value={String(store.ordersCount)} />
              <Item label="Produtos" value={String(store.productsCount)} />
              <Item label="Criada em" value={formatDate(store.createdAt)} />
              <Item
                label="Último pedido"
                value={formatDate(store.lastOrderAt)}
              />
              <Item
                label="Onboarding"
                value={store.onboardingCompleted ? 'Concluído' : 'Pendente'}
              />
              <Item
                label="Stripe"
                value={store.stripeConnected ? 'Conectado' : 'Não conectado'}
              />
              <Item label="Segmento" value={store.segment ?? '—'} />
              <Item label="Telefone" value={store.phone ?? '—'} />
            </dl>

            {store.deletionReason && (
              <p className="mt-4 rounded-lg bg-line/50 px-3 py-2 text-xs text-muted">
                <span className="font-medium">Motivo da exclusão:</span>{' '}
                {store.deletionReason}
              </p>
            )}
          </Section>

          <Section
            title="Histórico da plataforma"
            description="Ações da equipe sobre esta loja."
          >
            {audit.length === 0 ? (
              <p className="text-xs text-muted">Nenhuma ação registrada.</p>
            ) : (
              <ul className="grid gap-2">
                {audit.map((entry) => (
                  <li
                    key={entry.id}
                    className="flex flex-wrap items-baseline justify-between gap-2 border-b border-line/60 pb-2 text-xs last:border-0 last:pb-0"
                  >
                    <span className="font-medium">
                      {AUDIT_LABEL[entry.action] ?? entry.action}
                    </span>
                    <span className="text-muted">
                      {entry.actor ?? 'sistema'} · {formatDate(entry.createdAt)}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Section>
        </div>

        <div className="grid gap-4 self-start">
          <Section title="Responsável">
            {owner ? (
              <div className="text-sm">
                <p className="font-medium">{owner.name}</p>
                <p className="text-xs text-muted">{owner.email}</p>
              </div>
            ) : (
              <p className="text-xs text-muted">
                Esta loja não tem usuários.
              </p>
            )}
          </Section>

          <Section
            title="Ações"
            description="Acesso, suspensão e exclusão."
          >
            <div className="grid gap-2">
              <a
                href={store.storefrontUrl}
                target="_blank"
                rel="noreferrer"
                className="rounded-lg border border-line px-3 py-2 text-center text-xs font-medium hover:bg-line/60"
              >
                Ver a loja
              </a>

              <ImpersonateButton
                slug={store.slug}
                disabled={isDeleted || store.status === 'suspended'}
                onError={setActionError}
              />

              {/* Loja excluída não volta: reativar exige linha viva. */}
              {!isDeleted &&
                (store.status === 'suspended' ? (
                  <button
                    type="button"
                    disabled={toggleStatus.isPending}
                    onClick={() => toggleStatus.mutate('reactivate')}
                    className="rounded-lg border border-emerald-300 px-3 py-2 text-xs font-medium text-emerald-700 hover:bg-emerald-50 disabled:opacity-60 dark:text-emerald-300 dark:hover:bg-emerald-950/30"
                  >
                    {toggleStatus.isPending ? 'Reativando…' : 'Reativar loja'}
                  </button>
                ) : (
                  <button
                    type="button"
                    disabled={toggleStatus.isPending}
                    onClick={() => toggleStatus.mutate('suspend')}
                    className="rounded-lg border border-amber-300 px-3 py-2 text-xs font-medium text-amber-800 hover:bg-amber-50 disabled:opacity-60 dark:text-amber-200 dark:hover:bg-amber-950/30"
                  >
                    {toggleStatus.isPending ? 'Suspendendo…' : 'Suspender loja'}
                  </button>
                ))}
            </div>

            <p className="mt-2 text-[11px] leading-relaxed text-muted">
              Suspender tira a loja do ar sem apagar nada, e é reversível.
            </p>
          </Section>

          <PurgeStoreDialog
            slug={store.slug}
            onPurged={() => {
              queryClient.invalidateQueries({ queryKey: ['platform'] });
              navigate('/plataforma/lojas');
            }}
          />
        </div>
      </div>
    </div>
  );
}

const AUDIT_LABEL: Record<string, string> = {
  impersonate: 'Acessou o painel da loja',
  suspend: 'Suspendeu a loja',
  reactivate: 'Reativou a loja',
  purge: 'Excluiu permanentemente',
};

function Item({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-muted">{label}</dt>
      <dd className="mt-0.5 font-medium">{value}</dd>
    </div>
  );
}
