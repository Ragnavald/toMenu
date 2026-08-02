import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch, formatMoney } from '@/lib/api';
import {
  PAYMENT_LABELS,
  type DeliveryConfig,
  type Settings,
} from '@/lib/types';
import {
  Field,
  MoneyInput,
  PageHeader,
  SaveBar,
  Section,
  Toggle,
} from '@/components/ui';

export function DeliveryPage() {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<DeliveryConfig | null>(null);
  const [methods, setMethods] = useState<string[]>([]);
  const [dirty, setDirty] = useState(false);
  const [savedAt, setSavedAt] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data: settings, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: () => apiFetch<Settings>('/admin/settings'),
  });

  useEffect(() => {
    if (settings) {
      setForm(settings.delivery);
      setMethods(settings.paymentMethods);
      setDirty(false);
    }
  }, [settings]);

  const save = useMutation({
    mutationFn: async () => {
      if (!form) return;

      await apiFetch('/admin/settings/delivery', {
        method: 'PUT',
        body: JSON.stringify(form),
      });

      await apiFetch('/admin/settings/payments', {
        method: 'PUT',
        body: JSON.stringify({ methods }),
      });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settings'] });
      setDirty(false);
      setSavedAt(Date.now());
      setError(null);
    },
    onError: (caught) => {
      setError(
        caught instanceof ApiError
          ? Object.values(caught.errors ?? {}).flat().join(' ') || caught.message
          : 'Não foi possível salvar.',
      );
    },
  });

  if (isLoading || !form || !settings) {
    return <p className="text-sm text-muted">Carregando…</p>;
  }

  function update(patch: Partial<DeliveryConfig>) {
    setForm((current) => (current ? { ...current, ...patch } : current));
    setDirty(true);
  }

  function toggleMethod(method: string) {
    setMethods((current) => {
      const next = current.includes(method)
        ? current.filter((m) => m !== method)
        : [...current, method];

      // Uma loja sem forma de pagamento não consegue fechar pedido nenhum.
      return next.length === 0 ? current : next;
    });
    setDirty(true);
  }

  const onDelivery = ['cash', 'card_on_delivery', 'pix_on_delivery'];
  const online = ['stripe_card', 'stripe_pix'];

  return (
    <div>
      <PageHeader
        title="Entrega e pagamento"
        description="Regras aplicadas no checkout da sua loja."
      />

      <div className="grid gap-4">
        <Section title="Entrega">
          <div className="grid gap-4">
            <div className="grid gap-3.5 sm:grid-cols-2">
              <Field
                label="Taxa de entrega"
                hint="R$ 0,00 significa entrega grátis."
              >
                <MoneyInput
                  valueCents={form.feeCents}
                  onChange={(cents) => update({ feeCents: cents ?? 0 })}
                />
              </Field>

              <Field
                label="Pedido mínimo"
                hint="O cliente não consegue fechar abaixo deste valor."
              >
                <MoneyInput
                  valueCents={form.minOrderCents}
                  onChange={(cents) => update({ minOrderCents: cents ?? 0 })}
                />
              </Field>
            </div>

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

            <Field
              label="Frete grátis acima de"
              hint={
                form.freeAboveCents
                  ? `Pedidos acima de ${formatMoney(form.freeAboveCents)} não pagam entrega.`
                  : 'Deixe em branco para não oferecer frete grátis.'
              }
            >
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
                description="O cliente busca o pedido e não paga taxa."
              />
              <Toggle
                checked={form.acceptsDineIn}
                onChange={(value) => update({ acceptsDineIn: value })}
                label="Aceito consumo no local"
                description="O cliente come no salão. Sem endereço e sem taxa."
              />
            </div>
          </div>
        </Section>

        <Section
          title="Formas de pagamento"
          description="O cliente escolhe uma delas no checkout."
        >
          <div className="grid gap-5">
            <div>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
                Na entrega
              </p>
              <div className="grid gap-1.5">
                {onDelivery.map((method) => (
                  <label
                    key={method}
                    className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm transition-colors ${
                      methods.includes(method)
                        ? 'border-accent bg-accent/5'
                        : 'border-line hover:bg-line/40'
                    }`}
                  >
                    <input
                      type="checkbox"
                      checked={methods.includes(method)}
                      onChange={() => toggleMethod(method)}
                      className="size-4 accent-[rgb(var(--accent))]"
                    />
                    {PAYMENT_LABELS[method]}
                  </label>
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
                    <label
                      key={method}
                      className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm transition-colors ${
                        methods.includes(method)
                          ? 'border-accent bg-accent/5'
                          : 'border-line hover:bg-line/40'
                      }`}
                    >
                      <input
                        type="checkbox"
                        checked={methods.includes(method)}
                        onChange={() => toggleMethod(method)}
                        className="size-4 accent-[rgb(var(--accent))]"
                      />
                      {PAYMENT_LABELS[method]}
                    </label>
                  ))}
                </div>
              ) : (
                <div className="rounded-lg border border-line p-3.5">
                  <p className="text-sm">Recebimento online não está ativo</p>
                  <p className="mt-1 text-xs text-muted">
                    Para aceitar cartão e Pix no site, é preciso conectar sua
                    conta de recebimento. Enquanto isso, a loja opera
                    normalmente com pagamento na entrega.
                  </p>
                </div>
              )}
            </div>
          </div>
        </Section>

        {error && (
          <p role="alert" className="text-xs text-red-600">
            {error}
          </p>
        )}
      </div>

      <SaveBar
        dirty={dirty}
        saving={save.isPending}
        onSave={() => save.mutate()}
        savedAt={savedAt}
      />
    </div>
  );
}
