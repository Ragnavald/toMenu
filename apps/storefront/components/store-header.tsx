import Link from 'next/link';
import { formatMoney } from '@/lib/api';
import { storeHref } from '@/lib/store-url';
import type { TenantInfo } from '@/lib/types';
import { OrderTrackingButton } from './order-tracking-button';

/** Server Component: sem JS no cliente, entra direto no HTML inicial. */
export async function StoreHeader({ tenant }: { tenant: TenantInfo }) {
  const { deliveryConfig: config } = tenant;
  const profileHref = await storeHref(tenant.slug, 'loja');
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
        {/*
          Logo e botão dividem a primeira faixa; o nome ocupa a largura inteira
          logo abaixo. Enquanto os três disputavam a mesma linha, um aparelho de
          360px não tinha espaço para todos e o nome da loja era sempre quem
          cedia — "Forno di Napoli" chegava ao cliente como "Forno di ...".
        */}
        <div className="-mt-10 flex items-end justify-between gap-4 sm:-mt-12">
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

          {/* Vitrine não tem pedido para acompanhar. */}
          {tenant.acceptsOrders && (
            <div className="pb-1">
              <OrderTrackingButton tenantSlug={tenant.slug} />
            </div>
          )}
        </div>

        {/* O nome leva à página da loja: endereço, horário e contato.
            A seta é o que sinaliza que o título é navegável — sem ela o
            cliente não descobre que existe algo além do cardápio.

            O href é absoluto e montado a partir do prefixo real (ver
            storeHref). Já foi relativo — "loja" — e quebrava no acesso por
            caminho: a partir de to-menu.com/pizzaria, sem barra final, o
            navegador troca o último segmento e o link vira to-menu.com/loja,
            que é 404. */}
        <h1 className="mt-4 text-2xl font-semibold tracking-tight sm:text-3xl">
          <Link href={profileHref} className="group inline-flex items-start gap-1.5">
            <span>{tenant.name}</span>

            {/* shrink-0: a seta é o sinal de que dá para navegar; se encolher
                junto com o texto ela some justamente no caso apertado, que é
                quando mais se precisa dela. */}
            <svg
              className="mt-1 size-5 shrink-0 transition-transform group-hover:translate-x-0.5"
              style={{ color: 'rgb(var(--brand))' }}
              fill="none"
              stroke="currentColor"
              strokeWidth={2.2}
              viewBox="0 0 24 24"
              aria-hidden
            >
              <path strokeLinecap="round" strokeLinejoin="round" d="m9 6 6 6-6 6" />
            </svg>

            <span className="sr-only">Ver informações da loja</span>
          </Link>
        </h1>

        {/* No plano somente-cardápio o cabeçalho troca as condições de entrega
            — que não existem ali — pelo contato e endereço da loja, que é o que
            o visitante precisa para chegar até ela ou ligar. */}
        {!tenant.acceptsOrders ? (
          <dl className="mt-5 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm font-medium">
            {tenant.phone ? (
              <div className="flex items-center gap-2">
                <span
                  aria-hidden
                  className="size-1.5 rounded-full"
                  style={{ background: 'rgb(var(--brand))' }}
                />
                <dt className="sr-only">Telefone</dt>
                <dd>
                  <a href={`tel:${tenant.phone.replace(/\D/g, '')}`} className="text-muted">
                    {tenant.phone}
                  </a>
                </dd>
              </div>
            ) : null}

            {tenant.address ? (
              <div>
                <dt className="sr-only">Endereço</dt>
                <dd className="text-muted">{tenant.address}</dd>
              </div>
            ) : null}
          </dl>
        ) : (
        <dl className="mt-5 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm font-medium">
          {config.eta_minutes ? (
            <div className="flex items-center gap-2">
              <span
                aria-hidden
                className="size-1.5 rounded-full"
                style={{ background: 'rgb(var(--brand))' }}
              />
              <dt className="sr-only">Tempo estimado</dt>
              <dd className="text-muted">
                {config.eta_minutes} min
              </dd>
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
        )}
      </div>
    </header>
  );
}
