import Link from 'next/link';
import { formatMoney } from '@/lib/api';
import { mapsSearchUrl, osmEmbedUrl, type Coordinates } from '@/lib/geocode';
import { storeHref } from '@/lib/store-url';
import { formatSchedule, hasAnyHours, weekFromToday } from '@/lib/hours';
import {
  FULFILLMENT_NOUNS,
  PAYMENT_LABELS,
  WEEKDAY_LABELS,
  WEEKDAY_SHORT,
  type Fulfillment,
  type TenantInfo,
} from '@/lib/types';
import { StoreStatus } from './store-status';

/**
 * Página "sobre a loja": endereço, horário, contato e modalidades.
 *
 * Server Component. Só o selo de aberto/fechado precisa de JS, e ele é um
 * componente client isolado — o resto entra pronto no HTML e é indexável, que
 * é metade do motivo desta página existir: hoje "pizzaria tal endereço" não
 * encontra nada, porque o cardápio não publica esses dados em texto.
 */
export async function StoreProfile({
  tenant,
  coordinates,
}: {
  tenant: TenantInfo;
  coordinates: Coordinates | null;
}) {
  const menuHref = await storeHref(tenant.slug);
  const hasHours = hasAnyHours(tenant.businessHours);
  const week = weekFromToday(tenant.businessHours);

  return (
    <div className="pb-16">
      <ProfileHeader tenant={tenant} menuHref={menuHref} />

      <main className="mx-auto max-w-3xl px-4">
        {tenant.description && (
          <p className="mt-6 text-[15px] leading-relaxed text-muted">
            {tenant.description}
          </p>
        )}

        {tenant.acceptsOrders && tenant.fulfillments.length > 0 && (
          <Section title="Como pedir">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              {tenant.fulfillments.map((kind) => (
                <FulfillmentTile
                  key={kind}
                  kind={kind}
                  tenant={tenant}
                />
              ))}
            </div>
          </Section>
        )}

        {tenant.address && (
          <Section title="Localização">
            <LocationCard address={tenant.address} coordinates={coordinates} />
          </Section>
        )}

        {(tenant.phone || tenant.whatsapp) && (
          <Section title="Contato">
            <div className="grid gap-3 sm:grid-cols-2">
              {tenant.phone && (
                <ContactRow
                  href={`tel:${digitsOf(tenant.phone)}`}
                  label="Telefone"
                  value={tenant.phone}
                  icon={<PhoneIcon />}
                />
              )}

              {tenant.whatsapp && (
                <ContactRow
                  href={`https://wa.me/${whatsappNumber(tenant.whatsapp)}`}
                  label="WhatsApp"
                  value={tenant.whatsapp}
                  icon={<WhatsAppIcon />}
                  external
                />
              )}
            </div>
          </Section>
        )}

        {hasHours && (
          <Section title="Horário de funcionamento">
            <ul className="surface-card overflow-hidden">
              {week.map(({ day, schedule, isToday }) => (
                <li
                  key={day}
                  className="flex items-center justify-between gap-4 border-b px-4 py-3 text-sm last:border-b-0"
                  style={{
                    borderColor: 'var(--hairline)',
                    background: isToday
                      ? 'rgb(var(--brand-soft) / 0.45)'
                      : undefined,
                  }}
                >
                  <span
                    className={isToday ? 'font-semibold' : 'font-medium'}
                    style={isToday ? { color: 'rgb(var(--brand))' } : undefined}
                  >
                    {/* O nome curto evita quebra de linha no celular; o
                        completo fica para leitores de tela. */}
                    <span aria-hidden className="sm:hidden">
                      {WEEKDAY_SHORT[day]}
                    </span>
                    <span className="max-sm:sr-only">{WEEKDAY_LABELS[day]}</span>
                    {isToday && <span className="ml-2 text-xs">· Hoje</span>}
                  </span>

                  <span
                    className={
                      schedule
                        ? isToday
                          ? 'font-semibold tabular-nums'
                          : 'tabular-nums text-muted'
                        : 'text-subtle'
                    }
                  >
                    {formatSchedule(schedule)}
                  </span>
                </li>
              ))}
            </ul>
          </Section>
        )}

        {tenant.acceptsOrders && tenant.paymentMethods.length > 0 && (
          <Section title="Formas de pagamento">
            <div className="flex flex-wrap gap-2">
              {tenant.paymentMethods.map((method) => (
                <span
                  key={method}
                  className="border px-3 py-1.5 text-sm font-medium"
                  style={{
                    borderColor: 'var(--hairline)',
                    borderRadius: 'calc(var(--radius) * 0.5)',
                  }}
                >
                  {PAYMENT_LABELS[method] ?? method}
                </span>
              ))}
            </div>
          </Section>
        )}

        <Link
          href={menuHref}
          className="mt-10 flex w-full items-center justify-center gap-2 px-5 py-3.5 text-sm font-semibold transition-opacity hover:opacity-90"
          style={{
            background: 'rgb(var(--brand))',
            color: 'rgb(var(--brand-ink))',
            borderRadius: 'calc(var(--radius) * 0.75)',
          }}
        >
          Ver o cardápio
          <svg
            className="size-4"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            viewBox="0 0 24 24"
            aria-hidden
          >
            <path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
          </svg>
        </Link>
      </main>
    </div>
  );
}

/** Capa, logo e nome, com o selo de status e o caminho de volta. */
/**
 * `menuHref` chega pronto do pai em vez de ser resolvido aqui: este componente
 * é síncrono e usar headers() dentro dele o tornaria assíncrono sem ganho.
 */
function ProfileHeader({
  tenant,
  menuHref,
}: {
  tenant: TenantInfo;
  menuHref: string;
}) {
  const initials = tenant.name
    .split(' ')
    .slice(0, 2)
    .map((word) => word[0])
    .join('')
    .toUpperCase();

  return (
    <header className="relative">
      <div
        className="h-36 w-full sm:h-52"
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

      {/* Volta para o cardápio. Absoluto e montado a partir do prefixo real:
          "./" resolvia certo no subdomínio e errado no acesso por caminho,
          onde a partir de /pizzaria/loja ele apontava para /pizzaria/ apenas
          por sorte da barra — e para a landing quando ela faltava. */}
      <Link
        href={menuHref}
        aria-label="Voltar ao cardápio"
        className="absolute left-4 top-4 grid size-10 place-items-center rounded-full shadow-soft backdrop-blur transition-transform hover:scale-105"
        style={{ background: 'rgb(var(--surface) / 0.92)' }}
      >
        <svg
          className="size-5"
          fill="none"
          stroke="currentColor"
          strokeWidth={2}
          viewBox="0 0 24 24"
          aria-hidden
        >
          <path strokeLinecap="round" strokeLinejoin="round" d="M19 12H5m6-6-6 6 6 6" />
        </svg>
      </Link>

      <div className="mx-auto max-w-3xl px-4">
        <div
          className="-mt-12 grid size-24 place-items-center overflow-hidden border-4 bg-[rgb(var(--surface))] text-2xl font-semibold shadow-soft"
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

        <div className="mt-5 flex flex-wrap items-center gap-x-3 gap-y-2">
          <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
            {tenant.name}
          </h1>

          <StoreStatus isOpen={tenant.isOpen} hours={tenant.businessHours} />
        </div>
      </div>
    </header>
  );
}

function Section({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section className="mt-8">
      <h2 className="mb-3 text-xs font-semibold uppercase tracking-[0.08em] text-subtle">
        {title}
      </h2>
      {children}
    </section>
  );
}

/**
 * Mapa embutido do OpenStreetMap, com o endereço abaixo.
 *
 * Sem coordenadas o iframe é omitido: um mapa apontando para o lugar errado é
 * pior do que mapa nenhum. O endereço em texto e o botão continuam ali.
 */
function LocationCard({
  address,
  coordinates,
}: {
  address: string;
  coordinates: Coordinates | null;
}) {
  return (
    <div className="surface-card overflow-hidden">
      {coordinates && (
        <iframe
          src={osmEmbedUrl(coordinates)}
          title={`Mapa da localização: ${address}`}
          loading="lazy"
          referrerPolicy="no-referrer-when-downgrade"
          // A altura folgada é de propósito: o embed do OSM ancora a barra de
          // atribuição no rodapé do próprio iframe, e num quadro baixo ela
          // cobre justamente o pin.
          className="block h-64 w-full border-0 sm:h-72"
        />
      )}

      <div className="flex items-start gap-3 p-4">
        <span className="mt-0.5 shrink-0" style={{ color: 'rgb(var(--brand))' }}>
          <svg
            className="size-5"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.7}
            viewBox="0 0 24 24"
            aria-hidden
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11z"
            />
            <circle cx="12" cy="10" r="2.6" />
          </svg>
        </span>

        <div className="min-w-0 flex-1">
          <address className="text-sm not-italic leading-relaxed">
            {address}
          </address>

          <a
            href={mapsSearchUrl(address)}
            target="_blank"
            rel="noreferrer"
            className="mt-2 inline-flex items-center gap-1 text-sm font-semibold"
            style={{ color: 'rgb(var(--brand))' }}
          >
            Como chegar
            <svg
              className="size-3.5"
              fill="none"
              stroke="currentColor"
              strokeWidth={2}
              viewBox="0 0 24 24"
              aria-hidden
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M7 17 17 7m0 0H8m9 0v9"
              />
            </svg>
          </a>
        </div>
      </div>
    </div>
  );
}

function ContactRow({
  href,
  label,
  value,
  icon,
  external,
}: {
  href: string;
  label: string;
  value: string;
  icon: React.ReactNode;
  external?: boolean;
}) {
  return (
    <a
      href={href}
      {...(external ? { target: '_blank', rel: 'noreferrer' } : {})}
      className="surface-card flex items-center gap-3 p-4 transition-colors hover:bg-[rgb(var(--brand-soft)/0.35)]"
    >
      <span className="shrink-0" style={{ color: 'rgb(var(--brand))' }}>
        {icon}
      </span>

      <span className="min-w-0">
        <span className="block text-xs text-subtle">{label}</span>
        <span className="block truncate text-sm font-semibold">{value}</span>
      </span>
    </a>
  );
}

/** Cartão de uma modalidade, com a condição que a diferencia das demais. */
function FulfillmentTile({
  kind,
  tenant,
}: {
  kind: Fulfillment;
  tenant: TenantInfo;
}) {
  const { fee_cents: fee, eta_minutes: eta } = tenant.deliveryConfig;

  const detail =
    kind === 'delivery'
      ? fee === 0
        ? 'Grátis'
        : fee !== undefined
          ? formatMoney(fee)
          : null
      : eta
        ? `~${eta} min`
        : null;

  return (
    <div
      className="flex flex-col items-center gap-2 border px-3 py-4 text-center"
      style={{
        borderColor: 'var(--hairline)',
        borderRadius: 'calc(var(--radius) * 0.65)',
      }}
    >
      <span style={{ color: 'rgb(var(--brand))' }}>
        <FulfillmentGlyph kind={kind} />
      </span>

      <span className="text-sm font-semibold leading-tight">
        {FULFILLMENT_NOUNS[kind]}
      </span>

      {detail && <span className="text-xs text-muted">{detail}</span>}
    </div>
  );
}

function FulfillmentGlyph({ kind }: { kind: Fulfillment }) {
  const common = {
    className: 'size-6',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.6,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    viewBox: '0 0 24 24',
    'aria-hidden': true,
  };

  if (kind === 'delivery') {
    return (
      <svg {...common}>
        <circle cx="6" cy="17" r="2.6" />
        <circle cx="18" cy="17" r="2.6" />
        <path d="M8.6 17h6.8M6 14.4 9 7h3.6l3 7.4M12.6 7h3.2l2.2 7.4" />
      </svg>
    );
  }

  if (kind === 'pickup') {
    return (
      <svg {...common}>
        <path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1z" />
        <path d="M9.5 21v-6h5v6" />
      </svg>
    );
  }

  return (
    <svg {...common}>
      <path d="M5 3v7a2.5 2.5 0 0 0 5 0V3M7.5 10v11" />
      <path d="M17.5 3c-1.7 1.6-2.5 3.6-2.5 6s.8 3.4 2.5 3.4h1V3z" />
      <path d="M18.5 12.4V21" />
    </svg>
  );
}

function PhoneIcon() {
  return (
    <svg
      className="size-5"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.7}
      viewBox="0 0 24 24"
      aria-hidden
    >
      <path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M6.5 3.5h3l1.5 4-2 1.5a12 12 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 5.7 2 2 0 0 1 6.5 3.5z"
      />
    </svg>
  );
}

function WhatsAppIcon() {
  return (
    <svg className="size-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden>
      <path d="M17.5 14.4c-.3-.2-1.7-.9-2-1-.3-.1-.5-.1-.7.2-.2.3-.7 1-.9 1.1-.2.2-.3.2-.6.1-1.6-.8-2.7-1.5-3.8-3.3-.3-.5.3-.5.8-1.5.1-.2 0-.4 0-.5 0-.2-.7-1.6-.9-2.2-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1 1-1 2.5s1.1 2.9 1.2 3.1c.1.2 2.1 3.2 5.1 4.5 1.9.8 2.6.9 3.5.7.6-.1 1.7-.7 1.9-1.3.2-.7.2-1.2.2-1.3-.1-.2-.3-.2-.6-.4z" />
      <path d="M12 2a10 10 0 0 0-8.6 15L2 22l5.2-1.4A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3.1.8.8-3-.2-.3A8.2 8.2 0 1 1 12 20.2z" />
    </svg>
  );
}

function digitsOf(value: string): string {
  return value.replace(/\D/g, '');
}

/** wa.me exige DDI; números brasileiros são digitados sem ele no painel. */
function whatsappNumber(value: string): string {
  const digits = digitsOf(value);

  return digits.startsWith('55') ? digits : `55${digits}`;
}
