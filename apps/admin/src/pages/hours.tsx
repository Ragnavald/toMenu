import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from '@/lib/api';
import { DAYS, type BusinessHours, type Settings } from '@/lib/types';
import { PageHeader, SaveBar, Section } from '@/components/ui';

export function HoursPage() {
  const queryClient = useQueryClient();
  const [hours, setHours] = useState<BusinessHours | null>(null);
  const [override, setOverride] = useState<boolean | null>(null);
  const [dirty, setDirty] = useState(false);
  const [savedAt, setSavedAt] = useState<number | null>(null);

  const { data: settings, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: () => apiFetch<Settings>('/admin/settings'),
  });

  useEffect(() => {
    if (settings) {
      setHours(settings.businessHours);
      setOverride(settings.isOpenOverride);
      setDirty(false);
    }
  }, [settings]);

  const save = useMutation({
    mutationFn: () =>
      apiFetch('/admin/settings/hours', {
        method: 'PUT',
        body: JSON.stringify({ hours, isOpenOverride: override }),
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settings'] });
      setDirty(false);
      setSavedAt(Date.now());
    },
  });

  if (isLoading || !hours) {
    return <p className="text-sm text-muted">Carregando…</p>;
  }

  function update(day: string, patch: Partial<BusinessHours[string]>) {
    setHours((current) =>
      current ? { ...current, [day]: { ...current[day], ...patch } } : current,
    );
    setDirty(true);
  }

  /** Copia o horário de um dia para todos os outros — poupa 6 edições iguais. */
  function applyToAll(day: string) {
    setHours((current) => {
      if (!current) return current;
      const model = current[day];

      return Object.fromEntries(
        DAYS.map(({ key }) => [key, { ...current[key], open: model.open, close: model.close }]),
      );
    });
    setDirty(true);
  }

  return (
    <div>
      <PageHeader
        title="Horários"
        description="Fora destes horários a loja aparece como fechada."
      />

      <div className="grid gap-4">
        <Section
          title="Situação agora"
          description="Use para fechar por imprevisto sem mexer nos horários."
        >
          <div className="grid gap-1.5 sm:grid-cols-3">
            {[
              { value: null, label: 'Seguir horários', hint: 'Automático' },
              { value: true, label: 'Forçar aberta', hint: 'Aceita pedidos' },
              { value: false, label: 'Fechar agora', hint: 'Recusa pedidos' },
            ].map((option) => (
              <button
                key={String(option.value)}
                type="button"
                onClick={() => {
                  setOverride(option.value);
                  setDirty(true);
                }}
                className={`rounded-lg border p-3 text-left transition-colors ${
                  override === option.value
                    ? 'border-accent bg-accent/5'
                    : 'border-line hover:bg-line/40'
                }`}
              >
                <span className="block text-sm font-medium">{option.label}</span>
                <span className="block text-xs text-muted">{option.hint}</span>
              </button>
            ))}
          </div>
        </Section>

        <Section title="Horário semanal">
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

                  {slot.enabled && (
                    <button
                      type="button"
                      onClick={() => applyToAll(key)}
                      className="ml-auto rounded-lg px-2.5 py-1 text-xs text-muted transition-colors hover:bg-line"
                    >
                      Aplicar a todos
                    </button>
                  )}
                </div>
              );
            })}
          </div>

          <p className="mt-3 text-xs text-muted">
            Para fechar depois da meia-noite, use por exemplo 19:00 até 02:00.
          </p>
        </Section>
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
