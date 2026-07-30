import { formatMoney } from '@/lib/api';
import type { TenantInfo } from '@/lib/types';

/** Server Component: sem JS no cliente, entra direto no HTML inicial. */
export function StoreHeader({ tenant }: { tenant: TenantInfo }) {
  const { deliveryConfig: config } = tenant;
  const initials = tenant.name
    .split(' ')
    .slice(0, 2)
    .map((word) => word[0])
    .join('')
    .toUpperCase();

  return (
    <header className="relative">
      {/* Faixa de marca: o gradiente usa a cor do tenant, então cada loja
          tem uma capa distinta mesmo sem enviar imagem alguma. */}
      <div
        className="h-32 w-full sm:h-44"
        style={{
          background:
            'linear-gradient(135deg, rgb(var(--brand) / 0.92), rgb(var(--brand) / 0.55) 55%, rgb(var(--brand-soft)))',
        }}
      >
        {tenant.coverUrl && (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={tenant.coverUrl}
            alt=""
            className="h-full w-full object-cover"
          />
        )}
      </div>

      <div className="mx-auto max-w-3xl px-4">
        <div className="-mt-10 flex items-end gap-4 sm:-mt-12">
          <div
            className="grid size-20 shrink-0 place-items-center overflow-hidden border-4 bg-[rgb(var(--surface))] text-xl font-semibold shadow-soft sm:size-24"
            style={{
              borderColor: 'rgb(var(--surface))',
              borderRadius: 'var(--radius)',
              color: 'rgb(var(--brand))',
            }}
          >
            {tenant.logoUrl ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={tenant.logoUrl}
                alt={tenant.name}
                className="size-full object-cover"
              />
            ) : (
              initials
            )}
          </div>

          <div className="min-w-0 flex-1 pb-1">
            <h1 className="truncate text-2xl font-semibold tracking-tight sm:text-3xl">
              {tenant.name}
            </h1>
          </div>
        </div>

        <dl className="mt-5 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
          {config.eta_minutes ? (
            <div className="flex items-center gap-2">
              <span
                aria-hidden
                className="size-1.5 rounded-full"
                style={{ background: 'rgb(var(--brand))' }}
              />
              <dt className="sr-only">Tempo estimado</dt>
              <dd className="text-muted">{config.eta_minutes} min</dd>
            </div>
          ) : null}

          {config.fee_cents !== undefined ? (
            <div>
              <dt className="sr-only">Taxa de entrega</dt>
              <dd className="text-muted">
                Entrega {config.fee_cents === 0 ? 'grátis' : formatMoney(config.fee_cents)}
              </dd>
            </div>
          ) : null}

          {config.min_order_cents ? (
            <div>
              <dt className="sr-only">Pedido mínimo</dt>
              <dd className="text-muted">
                Mínimo {formatMoney(config.min_order_cents)}
              </dd>
            </div>
          ) : null}
        </dl>
      </div>
    </header>
  );
}
