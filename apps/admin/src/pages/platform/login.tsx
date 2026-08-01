import { useRef, useState } from 'react';
import { API_BASE, ApiError } from '@/lib/api';
import {
  savePlatformSession,
  type PlatformSession,
} from '@/lib/platform';
import {
  Turnstile,
  isTurnstileEnabled,
  type TurnstileHandle,
} from '@/components/turnstile';

/**
 * Login do staff da plataforma.
 *
 * Sem campo de loja, ao contrário do login do lojista: a conta é central e não
 * pertence a nenhum tenant. O endpoint também é outro — `/api/platform/auth/login`
 * só enxerga contas com `is_platform_admin`.
 */
export function PlatformLoginPage({
  onAuthenticated,
}: {
  onAuthenticated: (session: PlatformSession) => void;
}) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [turnstileToken, setTurnstileToken] = useState('');
  const turnstileRef = useRef<TurnstileHandle | null>(null);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(`${API_BASE}/api/platform/auth/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          email,
          password,
          'cf-turnstile-response': turnstileToken,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        // Cada token do Turnstile vale uma verificação: sem resetar, a segunda
        // tentativa reenviaria um token gasto e falharia mesmo com a senha
        // certa.
        turnstileRef.current?.reset();
        throw new ApiError(data?.message ?? 'Falha ao entrar.', response.status);
      }

      const session: PlatformSession = {
        token: data.token,
        name: data.user.name,
        email: data.user.email,
      };

      savePlatformSession(session);
      onAuthenticated(session);
    } catch (caught) {
      setError(
        caught instanceof Error ? caught.message : 'Não foi possível entrar.',
      );
    } finally {
      setLoading(false);
    }
  }

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
          <h1 className="text-xl font-semibold tracking-tight">
            ToMenu Plataforma
          </h1>
          <p className="mt-1 text-sm text-muted">
            Acesso restrito à equipe.
          </p>
        </div>

        <form onSubmit={handleSubmit} className="panel grid gap-3.5 p-5">
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
              placeholder="voce@to-menu.com"
            />
          </div>

          <div className="grid gap-1.5">
            <label htmlFor="password" className="text-xs font-medium text-muted">
              Senha
            </label>
            <input
              id="password"
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="field"
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
            {loading ? 'Entrando…' : 'Entrar'}
          </button>
        </form>
      </div>
    </div>
  );
}
