import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '@/lib/api';
import { DAYS, PAYMENT_LABELS, type Category, type Settings } from '@/lib/types';
import { SEGMENT_OPTIONS, templateCategorias, getSegmentLabel } from '@/lib/templates';
import { Field, MoneyInput, Toggle } from '@/components/ui';
import {
  buildAddressString,
  fetchViaCep,
  formatCep,
  formatPhone,
  parseAddressString,
  type AddressFields,
} from '@/lib/masks';

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
        {/* O texto do logo é escuro; no tema escuro entra a variante clara.
            A troca segue a classe .dark do <html>, não a media query, senão
            quem alterna o tema manualmente veria o logo errado. */}
        <img
          src="/tomenu-wordmark.png"
          alt="ToMenu"
          width={560}
          height={133}
          className="mb-5 h-8 w-auto dark:hidden"
        />
        <img
          src="/tomenu-wordmark-dark.png"
          alt="ToMenu"
          width={560}
          height={133}
          className="mb-5 hidden h-8 w-auto dark:block"
        />
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
                  className={`block h-1 rounded-full transition-colors ${done || current ? 'bg-accent' : 'bg-line'
                    }`}
                />
                <span
                  className={`mt-1.5 hidden text-[11px] font-medium sm:block ${current ? 'text-accent' : 'text-muted'
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
              segment={settings.profile.segment}
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

function LogoUploader({
  logoUrl,
  onUploaded,
}: {
  logoUrl: string | null;
  onUploaded: (url: string) => void;
}) {
  const [uploading, setUploading] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  async function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('logo', file);

    setUploading(true);
    try {
      const res = await apiFetch<{ url: string }>('/admin/settings/logo', {
        method: 'POST',
        body: formData,
      });
      onUploaded(res.url);
    } catch {
      alert('Erro ao enviar a imagem do logo.');
    } finally {
      setUploading(false);
    }
  }

  return (
    <div className="flex items-center gap-4">
      <div className="relative size-16 shrink-0 overflow-hidden rounded-xl border border-line bg-line/20 flex items-center justify-center">
        {logoUrl ? (
          <img
            src={logoUrl}
            alt="Logo da loja"
            className="size-full object-cover"
          />
        ) : (
          <span className="text-xs text-muted">Sem logo</span>
        )}
      </div>

      <div>
        <input
          ref={fileInputRef}
          type="file"
          accept="image/*"
          className="hidden"
          onChange={handleFileChange}
        />
        <button
          type="button"
          disabled={uploading}
          onClick={() => fileInputRef.current?.click()}
          className="rounded-lg border border-line px-3 py-1.5 text-xs font-semibold hover:bg-line/40 transition-colors disabled:opacity-50"
        >
          {uploading ? 'Enviando...' : logoUrl ? 'Alterar logo' : 'Enviar logo'}
        </button>
        <p className="mt-1 text-[11px] text-muted">
          PNG, JPG, WEBP ou SVG (máx. 5MB)
        </p>
      </div>
    </div>
  );
}

function StoreStep({
  settings,
  onError,
}: {
  settings: Settings;
  onError: (message: string | null) => void;
}) {
  const queryClient = useQueryClient();
  const numberInputRef = useRef<HTMLInputElement>(null);
  const [loadingCep, setLoadingCep] = useState(false);
  const [logoUrl, setLogoUrl] = useState<string | null>(
    settings.profile.logoUrl ?? null,
  );
  const [form, setForm] = useState({
    name: settings.store.name,
    segment: settings.profile.segment ?? '',
    whatsapp: formatPhone(settings.profile.whatsapp ?? ''),
    description: settings.profile.description ?? '',
  });

  const [addressFields, setAddressFields] = useState<AddressFields>(() =>
    parseAddressString(settings.profile.address ?? ''),
  );

  const save = useMutation({
    mutationFn: () => {
      const fullAddress = buildAddressString(addressFields);
      return apiFetch('/admin/settings/profile', {
        method: 'PUT',
        body: JSON.stringify({
          name: form.name,
          segment: form.segment || null,
          whatsapp: form.whatsapp || null,
          address: fullAddress || null,
          description: form.description || null,
          phone: settings.profile.phone,
          logoUrl: logoUrl,
        }),
      });
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['settings'] }),
    onError: () => onError('Não foi possível salvar os dados da loja.'),
  });

  function updateAddress(patch: Partial<AddressFields>) {
    setAddressFields((current) => ({ ...current, ...patch }));
  }

  async function handleCepChange(rawCep: string) {
    const formatted = formatCep(rawCep);
    updateAddress({ zip: formatted });

    const cleanZip = formatted.replace(/\D/g, '');
    if (cleanZip.length === 8) {
      setLoadingCep(true);
      const res = await fetchViaCep(cleanZip);
      setLoadingCep(false);
      if (res) {
        setAddressFields((current) => ({
          ...current,
          zip: formatted,
          street: res.street || current.street,
          district: res.district || current.district,
          city: res.city || current.city,
          state: res.state || current.state,
        }));
        setTimeout(() => {
          numberInputRef.current?.focus();
        }, 100);
      }
    }
  }

  return (
    <div className="grid gap-3.5" onBlur={() => save.mutate()}>
      <Field label="Logo da loja">
        <LogoUploader
          logoUrl={logoUrl}
          onUploaded={(url) => {
            setLogoUrl(url);
            queryClient.invalidateQueries({ queryKey: ['settings'] });
          }}
        />
      </Field>

      <Field label="Nome do restaurante">
        <input
          value={form.name}
          onChange={(e) => setForm({ ...form, name: e.target.value })}
          className="field"
        />
      </Field>

      <Field
        label="Tipo de estabelecimento (opcional)"
        hint="Ajuda a sugerir e popular as categorias pré-definidas no seu cardápio."
      >
        <select
          value={form.segment}
          onChange={(e) => setForm({ ...form, segment: e.target.value })}
          className="field"
        >
          <option value="">Selecione o tipo do estabelecimento...</option>
          {SEGMENT_OPTIONS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </select>
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
          onChange={(e) =>
            setForm({ ...form, whatsapp: formatPhone(e.target.value) })
          }
          className="field"
          placeholder="(11) 99999-9999"
          maxLength={15}
        />
      </Field>

      {/* Endereço quebrado */}
      <div className="grid gap-3 rounded-lg border border-line p-3.5 bg-line/10">
        <p className="text-xs font-semibold uppercase tracking-wide text-muted">
          Endereço físico do restaurante
        </p>

        <div className="grid grid-cols-1 sm:grid-cols-[160px_1fr] gap-3">
          <Field label="CEP" hint="Busca via ViaCEP">
            <div className="relative">
              <input
                value={addressFields.zip}
                onChange={(e) => handleCepChange(e.target.value)}
                className="field"
                placeholder="00000-000"
                maxLength={9}
              />
              {loadingCep && (
                <span className="absolute inset-y-0 right-3 flex items-center text-xs text-muted animate-pulse">
                  ...
                </span>
              )}
            </div>
          </Field>

          <Field label="Rua / Logradouro">
            <input
              value={addressFields.street}
              onChange={(e) => updateAddress({ street: e.target.value })}
              className="field"
              placeholder="Ex: Rua Augusta"
            />
          </Field>
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-[100px_1fr_1fr] gap-3">
          <Field label="Número">
            <input
              ref={numberInputRef}
              value={addressFields.number}
              onChange={(e) => updateAddress({ number: e.target.value })}
              className="field"
              placeholder="1200"
            />
          </Field>

          <Field label="Complemento" hint="Opcional">
            <input
              value={addressFields.complement}
              onChange={(e) => updateAddress({ complement: e.target.value })}
              className="field"
              placeholder="Apto / Sala"
            />
          </Field>

          <Field label="Bairro">
            <input
              value={addressFields.district}
              onChange={(e) => updateAddress({ district: e.target.value })}
              className="field"
              placeholder="Consolação"
            />
          </Field>
        </div>

        <div className="grid grid-cols-[1fr_80px] gap-3">
          <Field label="Cidade">
            <input
              value={addressFields.city}
              onChange={(e) => updateAddress({ city: e.target.value })}
              className="field"
              placeholder="São Paulo"
            />
          </Field>

          <Field label="UF">
            <input
              value={addressFields.state}
              onChange={(e) =>
                updateAddress({ state: e.target.value.toUpperCase() })
              }
              className="field uppercase"
              placeholder="SP"
              maxLength={2}
            />
          </Field>
        </div>
      </div>

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
      className={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm transition-colors ${checked ? 'border-accent bg-accent/5' : 'border-line hover:bg-line/40'
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
  segment,
}: {
  categories: Category[];
  storefrontUrl: string;
  segment?: string | null;
}) {
  const queryClient = useQueryClient();
  const [name, setName] = useState('');
  const [selectedSegment, setSelectedSegment] = useState<string>(segment ?? '');

  useEffect(() => {
    if (segment) setSelectedSegment(segment);
  }, [segment]);

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

  const batchCreate = useMutation({
    mutationFn: (items: { name: string }[]) =>
      apiFetch('/admin/categories/batch', {
        method: 'POST',
        body: JSON.stringify({ categories: items }),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['categories'] });
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) =>
      apiFetch(`/admin/categories/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['categories'] });
    },
  });

  const activeTemplates = selectedSegment ? templateCategorias[selectedSegment] : null;

  return (
    <div className="grid gap-4">
      <p className="text-sm text-muted">
        Comece criando as seções do seu cardápio. Depois é só adicionar os
        pratos dentro de cada uma.
      </p>

      {/* Seleção e importação por tipo de estabelecimento */}
      <div className="rounded-xl border border-line bg-line/10 p-3.5 space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <label className="text-xs font-semibold uppercase tracking-wide text-muted">
            Tipo de Estabelecimento
          </label>
          <select
            value={selectedSegment}
            onChange={(e) => setSelectedSegment(e.target.value)}
            className="field max-w-xs text-xs py-1 px-2.5"
          >
            <option value="">Selecione o tipo...</option>
            {SEGMENT_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
        </div>

        {activeTemplates && activeTemplates.length > 0 && (
          <div className="space-y-2 border-t border-line pt-2.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-xs font-medium text-ink">
                Categorias sugeridas ({getSegmentLabel(selectedSegment)}):
              </span>
              <button
                type="button"
                disabled={batchCreate.isPending}
                onClick={() => {
                  const items = activeTemplates.map((t) => ({
                    name: `${t.icone} ${t.nome}`,
                  }));
                  batchCreate.mutate(items);
                }}
                className="rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
              >
                {batchCreate.isPending ? 'Importando...' : '✨ Importar todas do modelo'}
              </button>
            </div>
            <div className="flex flex-wrap gap-1.5">
              {activeTemplates.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  disabled={create.isPending}
                  onClick={() => create.mutate(`${item.icone} ${item.nome}`)}
                  className="rounded-full border border-line bg-surface px-3 py-1 text-xs font-medium transition-colors hover:bg-line/50"
                >
                  + {item.icone} {item.nome}
                </button>
              ))}
            </div>
          </div>
        )}
      </div>

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
          placeholder="Nome de nova seção manual — ex.: Sobremesas"
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

      {categories.length > 0 && (
        <ul className="grid gap-1.5">
          {categories.map((category) => (
            <li
              key={category.id}
              className="flex items-center justify-between rounded-lg border border-line px-3.5 py-2.5 text-sm"
            >
              <span>{category.name}</span>
              <div className="flex items-center gap-3">
                <span className="text-xs text-muted">
                  {category.products_count ?? 0} itens
                </span>
                <button
                  type="button"
                  disabled={remove.isPending}
                  onClick={() => remove.mutate(category.id)}
                  className="grid size-6 place-items-center rounded-md text-muted transition-colors hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400"
                  title="Remover categoria"
                  aria-label={`Remover categoria ${category.name}`}
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                  </svg>
                </button>
              </div>
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
