'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import {
  Turnstile,
  isTurnstileEnabled,
  type TurnstileHandle,
} from './turnstile';

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';
const ROOT_DOMAIN = process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost';
const ADMIN_URL = process.env.NEXT_PUBLIC_ADMIN_URL ?? 'http://localhost:5173';

type SlugState = 'idle' | 'checking' | 'available' | 'taken' | 'invalid';

type PlanSlug = 'cardapio' | 'pro';

/**
 * Os planos como o formulário precisa deles: rótulo, preço e a única frase que
 * separa um do outro. A lista completa de capacidades fica na seção de planos;
 * repeti-la aqui só afastaria o botão de enviar.
 */
const PLAN_CHOICES: { slug: PlanSlug; name: string; price: string; note: string }[] = [
  {
    slug: 'cardapio',
    name: 'Cardápio digital',
    price: 'R$ 29/mês',
    note: 'Mostra o cardápio. Sem pedidos pelo site.',
  },
  {
    slug: 'pro',
    name: 'Pro',
    price: 'R$ 89/mês',
    note: 'Cardápio + pedidos, entrega e painel.',
  },
];

/** Plano vindo do link dos cards (`/?plano=cardapio`); Pro se não vier nada. */
function initialPlan(): PlanSlug {
  if (typeof window === 'undefined') return 'pro';

  const value = new URLSearchParams(window.location.search).get('plano');

  return value === 'cardapio' ? 'cardapio' : 'pro';
}

/** Espelha a normalização do backend para que a prévia não minta ao usuário. */
function slugify(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40);
}

export function SignupForm() {
  const [storeName, setStoreName] = useState('');
  const [slug, setSlug] = useState('');
  const [slugTouched, setSlugTouched] = useState(false);
  const [slugState, setSlugState] = useState<SlugState>('idle');
  const [ownerName, setOwnerName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);
  // 'pro' na primeira renderização e ajustado no efeito abaixo: ler a query
  // string no estado inicial divergiria do HTML gerado no servidor, e o React
  // descartaria a hidratação da árvore inteira.
  const [plan, setPlan] = useState<PlanSlug>('pro');
  const [turnstileToken, setTurnstileToken] = useState('');
  const turnstileRef = useRef<TurnstileHandle | null>(null);

  // Enquanto o usuário não editar o endereço manualmente, ele acompanha o nome
  // da loja — a maioria nunca vai querer que sejam diferentes.
  const effectiveSlug = slugTouched ? slug : slugify(storeName);

  const previewUrl = useMemo(
    () => `${effectiveSlug || 'sualoja'}.${ROOT_DOMAIN}`,
    [effectiveSlug],
  );

  // Aplica o plano escolhido no card que trouxe o visitante até aqui.
  useEffect(() => {
    setPlan(initialPlan());
  }, []);

  useEffect(() => {
    if (effectiveSlug.length < 3) {
      setSlugState(effectiveSlug.length === 0 ? 'idle' : 'invalid');
      return;
    }

    setSlugState('checking');
    const controller = new AbortController();

    // Debounce: sem isso a API leva uma requisição por tecla digitada.
    const timer = setTimeout(async () => {
      try {
        const response = await fetch(
          `${API_URL}/api/register/check-slug?slug=${encodeURIComponent(effectiveSlug)}`,
          { signal: controller.signal, headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
          setSlugState('invalid');
          return;
        }

        const data = await response.json();
        setSlugState(data.available ? 'available' : 'taken');
      } catch {
        // Abortos do debounce são esperados; não vale sinalizar erro.
      }
    }, 400);

    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [effectiveSlug]);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setErrors({});

    try {
      const response = await fetch(`${API_URL}/api/register`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          store_name: storeName,
          slug: effectiveSlug,
          owner_name: ownerName,
          email,
          password,
          password_confirmation: confirmation,
          plan,
          'cf-turnstile-response': turnstileToken,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        setErrors(data.errors ?? { geral: [data.message ?? 'Não foi possível criar a loja.'] });
        // O token queima a cada verificação: sem resetar, uma segunda tentativa
        // reenviaria um token já gasto e falharia mesmo com os dados corrigidos.
        turnstileRef.current?.reset();
        return;
      }

      // O token do cadastro já autentica o painel: o dono cai direto no wizard
      // em vez de ter que fazer login logo após criar a conta.
      const handoff = new URLSearchParams({
        token: data.token,
        tenant: data.tenant.slug,
        name: data.tenant.name,
        user: data.user.name,
      });

      window.location.href = `${ADMIN_URL}/bem-vindo#${handoff.toString()}`;
    } catch {
      setErrors({ geral: ['Não foi possível falar com o servidor.'] });
    } finally {
      setSubmitting(false);
    }
  }

  const inputClass =
    'w-full border bg-transparent px-3 py-2.5 text-sm outline-none transition-colors placeholder:text-[var(--ink-subtle)] focus:border-[rgb(var(--brand))]';
  const inputStyle = {
    borderRadius: 'calc(var(--radius) * 0.55)',
    borderColor: 'var(--hairline)',
  };

  return (
    <form onSubmit={handleSubmit} className="grid gap-3.5">
      <fieldset className="grid gap-1.5">
        <legend className="mb-1.5 text-xs font-medium text-muted">
          Seu plano
        </legend>

        <div className="grid gap-2 sm:grid-cols-2">
          {PLAN_CHOICES.map((choice) => {
            const isSelected = plan === choice.slug;

            return (
              <label
                key={choice.slug}
                className="cursor-pointer border p-3 transition-colors"
                style={{
                  borderRadius: 'calc(var(--radius) * 0.55)',
                  borderColor: isSelected
                    ? 'rgb(var(--brand))'
                    : 'var(--hairline)',
                  background: isSelected
                    ? 'rgb(var(--brand-soft) / 0.45)'
                    : 'transparent',
                }}
              >
                <span className="flex items-center gap-2">
                  <input
                    type="radio"
                    name="plano"
                    value={choice.slug}
                    checked={isSelected}
                    onChange={() => setPlan(choice.slug)}
                    className="size-3.5 shrink-0 accent-[rgb(var(--brand))]"
                  />
                  <span className="text-sm font-semibold">{choice.name}</span>
                </span>

                <span className="mt-1 block text-sm font-medium tabular-nums">
                  {choice.price}
                </span>
                <span className="mt-0.5 block text-xs leading-relaxed text-muted">
                  {choice.note}
                </span>
              </label>
            );
          })}
        </div>

        <p className="text-xs text-subtle">
          Os 14 dias grátis valem nos dois. Trocar de plano depois não apaga
          nada do que você já cadastrou.
        </p>
      </fieldset>

      <div className="border-t border-[var(--hairline)] pt-3.5" />

      <Field label="Nome do restaurante" error={errors.store_name?.[0]}>
        <input
          required
          value={storeName}
          onChange={(e) => setStoreName(e.target.value)}
          className={inputClass}
          style={inputStyle}
          placeholder="Cantina da Nona"
        />
      </Field>

      <Field label="Endereço da sua loja" error={errors.slug?.[0]}>
        <div
          className="flex items-center overflow-hidden border"
          style={inputStyle}
        >
          <input
            required
            value={effectiveSlug}
            onChange={(e) => {
              setSlugTouched(true);
              setSlug(slugify(e.target.value));
            }}
            className="min-w-0 flex-1 bg-transparent px-3 py-2.5 text-sm outline-none"
            placeholder="cantina-da-nona"
            aria-describedby="slug-hint"
          />
          <span className="shrink-0 pr-3 text-sm text-subtle">
            .{ROOT_DOMAIN}
          </span>
        </div>

        <p id="slug-hint" className="mt-1.5 text-xs">
          {slugState === 'available' && (
            <span className="text-emerald-600">
              {previewUrl} está disponível
            </span>
          )}
          {slugState === 'taken' && (
            <span className="text-red-600">
              {previewUrl} já está em uso
            </span>
          )}
          {slugState === 'checking' && (
            <span className="text-subtle">verificando…</span>
          )}
          {slugState === 'invalid' && (
            <span className="text-subtle">
              Use ao menos 3 letras, números ou hífens.
            </span>
          )}
          {slugState === 'idle' && (
            <span className="text-subtle">Sua loja ficará em {previewUrl}</span>
          )}
        </p>
      </Field>

      <div className="mt-1 border-t border-[var(--hairline)] pt-3.5">
        <Field label="Seu nome" error={errors.owner_name?.[0]}>
          <input
            required
            value={ownerName}
            onChange={(e) => setOwnerName(e.target.value)}
            className={inputClass}
            style={inputStyle}
            placeholder="Ana Souza"
          />
        </Field>
      </div>

      <Field label="E-mail" error={errors.email?.[0]}>
        <input
          type="email"
          required
          autoComplete="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          className={inputClass}
          style={inputStyle}
          placeholder="voce@restaurante.com"
        />
      </Field>

      <div className="grid gap-3.5 sm:grid-cols-2">
        <Field label="Senha" error={errors.password?.[0]}>
          <input
            type="password"
            required
            minLength={8}
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className={inputClass}
            style={inputStyle}
          />
        </Field>

        <Field label="Confirmar senha">
          <input
            type="password"
            required
            autoComplete="new-password"
            value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)}
            className={inputClass}
            style={inputStyle}
          />
        </Field>
      </div>

      <Turnstile onToken={setTurnstileToken} handleRef={turnstileRef} />

      {errors['cf-turnstile-response'] && (
        <p role="alert" className="text-xs text-red-600">
          {errors['cf-turnstile-response'][0]}
        </p>
      )}

      {errors.geral && (
        <p role="alert" className="text-xs text-red-600">
          {errors.geral[0]}
        </p>
      )}

      <button
        type="submit"
        // Sem token o backend recusaria de qualquer forma; desabilitar evita a
        // ida perdida ao servidor enquanto o widget ainda resolve.
        disabled={
          submitting ||
          slugState === 'taken' ||
          (isTurnstileEnabled() && turnstileToken === '')
        }
        className="mt-1 px-4 py-3 text-sm font-semibold transition-opacity hover:opacity-90 disabled:opacity-55"
        style={{
          background: 'rgb(var(--brand))',
          color: 'rgb(var(--brand-ink))',
          borderRadius: 'calc(var(--radius) * 0.6)',
        }}
      >
        {submitting ? 'Criando sua loja…' : 'Criar minha loja grátis'}
      </button>

      <p className="text-center text-xs text-subtle">
        14 dias grátis. Você não precisa informar cartão agora.
      </p>
    </form>
  );
}

function Field({
  label,
  error,
  children,
}: {
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="grid gap-1.5">
      <span className="text-xs font-medium text-muted">{label}</span>
      {children}
      {error && <span className="text-xs text-red-600">{error}</span>}
    </label>
  );
}
