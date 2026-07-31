import { useState } from 'react';
import { API_BASE, ApiError, saveSession, type Session } from '@/lib/api';

export function LoginPage({
  onAuthenticated,
}: {
  onAuthenticated: (session: Session) => void;
}) {
  const [tenant, setTenant] = useState('forno-di-napoli');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(`${API_BASE}/api/auth/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ email, password, tenant }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new ApiError(data?.message ?? 'Falha ao entrar.', response.status);
      }

      const session: Session = {
        token: data.token,
        tenantSlug: data.tenant.slug,
        tenantName: data.tenant.name,
        userName: data.user.name,
      };

      saveSession(session);
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
          {/* Só o símbolo: o título logo abaixo já diz o nome da marca. */}
          <img
            src="/tomenu-icon.png"
            alt=""
            aria-hidden
            width={512}
            height={512}
            className="mx-auto mb-3 size-12"
          />
          <h1 className="text-xl font-semibold tracking-tight">ToMenu Admin</h1>
          <p className="mt-1 text-sm text-muted">
            Gerencie o cardápio e os pedidos da sua loja.
          </p>
        </div>

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
            disabled={loading}
            className="mt-1 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
          >
            {loading ? 'Entrando…' : 'Entrar'}
          </button>
        </form>

        <p className="mt-4 text-center text-xs text-muted">
          Demo: admin@fornodinapoli.test · senha <code>password</code>
        </p>
      </div>
    </div>
  );
}
