import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '@/lib/api';
import { DAYS, PAYMENT_LABELS, type Category, type Settings } from '@/lib/types';
import { Field, MoneyInput, Toggle } from '@/components/ui';

const STEPS = [
  { n: 1, title: 'Sua loja', hint: 'Como os clientes vão te encontrar' },
  { n: 2, title: 'Entrega', hint: 'Taxa, pedido mínimo e tempo' },
  { n: 3, title: 'Pagamento', hint: 'Como você recebe' },
  { n: 4, title: 'Horários', hint: 'Quando aceita pedidos' },
  { n: 5, title: 'Cardápio', hint: 'Primeiros itens' },
];

export function OnboardingPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [step, setStep] = useState(1);
  const [error, setError] = useState<string | null>(null);

  const { data: settings, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: () => apiFetch<Settings>('/admin/settings'),
  });

  const { data: categories } = useQuery({
    queryKey: ['categories'],
    queryFn: () => apiFetch<{ data: Category[] }>('/admin/categories'),
  });

  // Retoma de onde o dono parou, em vez de recomeçar do primeiro passo.
  useEffect(() => {
    if (settings) setStep(Math.min(Math.max(settings.store.onboardingStep, 1), 5));
  }, [settings]);

  const advance = useMutation({
    mutationFn: (payload: { step: number; complete?: boolean }) =>
      apiFetch('/admin/settings/onboarding', {
        method: 'PUT',
        body: JSON.stringify(payload),
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['settings'] }),
  });

  if (isLoading || !settings) {
    return <p className="text-sm text-muted">Carregando…</p>;
  }

  async function goTo(next: number) {
    setError(null);

    if (next > 5) {
      await advance.mutateAsync({ step: 5, complete: true });
      queryClient.invalidateQueries();
      navigate('/pedidos');
      return;
    }

    await advance.mutateAsync({ step: next });
    setStep(next);
  }

  return (
    <div>
      <header className="mb-6">
        <h1 className="text-xl font-semibold tracking-tight">
          Vamos deixar sua loja pronta
        </h1>
        <p className="mt-1 text-sm text-muted">
          Cinco passos rápidos. Você pode mudar tudo depois em Configurações.
        </p>
      </header>

      {/* Trilha de progresso: mostra onde está e o que falta. */}
      <ol className="mb-6 flex items-center gap-1.5">
        {STEPS.map((item) => {
          const done = item.n < step;
          const current = item.n === step;

          return (
            <li key={item.n} className="flex-1">
              <button
                type="button"
                // Só permite voltar: pular passos à frente deixaria a loja
                // publicada sem dados essenciais.
                onClick={() => item.n < step && setStep(item.n)}
                disabled={item.n > step}
                className="w-full text-left"
              >
                <span
                  className={`block h-1 rounded-full transition-colors ${
                    done || current ? 'bg-accent' : 'bg-line'
                  }`}
                />
                <span
                  className={`mt-1.5 hidden text-[11px] font-medium sm:block ${
                    current ? 'text-accent' : 'text-muted'
                  }`}
                >
                  {item.title}
                </span>
              </button>
            </li>
          );
        })}
      </ol>

      <div className="panel p-5">
        <p className="text-xs font-medium uppercase tracking-wide text-muted">
          Passo {step} de 5
        </p>
        <h2 className="mt-1 text-base font-semibold">{STEPS[step - 1].title}</h2>
        <p className="mt-0.5 text-sm text-muted">{STEPS[step - 1].hint}</p>

        <div className="mt-5">
          {step === 1 && <StoreStep settings={settings} onError={setError} />}
          {step === 2 && <DeliveryStep settings={settings} onError={setError} />}
          {step === 3 && <PaymentStep settings={settings} onError={setError} />}
          {step === 4 && <HoursStep settings={settings} onError={setError} />}
          {step === 5 && (
            <MenuStep
              categories={categories?.data ?? []}
              storefrontUrl={settings.store.storefrontUrl}
            />
          )}
        </div>

        {error && (
          <p role="alert" className="mt-4 text-xs text-red-600">
            {error}
          </p>
        )}

        <div className="mt-6 flex items-center justify-between gap-3 border-t border-line pt-4">
          <button
            type="button"
            onClick={() => setStep((s) => Math.max(1, s - 1))}
            disabled={step === 1}
            className="rounded-lg px-3 py-2 text-sm font-medium text-muted transition-colors hover:bg-line disabled:opacity-40"
          >
            Voltar
          </button>

          <div className="flex items-center gap-2">
            {step < 5 && (
              <button
                type="button"
                onClick={() => goTo(step + 1)}
                className="rounded-lg px-3 py-2 text-sm text-muted hover:bg-line"
              >
                Pular
              </button>
            )}

            <button
              type="button"
              onClick={() => goTo(step + 1)}
              disabled={advance.isPending}
              className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
            >
              {step === 5 ? 'Concluir e publicar' : 'Continuar'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ passos */

function StoreStep({
  settings,
  onError,
}: {
  settings: Settings;
  onError: (message: string | null) => void;
}) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    name: settings.store.name,
    whatsapp: settings.profile.whatsapp ?? '',
    address: settings.profile.address ?? '',
    description: settings.profile.description ?? '',
  });

  const save = useMutation({
    mutationFn: () =>
      apiFetch('/admin/settings/profile', {
        method: 'PUT',
        body: JSON.stringify({
          name: form.name,
          whatsapp: form.whatsapp || null,
          address: form.address || null,
          description: form.description || null,
          phone: settings.profile.phone,
        }),
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['settings'] }),
    onError: () => onError('Não foi possível salvar os dados da loja.'),
  });

  return (
    <div className="grid gap-3.5" onBlur={() => save.mutate()}>
      <Field label="Nome do restaurante">
        <input
          value={form.name}
          onChange={(e) => setForm({ ...form, name: e.target.value })}
          className="field"
        />
      </Field>

      <Field
        label="Endereço da sua loja online"
        hint="Este é o link que você compartilha com os clientes."
      >
        <input
          readOnly
          value={settings.store.storefrontUrl}
          className="field bg-line/40 text-muted"
        />
      </Field>

      <Field
        label="WhatsApp da loja"
        hint="Para onde enviamos o aviso de cada pedido novo."
      >
        <input
          value={form.whatsapp}
          onChange={(e) => setForm({ ...form, whatsapp: e.target.value })}
          className="field"
          placeholder="(11) 99999-9999"
        />
      </Field>

      <Field label="Endereço físico">
        <input
          value={form.address}
          onChange={(e) => setForm({ ...form, address: e.target.value })}
          className="field"
          placeholder="Rua das Flores, 100 — Centro"
        />
      </Field>

      <Field label="Descrição curta">
        <textarea
          value={form.description}
          onChange={(e) => setForm({ ...form, description: e.target.value })}
          className="field min-h-20 resize-none"
          placeholder="Comida italiana feita na hora, com massa fresca."
        />
      </Field>
    </div>
  );
}

function DeliveryStep({
  settings,
  onError,
}: {
  settings: Settings;
  onError: (message: string | null) => void;
}) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState(settings.delivery);

  const save = useMutation({
    mutationFn: (next: typeof form) =>
      apiFetch('/admin/settings/delivery', {
        method: 'PUT',
        body: JSON.stringify(next),
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['settings'] }),
    onError: () => onError('Não foi possível salvar as regras de entrega.'),
  });

  function update(patch: Partial<typeof form>) {
    const next = { ...form, ...patch };
    setForm(next);
    save.mutate(next);
  }

  return (
    <div className="grid gap-4">
      <div className="grid gap-3.5 sm:grid-cols-2">
        <Field label="Taxa de entrega" hint="Deixe R$ 0,00 para entrega grátis.">
          <MoneyInput
            valueCents={form.feeCents}
            onChange={(cents) => update({ feeCents: cents ?? 0 })}
          />
        </Field>

        <Field label="Pedido mínimo" hint="0 para não exigir mínimo.">
          <MoneyInput
            valueCents={form.minOrderCents}
            onChange={(cents) => update({ minOrderCents: cents ?? 0 })}
          />
        </Field>
      </div>

      <div className="grid gap-3.5 sm:grid-cols-2">
        <Field label="Tempo estimado (minutos)">
          <input
            type="number"
            min={5}
            max={240}
            value={form.etaMinutes}
            onChange={(e) => update({ etaMinutes: Number(e.target.value) })}
            className="field"
          />
        </Field>

        <Field label="Raio de entrega (km)" hint="Opcional.">
          <input
            type="number"
            min={0}
            max={100}
            step="0.5"
            value={form.radiusKm ?? ''}
            onChange={(e) =>
              update({ radiusKm: e.target.value ? Number(e.target.value) : null })
            }
            className="field"
          />
        </Field>
      </div>

      <Field label="Frete grátis acima de" hint="Deixe em branco para não oferecer.">
        <MoneyInput
          valueCents={form.freeAboveCents}
          onChange={(cents) => update({ freeAboveCents: cents })}
        />
      </Field>

      <div className="grid gap-2.5 border-t border-line pt-4">
        <Toggle
          checked={form.acceptsDelivery}
          onChange={(value) => update({ acceptsDelivery: value })}
          label="Aceito pedidos para entrega"
        />
        <Toggle
          checked={form.acceptsPickup}
          onChange={(value) => update({ acceptsPickup: value })}
          label="Aceito retirada no local"
        />
      </div>
    </div>
  );
}

function PaymentStep({
  settings,
  onError,
}: {
  settings: Settings;
  onError: (message: string | null) => void;
}) {
  const queryClient = useQueryClient();
  const [methods, setMethods] = useState<string[]>(settings.paymentMethods);

  const save = useMutation({
    mutationFn: (next: string[]) =>
      apiFetch('/admin/settings/payments', {
        method: 'PUT',
        body: JSON.stringify({ methods: next }),
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['settings'] }),
    onError: () => onError('Não foi possível salvar as formas de pagamento.'),
  });

  function toggle(method: string) {
    const next = methods.includes(method)
      ? methods.filter((m) => m !== method)
      : [...methods, method];

    if (next.length === 0) return; // A loja precisa de ao menos uma forma.

    setMethods(next);
    save.mutate(next);
  }

  const onDelivery = ['cash', 'card_on_delivery', 'pix_on_delivery'];
  const online = ['stripe_card', 'stripe_pix'];

  return (
    <div className="grid gap-5">
      <div>
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
          Na entrega
        </p>
        <div className="grid gap-1.5">
          {onDelivery.map((method) => (
            <PaymentOption
              key={method}
              method={method}
              checked={methods.includes(method)}
              onToggle={() => toggle(method)}
            />
          ))}
        </div>
      </div>

      <div>
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
          Pelo site
        </p>

        {settings.store.acceptsOnlinePayment ? (
          <div className="grid gap-1.5">
            {online.map((method) => (
              <PaymentOption
                key={method}
                method={method}
                checked={methods.includes(method)}
                onToggle={() => toggle(method)}
              />
            ))}
          </div>
        ) : (
          <div className="rounded-lg border border-line p-3.5">
            <p className="text-sm">Receber pelo site ainda não está ativo.</p>
            <p className="mt-1 text-xs text-muted">
              Para aceitar cartão e Pix no checkout, conecte sua conta de
              recebimento. Você pode fazer isso depois, em Entrega e pagamento —
              a loja funciona normalmente com pagamento na entrega.
            </p>
          </div>
        )}
      </div>
    </div>
  );
}

function PaymentOption({
  method,
  checked,
  onToggle,
}: {
  method: string;
  checked: boolean;
  onToggle: () => void;
}) {
  return (
    <label
      className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm transition-colors ${
        checked ? 'border-accent bg-accent/5' : 'border-line hover:bg-line/40'
      }`}
    >
      <input
        type="checkbox"
        checked={checked}
        onChange={onToggle}
        className="size-4 accent-[rgb(var(--accent))]"
      />
      {PAYMENT_LABELS[method] ?? method}
    </label>
  );
}

function HoursStep({
  settings,
  onError,
}: {
  settings: Settings;
  onError: (message: string | null) => void;
}) {
  const queryClient = useQueryClient();
  const [hours, setHours] = useState(settings.businessHours);

  const save = useMutation({
    mutationFn: (next: typeof hours) =>
      apiFetch('/admin/settings/hours', {
        method: 'PUT',
        body: JSON.stringify({ hours: next, isOpenOverride: settings.isOpenOverride }),
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['settings'] }),
    onError: () => onError('Não foi possível salvar os horários.'),
  });

  function update(day: string, patch: Partial<(typeof hours)[string]>) {
    const next = { ...hours, [day]: { ...hours[day], ...patch } };
    setHours(next);
    save.mutate(next);
  }

  return (
    <div className="grid gap-2">
      {DAYS.map(({ key, label }) => {
        const slot = hours[key];

        return (
          <div
            key={key}
            className="flex flex-wrap items-center gap-3 rounded-lg border border-line p-3"
          >
            <label className="flex min-w-28 items-center gap-2.5 text-sm">
              <input
                type="checkbox"
                checked={slot.enabled}
                onChange={(e) => update(key, { enabled: e.target.checked })}
                className="size-4 accent-[rgb(var(--accent))]"
              />
              {label}
            </label>

            <div
              className={`flex items-center gap-2 ${slot.enabled ? '' : 'opacity-40'}`}
            >
              <input
                type="time"
                value={slot.open}
                disabled={!slot.enabled}
                onChange={(e) => update(key, { open: e.target.value })}
                className="field w-auto"
                aria-label={`Abre ${label}`}
              />
              <span className="text-xs text-muted">até</span>
              <input
                type="time"
                value={slot.close}
                disabled={!slot.enabled}
                onChange={(e) => update(key, { close: e.target.value })}
                className="field w-auto"
                aria-label={`Fecha ${label}`}
              />
            </div>
          </div>
        );
      })}

      <p className="mt-1 text-xs text-muted">
        Fechar depois da meia-noite é suportado: coloque, por exemplo, 19:00 até
        02:00.
      </p>
    </div>
  );
}

function MenuStep({
  categories,
  storefrontUrl,
}: {
  categories: Category[];
  storefrontUrl: string;
}) {
  const queryClient = useQueryClient();
  const [name, setName] = useState('');

  const create = useMutation({
    mutationFn: (categoryName: string) =>
      apiFetch('/admin/categories', {
        method: 'POST',
        body: JSON.stringify({ name: categoryName }),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['categories'] });
      setName('');
    },
  });

  const SUGGESTIONS = ['Entradas', 'Pratos principais', 'Bebidas', 'Sobremesas'];

  return (
    <div className="grid gap-4">
      <p className="text-sm text-muted">
        Comece criando as seções do seu cardápio. Depois é só adicionar os
        pratos dentro de cada uma.
      </p>

      <form
        onSubmit={(event) => {
          event.preventDefault();
          if (name.trim()) create.mutate(name.trim());
        }}
        className="flex gap-2"
      >
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Nome da seção"
          className="field"
        />
        <button
          type="submit"
          disabled={!name.trim() || create.isPending}
          className="shrink-0 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
        >
          Adicionar
        </button>
      </form>

      {categories.length === 0 && (
        <div className="flex flex-wrap gap-1.5">
          <span className="text-xs text-muted">Sugestões:</span>
          {SUGGESTIONS.map((suggestion) => (
            <button
              key={suggestion}
              type="button"
              onClick={() => create.mutate(suggestion)}
              className="rounded-full border border-line px-2.5 py-1 text-xs text-muted transition-colors hover:bg-line/50"
            >
              + {suggestion}
            </button>
          ))}
        </div>
      )}

      {categories.length > 0 && (
        <ul className="grid gap-1.5">
          {categories.map((category) => (
            <li
              key={category.id}
              className="flex items-center justify-between rounded-lg border border-line px-3.5 py-2.5 text-sm"
            >
              <span>{category.name}</span>
              <span className="text-xs text-muted">
                {category.products_count ?? 0} itens
              </span>
            </li>
          ))}
        </ul>
      )}

      <p className="rounded-lg bg-accent/5 p-3 text-xs text-muted">
        Ao concluir, sua loja fica no ar em{' '}
        <span className="font-medium text-ink">{storefrontUrl}</span>.
      </p>
    </div>
  );
}
