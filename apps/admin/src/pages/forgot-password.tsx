import { useRef, useState } from 'react';
import { API_BASE, ApiError } from '@/lib/api';
import {
  Turnstile,
  isTurnstileEnabled,
  type TurnstileHandle,
} from '@/components/turnstile';

/**
 * Pedido do link de redefinição.
 *
 * Pede a loja além do e-mail porque a conta é o par (loja, e-mail): o mesmo
 * endereço pode ser dono de uma pizzaria e gerente de um sushi, e o backend
 * precisa dos dois para saber qual senha redefinir.
 */
export function ForgotPasswordPage({ onBack }: { onBack: () => void }) {
  const [tenant, setTenant] = useState('');
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const [loading, setLoading] = useState(false);
  const [turnstileToken, setTurnstileToken] = useState('');
  const turnstileRef = useRef<TurnstileHandle | null>(null);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(`${API_BASE}/api/auth/forgot-password`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          email,
          tenant,
          'cf-turnstile-response': turnstileToken,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        // Cada token do Turnstile vale uma verificação: sem o reset, a segunda
        // tentativa reenviaria um token gasto e falharia sem motivo aparente.
        turnstileRef.current?.reset();
        throw new ApiError(
          data?.message ?? 'Não foi possível enviar o link.',
          response.status,
        );
      }

      setSent(true);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Falha ao enviar.');
    } finally {
      setLoading(false);
    }
  }

  /*
   * A confirmação não diz se a conta existe.
   *
   * O backend responde igual nos dois casos de propósito — confirmar aqui que
   * o e-mail está cadastrado permitiria descobrir quem trabalha em qual loja.
   */
  if (sent) {
    return (
      <Shell title="Verifique seu e-mail">
        <div className="panel grid gap-3 p-5 text-sm">
          <p>
            Se houver uma conta com <strong>{email}</strong> na loja{' '}
            <strong>{tenant}</strong>, enviamos um link para criar uma senha nova.
          </p>
          <p className="text-muted">
            O link vale por 60 minutos. Não esqueça de olhar a caixa de spam.
          </p>
          <button
            type="button"
            onClick={onBack}
            className="mt-1 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90"
          >
            Voltar ao login
          </button>
        </div>
      </Shell>
    );
  }

  return (
    <Shell title="Esqueci minha senha">
      <form onSubmit={handleSubmit} className="panel grid gap-3.5 p-5">
        <div className="grid gap-1.5">
          <label htmlFor="tenant" className="text-xs font-medium text-muted">
            Loja
          </label>
          <input
            id="tenant"
            required
            value={tenant}
            onChange={(e) => setTenant(e.target.value)}
            className="field"
            placeholder="slug-da-loja"
          />
        </div>

        <div className="grid gap-1.5">
          <label htmlFor="email" className="text-xs font-medium text-muted">
            E-mail
          </label>
          <input
            id="email"
            type="email"
            required
            autoComplete="username"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="field"
            placeholder="voce@loja.com"
          />
        </div>

        <Turnstile onToken={setTurnstileToken} handleRef={turnstileRef} />

        {error && (
          <p
            role="alert"
            className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950/40 dark:text-red-300"
          >
            {error}
          </p>
        )}

        <button
          type="submit"
          disabled={loading || (isTurnstileEnabled() && turnstileToken === '')}
          className="mt-1 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
        >
          {loading ? 'Enviando…' : 'Enviar link de redefinição'}
        </button>

        <button
          type="button"
          onClick={onBack}
          className="text-xs text-muted underline-offset-2 hover:underline"
        >
          Voltar ao login
        </button>
      </form>
    </Shell>
  );
}

/** Moldura comum às telas de senha, espelhando o cabeçalho do login. */
export function Shell({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div className="grid min-h-full place-items-center px-4">
      <div className="w-full max-w-sm">
        <div className="mb-8 text-center">
          <img
            src="/tomenu-icon.png"
            alt=""
            aria-hidden
            width={512}
            height={512}
            className="mx-auto mb-3 size-12"
          />
          <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
        </div>
        {children}
      </div>
    </div>
  );
}
