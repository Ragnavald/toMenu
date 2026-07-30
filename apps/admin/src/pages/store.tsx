import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch } from '@/lib/api';
import type { Settings } from '@/lib/types';
import { Field, PageHeader, SaveBar, Section } from '@/components/ui';

export function StorePage() {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    name: '',
    phone: '',
    whatsapp: '',
    address: '',
    description: '',
    logoUrl: '',
    coverUrl: '',
  });
  const [dirty, setDirty] = useState(false);
  const [savedAt, setSavedAt] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const { data: settings, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: () => apiFetch<Settings>('/admin/settings'),
  });

  useEffect(() => {
    if (settings) {
      setForm({
        name: settings.store.name,
        phone: settings.profile.phone ?? '',
        whatsapp: settings.profile.whatsapp ?? '',
        address: settings.profile.address ?? '',
        description: settings.profile.description ?? '',
        logoUrl: settings.profile.logoUrl ?? '',
        coverUrl: settings.profile.coverUrl ?? '',
      });
      setDirty(false);
    }
  }, [settings]);

  const save = useMutation({
    mutationFn: () =>
      apiFetch('/admin/settings/profile', {
        method: 'PUT',
        body: JSON.stringify({
          name: form.name,
          phone: form.phone || null,
          whatsapp: form.whatsapp || null,
          address: form.address || null,
          description: form.description || null,
          logoUrl: form.logoUrl || null,
          coverUrl: form.coverUrl || null,
        }),
      }),
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

  if (isLoading || !settings) {
    return <p className="text-sm text-muted">Carregando…</p>;
  }

  function update(patch: Partial<typeof form>) {
    setForm((current) => ({ ...current, ...patch }));
    setDirty(true);
  }

  return (
    <div>
      <PageHeader
        title="Dados da loja"
        description="Aparecem no site que seus clientes acessam."
      />

      <div className="grid gap-4">
        <Section
          title="Endereço da loja online"
          description="É o link que você divulga para os clientes."
        >
          <div className="flex flex-wrap items-center gap-2">
            <code className="min-w-0 flex-1 truncate rounded-lg bg-line/50 px-3 py-2 text-sm">
              {settings.store.storefrontUrl}
            </code>

            <button
              type="button"
              onClick={() => {
                navigator.clipboard?.writeText(settings.store.storefrontUrl);
                setCopied(true);
                setTimeout(() => setCopied(false), 2000);
              }}
              className="shrink-0 rounded-lg border border-line px-3 py-2 text-xs font-medium transition-colors hover:bg-line/50"
            >
              {copied ? 'Copiado ✓' : 'Copiar'}
            </button>

            <a
              href={settings.store.storefrontUrl}
              target="_blank"
              rel="noreferrer"
              className="shrink-0 rounded-lg border border-line px-3 py-2 text-xs font-medium transition-colors hover:bg-line/50"
            >
              Abrir
            </a>
          </div>
        </Section>

        <Section title="Identificação">
          <div className="grid gap-3.5">
            <Field label="Nome do restaurante">
              <input
                value={form.name}
                onChange={(e) => update({ name: e.target.value })}
                className="field"
              />
            </Field>

            <Field
              label="Descrição curta"
              hint="Aparece abaixo do nome no seu cardápio."
            >
              <textarea
                value={form.description}
                onChange={(e) => update({ description: e.target.value })}
                className="field min-h-20 resize-none"
                placeholder="Comida italiana feita na hora, com massa fresca."
              />
            </Field>
          </div>
        </Section>

        <Section title="Contato">
          <div className="grid gap-3.5 sm:grid-cols-2">
            <Field label="Telefone">
              <input
                value={form.phone}
                onChange={(e) => update({ phone: e.target.value })}
                className="field"
                placeholder="(11) 3333-4444"
              />
            </Field>

            <Field label="WhatsApp" hint="Recebe o aviso de cada pedido.">
              <input
                value={form.whatsapp}
                onChange={(e) => update({ whatsapp: e.target.value })}
                className="field"
                placeholder="(11) 99999-9999"
              />
            </Field>
          </div>

          <div className="mt-3.5">
            <Field label="Endereço físico">
              <input
                value={form.address}
                onChange={(e) => update({ address: e.target.value })}
                className="field"
                placeholder="Rua das Flores, 100 — Centro"
              />
            </Field>
          </div>
        </Section>

        <Section
          title="Imagens"
          description="Informe o endereço das imagens já hospedadas."
        >
          <div className="grid gap-3.5">
            <Field label="Logo (URL)">
              <input
                value={form.logoUrl}
                onChange={(e) => update({ logoUrl: e.target.value })}
                className="field"
                placeholder="https://…/logo.png"
              />
            </Field>

            <Field label="Capa (URL)">
              <input
                value={form.coverUrl}
                onChange={(e) => update({ coverUrl: e.target.value })}
                className="field"
                placeholder="https://…/capa.jpg"
              />
            </Field>
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
