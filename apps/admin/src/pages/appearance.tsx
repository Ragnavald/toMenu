import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '@/lib/api';
import type { Settings, ThemeTokens } from '@/lib/types';

/** Paletas prontas: a maioria dos donos de loja não quer escolher RGB. */
const PRESETS = [
  { name: 'Tomate', brand: '198 40 40', brandSoft: '254 235 235', surface: '255 251 247', ink: '38 26 22' },
  { name: 'Índigo', brand: '23 79 122', brandSoft: '226 239 248', surface: '250 252 254', ink: '17 28 38' },
  { name: 'Floresta', brand: '21 105 74', brandSoft: '222 245 235', surface: '250 253 251', ink: '18 32 27' },
  { name: 'Âmbar', brand: '180 83 9', brandSoft: '254 243 220', surface: '255 252 245', ink: '41 31 18' },
  { name: 'Ameixa', brand: '112 45 122', brandSoft: '244 232 247', surface: '253 251 254', ink: '32 20 36' },
  { name: 'Grafite', brand: '39 39 42', brandSoft: '244 244 245', surface: '255 255 255', ink: '24 24 27' },
];

const FONT_LABELS: Record<string, string> = {
  inter: 'Inter',
  manrope: 'Manrope',
  sora: 'Sora',
  'space-grotesk': 'Space Grotesk',
  playfair: 'Playfair Display',
  'dm-serif': 'DM Serif Display',
};

const LAYOUT_LABELS: Record<string, string> = {
  classic: 'Clássico',
  grid: 'Grade',
  compact: 'Compacto',
};

export function AppearancePage() {
  const queryClient = useQueryClient();
  const [theme, setTheme] = useState<ThemeTokens | null>(null);
  const [saved, setSaved] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: () => apiFetch<Settings>('/admin/settings'),
    staleTime: 300_000,
  });

  useEffect(() => {
    if (data?.theme) setTheme(data.theme);
  }, [data]);

  const save = useMutation({
    mutationFn: (payload: ThemeTokens) =>
      apiFetch('/admin/settings/theme', {
        method: 'PUT',
        body: JSON.stringify({ theme: payload }),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settings'] });
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
    },
  });

  if (isLoading || !theme) {
    return <p className="text-sm text-muted">Carregando…</p>;
  }

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold tracking-tight">Aparência</h1>
          <p className="mt-0.5 text-sm text-muted">
            As mudanças valem no site da loja assim que você salvar — sem
            republicar nada.
          </p>
        </div>

        <div className="flex items-center gap-3">
          {saved && (
            <span className="text-xs font-medium text-emerald-600">
              Salvo ✓
            </span>
          )}
          <button
            type="button"
            onClick={() => save.mutate(theme)}
            disabled={save.isPending}
            className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
          >
            {save.isPending ? 'Salvando…' : 'Salvar'}
          </button>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
        <div className="grid gap-4">
          <section className="panel p-4">
            <h2 className="mb-3 text-sm font-semibold">Paleta</h2>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              {PRESETS.map((preset) => {
                const isActive = preset.brand === theme.brand;

                return (
                  <button
                    key={preset.name}
                    type="button"
                    onClick={() =>
                      setTheme({
                        ...theme,
                        brand: preset.brand,
                        brandSoft: preset.brandSoft,
                        surface: preset.surface,
                        ink: preset.ink,
                      })
                    }
                    className={`flex items-center gap-2.5 rounded-lg border p-2.5 text-left text-xs font-medium transition-colors ${
                      isActive
                        ? 'border-accent bg-accent/5'
                        : 'border-line hover:bg-line/50'
                    }`}
                  >
                    <span
                      className="size-7 shrink-0 rounded-md"
                      style={{ background: `rgb(${preset.brand})` }}
                    />
                    {preset.name}
                  </button>
                );
              })}
            </div>
          </section>

          <section className="panel p-4">
            <h2 className="mb-3 text-sm font-semibold">Tipografia</h2>
            <div className="grid gap-1.5 sm:grid-cols-2">
              {(data?.options.fonts ?? []).map((font) => (
                <label
                  key={font}
                  className={`flex cursor-pointer items-center gap-2.5 rounded-lg border p-2.5 text-sm transition-colors ${
                    theme.font === font
                      ? 'border-accent bg-accent/5'
                      : 'border-line hover:bg-line/50'
                  }`}
                >
                  <input
                    type="radio"
                    name="font"
                    checked={theme.font === font}
                    onChange={() => setTheme({ ...theme, font })}
                    className="size-4 accent-[rgb(var(--accent))]"
                  />
                  {FONT_LABELS[font] ?? font}
                </label>
              ))}
            </div>
          </section>

          <section className="panel p-4">
            <h2 className="mb-3 text-sm font-semibold">Layout do cardápio</h2>
            <div className="grid gap-1.5 sm:grid-cols-3">
              {(data?.options.layouts ?? []).map((layout) => (
                <label
                  key={layout}
                  className={`flex cursor-pointer items-center gap-2.5 rounded-lg border p-2.5 text-sm transition-colors ${
                    theme.layout === layout
                      ? 'border-accent bg-accent/5'
                      : 'border-line hover:bg-line/50'
                  }`}
                >
                  <input
                    type="radio"
                    name="layout"
                    checked={theme.layout === layout}
                    onChange={() => setTheme({ ...theme, layout })}
                    className="size-4 accent-[rgb(var(--accent))]"
                  />
                  {LAYOUT_LABELS[layout] ?? layout}
                </label>
              ))}
            </div>
          </section>

          <section className="panel p-4">
            <h2 className="mb-3 text-sm font-semibold">
              Arredondamento das bordas
            </h2>
            <input
              type="range"
              min={0}
              max={32}
              step={2}
              value={parseInt(theme.radius, 10)}
              onChange={(e) =>
                setTheme({ ...theme, radius: `${e.target.value}px` })
              }
              className="w-full accent-[rgb(var(--accent))]"
              aria-label="Arredondamento"
            />
            <p className="mt-1 text-xs text-muted">{theme.radius}</p>
          </section>
        </div>

        {/*
          Preview ao vivo com os mesmos tokens que o storefront consome.
          Ver o efeito antes de salvar é o que torna o editor utilizável por
          quem não é técnico.
        */}
        <aside className="lg:sticky lg:top-32 lg:self-start">
          <p className="mb-2 text-xs font-medium text-muted">Prévia</p>

          <div
            className="overflow-hidden border border-line"
            style={{
              borderRadius: theme.radius,
              background: `rgb(${theme.surface})`,
              color: `rgb(${theme.ink})`,
            }}
          >
            <div
              className="h-16"
              style={{
                background: `linear-gradient(135deg, rgb(${theme.brand} / 0.92), rgb(${theme.brandSoft}))`,
              }}
            />

            <div className="p-3.5">
              <p className="text-base font-semibold">Sua Loja</p>
              <p className="mt-0.5 text-xs" style={{ opacity: 0.62 }}>
                45 min · Entrega R$ 7,00
              </p>

              <div className="mt-3 flex gap-1.5">
                <span
                  className="rounded-md px-2.5 py-1 text-[11px] font-medium"
                  style={{
                    background: `rgb(${theme.brandSoft})`,
                    color: `rgb(${theme.brand})`,
                    borderRadius: `calc(${theme.radius} * 0.6)`,
                  }}
                >
                  Entradas
                </span>
                <span className="px-2.5 py-1 text-[11px]" style={{ opacity: 0.6 }}>
                  Pratos
                </span>
              </div>

              <div
                className="mt-3 border p-3"
                style={{
                  borderRadius: theme.radius,
                  borderColor: `rgb(${theme.ink} / 0.09)`,
                }}
              >
                <p className="text-sm font-medium">Prato do dia</p>
                <p className="mt-0.5 text-xs" style={{ opacity: 0.62 }}>
                  Descrição curta do item
                </p>
                <p className="mt-2 text-sm font-semibold">R$ 42,00</p>
              </div>

              <button
                type="button"
                className="mt-3 w-full py-2.5 text-xs font-semibold text-white"
                style={{
                  background: `rgb(${theme.brand})`,
                  borderRadius: `calc(${theme.radius} * 0.6)`,
                }}
              >
                Ver carrinho
              </button>
            </div>
          </div>
        </aside>
      </div>
    </div>
  );
}
