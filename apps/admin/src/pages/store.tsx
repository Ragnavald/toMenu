import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch } from '@/lib/api';
import type { Settings } from '@/lib/types';
import { Field, PageHeader, SaveBar, Section } from '@/components/ui';
import { DeleteStore } from '@/components/delete-store';
import { StoreQrCode } from '@/components/store-qrcode';
import {
  buildAddressString,
  fetchViaCep,
  formatCep,
  formatPhone,
  parseAddressString,
  type AddressFields,
} from '@/lib/masks';
import { SEGMENT_OPTIONS } from '@/lib/templates';

const IMAGE_KINDS = {
  logo: {
    endpoint: '/admin/settings/logo',
    alt: 'Logo da loja',
    empty: 'Sem logo',
    upload: 'Enviar logo',
    replace: 'Alterar logo',
    error: 'Erro ao enviar a imagem do logo.',
    // Quadrado: o logo é exibido em avatar redondo no cardápio.
    frame: 'size-16 rounded-xl',
  },
  cover: {
    endpoint: '/admin/settings/cover',
    alt: 'Capa da loja',
    empty: 'Sem capa',
    upload: 'Enviar capa',
    replace: 'Alterar capa',
    error: 'Erro ao enviar a imagem de capa.',
    // Panorâmico: espelha a faixa larga que a capa ocupa no topo da loja.
    frame: 'h-16 w-32 rounded-lg',
  },
} as const;

/**
 * Envia logo ou capa e devolve a URL já gravada.
 *
 * O upload grava no banco sozinho, sem passar pelo "Salvar" do formulário — por
 * isso o `onUploaded` precisa atualizar o estado local também. Enquanto ele não
 * fazia isso, o campo do formulário seguia com a URL anterior e o próximo
 * "Salvar" reescrevia a imagem antiga por cima da recém-enviada: a troca sumia
 * do cardápio e o painel parecia ter ignorado o upload.
 */
function ImageUploader({
  kind,
  url,
  onUploaded,
}: {
  kind: keyof typeof IMAGE_KINDS;
  url: string | null;
  onUploaded: (url: string) => void;
}) {
  const [uploading, setUploading] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const labels = IMAGE_KINDS[kind];

  async function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;

    const formData = new FormData();
    formData.append(kind, file);

    setUploading(true);
    try {
      const res = await apiFetch<{ url: string }>(labels.endpoint, {
        method: 'POST',
        body: formData,
      });
      onUploaded(res.url);
    } catch {
      alert(labels.error);
    } finally {
      setUploading(false);
      // Sem isto, reenviar o MESMO arquivo depois de um erro não dispara
      // change algum — o valor do input não mudou.
      e.target.value = '';
    }
  }

  return (
    <div className="flex items-center gap-4">
      <div
        className={`relative shrink-0 overflow-hidden border border-line bg-line/20 flex items-center justify-center ${labels.frame}`}
      >
        {url ? (
          <img src={url} alt={labels.alt} className="size-full object-cover" />
        ) : (
          <span className="text-xs text-muted">{labels.empty}</span>
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
          {uploading ? 'Enviando...' : url ? labels.replace : labels.upload}
        </button>
        <p className="mt-1 text-[11px] text-muted">
          PNG, JPG, WEBP ou SVG (máx. 5MB)
        </p>
      </div>
    </div>
  );
}

export function StorePage() {
  const queryClient = useQueryClient();
  const numberInputRef = useRef<HTMLInputElement>(null);
  const [loadingCep, setLoadingCep] = useState(false);
  const [form, setForm] = useState({
    name: '',
    segment: '',
    phone: '',
    whatsapp: '',
    description: '',
    logoUrl: '',
    coverUrl: '',
  });

  const [addressFields, setAddressFields] = useState<AddressFields>({
    zip: '',
    street: '',
    number: '',
    complement: '',
    district: '',
    city: '',
    state: '',
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
        segment: settings.profile.segment ?? '',
        phone: formatPhone(settings.profile.phone ?? ''),
        whatsapp: formatPhone(settings.profile.whatsapp ?? ''),
        description: settings.profile.description ?? '',
        logoUrl: settings.profile.logoUrl ?? '',
        coverUrl: settings.profile.coverUrl ?? '',
      });
      setAddressFields(parseAddressString(settings.profile.address ?? ''));
      setDirty(false);
    }
  }, [settings]);

  const save = useMutation({
    mutationFn: () => {
      const fullAddress = buildAddressString(addressFields);
      return apiFetch('/admin/settings/profile', {
        method: 'PUT',
        body: JSON.stringify({
          name: form.name,
          segment: form.segment || null,
          phone: form.phone || null,
          whatsapp: form.whatsapp || null,
          address: fullAddress || null,
          description: form.description || null,
          logoUrl: form.logoUrl || null,
          coverUrl: form.coverUrl || null,
        }),
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

  if (isLoading || !settings) {
    return <p className="text-sm text-muted">Carregando…</p>;
  }

  function update(patch: Partial<typeof form>) {
    setForm((current) => ({ ...current, ...patch }));
    setDirty(true);
  }

  function updateAddress(patch: Partial<AddressFields>) {
    setAddressFields((current) => ({ ...current, ...patch }));
    setDirty(true);
  }

  /**
   * Reflete no painel uma imagem que o upload já gravou no banco.
   *
   * Diferente dos outros campos, esta não é uma edição pendente: o arquivo já
   * subiu e a coluna já mudou. Por isso o cache de `settings` é corrigido junto
   * — é ele que alimenta o restante do painel (o topo da barra lateral, o
   * preview do tema) e, sem esta linha, esses lugares continuavam mostrando o
   * logo anterior até um F5.
   *
   * `setQueryData` em vez de `invalidateQueries`: o refetch traria a resposta
   * certa, mas o efeito que a copia para o formulário reescreve TODOS os campos
   * e zera o `dirty`, descartando o que o lojista tivesse digitado antes de
   * trocar a imagem. Como já temos a URL na mão, não há o que buscar.
   *
   * O `dirty` fica como está de propósito: a imagem não depende do "Salvar",
   * então o upload sozinho não deve acender a barra pedindo que se salve.
   */
  function applyUploadedImage(field: 'logoUrl' | 'coverUrl', url: string) {
    setForm((current) => ({ ...current, [field]: url }));

    queryClient.setQueryData<Settings>(['settings'], (current) =>
      current
        ? { ...current, profile: { ...current.profile, [field]: url } }
        : current,
    );
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
        setDirty(true);
        setTimeout(() => {
          numberInputRef.current?.focus();
        }, 100);
      }
    }
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

        <Section
          title="QR Code do cardápio"
          description="Leva direto para o endereço acima."
        >
          <StoreQrCode storefrontUrl={settings.store.storefrontUrl} />
        </Section>

        <Section title="Identificação">
          <div className="grid gap-3.5">
            <Field label="Logo da loja">
              <ImageUploader
                kind="logo"
                url={form.logoUrl || null}
                onUploaded={(url) => applyUploadedImage('logoUrl', url)}
              />
            </Field>

            <Field label="Nome do restaurante">
              <input
                value={form.name}
                onChange={(e) => update({ name: e.target.value })}
                className="field"
              />
            </Field>

            <Field
              label="Tipo de estabelecimento"
              hint="Usado para sugerir categorias pré-definidas para o seu cardápio."
            >
              <select
                value={form.segment}
                onChange={(e) => update({ segment: e.target.value })}
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

        <Section title="Contato e Endereço">
          <div className="grid gap-3.5 sm:grid-cols-2">
            <Field label="Telefone">
              <input
                value={form.phone}
                onChange={(e) =>
                  update({ phone: formatPhone(e.target.value) })
                }
                className="field"
                placeholder="(11) 3333-4444"
                maxLength={15}
              />
            </Field>

            <Field
              label="WhatsApp"
              hint="Aparece no cardápio para o cliente falar com a loja."
            >
              <input
                value={form.whatsapp}
                onChange={(e) =>
                  update({ whatsapp: formatPhone(e.target.value) })
                }
                className="field"
                placeholder="(11) 99999-9999"
                maxLength={15}
              />
            </Field>
          </div>

          {/* Endereço estruturado / quebrado */}
          <div className="mt-4 grid gap-3 rounded-lg border border-line p-3.5 bg-line/10">
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
                  onChange={(e) =>
                    updateAddress({ complement: e.target.value })
                  }
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
        </Section>

        <Section
          title="Imagens da Loja"
          description="Imagem de capa exibida no topo do seu cardápio."
        >
          <div className="grid gap-3.5">
            <Field label="Capa da loja">
              <ImageUploader
                kind="cover"
                url={form.coverUrl || null}
                onUploaded={(url) => applyUploadedImage('coverUrl', url)}
              />
            </Field>

            {/* O campo continua editável para quem já hospeda a capa fora
                daqui — apagar o conteúdo é também a única forma de REMOVER a
                capa, já que o upload sempre substitui por outra imagem. */}
            <Field
              label="Capa (URL)"
              hint="Preenchido pelo envio acima. Apague para remover a capa."
            >
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

        <DeleteStore />
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
