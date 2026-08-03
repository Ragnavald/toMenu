'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import {
  Turnstile,
  isTurnstileEnabled,
  type TurnstileHandle,
} from './turnstile';

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';
const ROOT_DOMAIN = process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost';

type SlugState = 'idle' | 'checking' | 'available' | 'taken' | 'invalid';

/** Dados da loja recém-criada que a tela de confirmação precisa mostrar. */
type CreatedAccount = {
  email: string;
  tenant: string;
  storeName: string;
  minutes: number;
};

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
  // Nasce desmarcado e assim tem de continuar. Uma caixa pré-marcada não é
  // consentimento — a LGPD exige manifestação inequívoca do titular, e o aceite
  // que o usuário não deu não prova nada num questionamento futuro.
  const [acceptedTerms, setAcceptedTerms] = useState(false);
  const [turnstileToken, setTurnstileToken] = useState('');
  const turnstileRef = useRef<TurnstileHandle | null>(null);
  // Preenchido quando a loja é criada: troca o formulário pelo aviso de
  // confirmação. A loja já existe neste ponto — não há como voltar ao
  // formulário, e reapresentá-lo só levaria o lojista a tentar de novo com um
  // slug agora ocupado por ele mesmo.
  const [created, setCreated] = useState<CreatedAccount | null>(null);

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
          accepted_terms: acceptedTerms,
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

      /*
       * A loja foi criada, mas ninguém entra no painel ainda.
       *
       * O cadastro não devolve mais token: a sessão só nasce quando o link
       * enviado por e-mail é consumido. Em vez de redirecionar, a tela troca
       * pelo aviso de confirmação, que também oferece o reenvio.
       */
      setCreated({
        email,
        tenant: data.tenant.slug,
        storeName: data.tenant.name,
        minutes: data.expiresInMinutes ?? 30,
      });
    } catch {
      setErrors({ geral: ['Não foi possível falar com o servidor.'] });
    } finally {
      setSubmitting(false);
    }
  }

  if (created) {
    return <VerifyEmailNotice account={created} />;
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

      {/*
        Aceite dos documentos.
        Fica imediatamente acima do botão porque é a última coisa que o usuário
        confirma antes de contratar, e os links abrem em aba nova: mandar quem
        quer ler os termos para fora da página apagaria o formulário todo que
        ele acabou de preencher.
      */}
      <div className="mt-1">
        <label className="flex cursor-pointer items-start gap-2.5">
          <input
            type="checkbox"
            required
            checked={acceptedTerms}
            onChange={(e) => setAcceptedTerms(e.target.checked)}
            className="mt-0.5 size-4 shrink-0 accent-[rgb(var(--brand))]"
            aria-describedby="terms-error"
          />
          <span className="text-xs leading-relaxed text-muted">
            Li e aceito os{' '}
            <a
              href="/termos"
              target="_blank"
              rel="noreferrer"
              className="font-medium underline underline-offset-2 hover:text-[rgb(var(--brand))]"
            >
              Termos de Uso
            </a>{' '}
            e a{' '}
            <a
              href="/privacidade"
              target="_blank"
              rel="noreferrer"
              className="font-medium underline underline-offset-2 hover:text-[rgb(var(--brand))]"
            >
              Política de Privacidade
            </a>
            , e autorizo o tratamento dos meus dados conforme descrito nela.
          </span>
        </label>

        {/* O backend também recusa o cadastro sem aceite; este erro só aparece
            se a validação do navegador for contornada. */}
        {errors.accepted_terms && (
          <p id="terms-error" role="alert" className="mt-1.5 text-xs text-red-600">
            {errors.accepted_terms[0]}
          </p>
        )}
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
          !acceptedTerms ||
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

/**
 * "Confirme seu e-mail antes de prosseguir."
 *
 * Substitui o formulário assim que a loja é criada. A conta existe, mas o
 * painel só abre depois que o link enviado por e-mail é consumido — por isso
 * não há atalho para o painel aqui: ele responderia com a tela de login, que
 * também recusaria a conta.
 */
function VerifyEmailNotice({ account }: { account: CreatedAccount }) {
  const [resent, setResent] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Segura o segundo clique impaciente: o backend limita a três reenvios por
  // conta a cada 15 minutos, e gastar as tentativas em cliques repetidos
  // deixaria o lojista sem saída justamente quando o e-mail demora.
  const [cooldown, setCooldown] = useState(0);

  useEffect(() => {
    if (cooldown <= 0) return;

    const timer = setTimeout(() => setCooldown((value) => value - 1), 1000);

    return () => clearTimeout(timer);
  }, [cooldown]);

  async function handleResend() {
    setSending(true);
    setError(null);

    try {
      const response = await fetch(`${API_URL}/api/auth/verify-email/resend`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ email: account.email, tenant: account.tenant }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(
          data?.errors?.email?.[0] ??
            data?.message ??
            'Não foi possível reenviar o e-mail.',
        );
      }

      setResent(true);
      setCooldown(60);
    } catch (caught) {
      setError(
        caught instanceof Error ? caught.message : 'Falha ao reenviar o e-mail.',
      );
    } finally {
      setSending(false);
    }
  }

  return (
    <div
      className="grid gap-4 border p-5 text-center"
      style={{
        borderRadius: 'calc(var(--radius) * 0.6)',
        borderColor: 'var(--hairline)',
      }}
    >
      <div className="grid gap-2">
        <h3 className="text-base font-semibold">
          Confirme seu e-mail antes de prosseguir
        </h3>

        <p className="text-sm leading-relaxed text-muted">
          A loja <strong>{account.storeName}</strong> foi criada. Enviamos um
          link de confirmação para <strong>{account.email}</strong> — abra o
          link para liberar o acesso ao painel.
        </p>
      </div>

      <p className="text-xs text-subtle">
        O link vale por {account.minutes} minutos. Se não encontrar o e-mail,
        procure na caixa de spam.
      </p>

      <div className="border-t border-[var(--hairline)] pt-4">
        {resent ? (
          <p className="text-xs text-emerald-600">
            Link reenviado. Verifique sua caixa de entrada.
          </p>
        ) : (
          <p className="text-xs text-subtle">Não recebeu?</p>
        )}

        <button
          type="button"
          onClick={handleResend}
          disabled={sending || cooldown > 0}
          className="mt-2 border px-4 py-2 text-xs font-semibold transition-opacity hover:opacity-90 disabled:opacity-55"
          style={{
            borderRadius: 'calc(var(--radius) * 0.55)',
            borderColor: 'var(--hairline)',
          }}
        >
          {sending
            ? 'Reenviando…'
            : cooldown > 0
              ? `Reenviar em ${cooldown}s`
              : 'Reenviar o link'}
        </button>

        {error && (
          <p role="alert" className="mt-2 text-xs text-red-600">
            {error}
          </p>
        )}
      </div>
    </div>
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
