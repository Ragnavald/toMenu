import { useState } from 'react';
import { API_BASE, ApiError } from '@/lib/api';
import { Shell } from '@/pages/forgot-password';

/**
 * Tela do link enviado por e-mail: define a senha nova.
 *
 * Os três identificadores (token, e-mail, loja) chegam na query string. O token
 * sozinho não basta porque a conta é o par (loja, e-mail) — o mesmo endereço
 * pode existir em lojas diferentes, e o backend recusa um token usado fora da
 * loja para a qual foi emitido.
 */
export function ResetPasswordPage({ onDone }: { onDone: () => void }) {
  const params = new URLSearchParams(window.location.search);
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';
  const tenant = params.get('tenant') ?? '';

  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [done, setDone] = useState(false);

  // Link truncado ou colado pela metade — acontece com cliente de e-mail que
  // quebra URLs longas. Dizer isso é mais útil que deixar o POST falhar com
  // "token inválido", que sugere problema de conta.
  if (!token || !email || !tenant) {
    return (
      <Shell title="Link incompleto">
        <div className="panel grid gap-3 p-5 text-sm">
          <p>
            Este endereço não tem todos os dados necessários. Ele pode ter sido
            cortado pelo seu programa de e-mail.
          </p>
          <p className="text-muted">
            Peça um link novo e, se possível, copie o endereço inteiro.
          </p>
          <button
            type="button"
            onClick={onDone}
            className="mt-1 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90"
          >
            Voltar ao login
          </button>
        </div>
      </Shell>
    );
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(`${API_BASE}/api/auth/reset-password`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          token,
          email,
          tenant,
          password,
          password_confirmation: confirmation,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        // 422 traz `errors` por campo; a mensagem do token é a informativa
        // aqui (link expirado ou já usado).
        const detail =
          data?.errors?.token?.[0] ??
          data?.errors?.password?.[0] ??
          data?.message;

        throw new ApiError(detail ?? 'Não foi possível redefinir a senha.', response.status);
      }

      setDone(true);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Falha ao redefinir.');
    } finally {
      setLoading(false);
    }
  }

  if (done) {
    return (
      <Shell title="Senha alterada">
        <div className="panel grid gap-3 p-5 text-sm">
          <p>Sua senha foi redefinida. Use a senha nova para entrar.</p>
          <p className="text-muted">
            Por segurança, encerramos as sessões que estavam abertas nesta conta.
          </p>
          <button
            type="button"
            onClick={onDone}
            className="mt-1 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90"
          >
            Ir para o login
          </button>
        </div>
      </Shell>
    );
  }

  return (
    <Shell title="Criar senha nova">
      <form onSubmit={handleSubmit} className="panel grid gap-3.5 p-5">
        <p className="text-xs text-muted">
          Conta <strong>{email}</strong> na loja <strong>{tenant}</strong>.
        </p>

        <div className="grid gap-1.5">
          <label htmlFor="password" className="text-xs font-medium text-muted">
            Senha nova
          </label>
          <input
            id="password"
            type="password"
            required
            minLength={8}
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="field"
          />
          <p className="text-xs text-muted">Ao menos 8 caracteres.</p>
        </div>

        <div className="grid gap-1.5">
          <label htmlFor="confirmation" className="text-xs font-medium text-muted">
            Repita a senha
          </label>
          <input
            id="confirmation"
            type="password"
            required
            autoComplete="new-password"
            value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)}
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
          {loading ? 'Salvando…' : 'Salvar senha nova'}
        </button>
      </form>
    </Shell>
  );
}
