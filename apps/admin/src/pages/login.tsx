import { useRef, useState } from 'react';
import { API_BASE, ApiError, saveSession, type Session } from '@/lib/api';
import { Shell } from '@/pages/forgot-password';
import {
  Turnstile,
  isTurnstileEnabled,
  type TurnstileHandle,
} from '@/components/turnstile';

export function LoginPage({
  onAuthenticated,
  onForgotPassword,
}: {
  onAuthenticated: (session: Session) => void;
  onForgotPassword: () => void;
}) {
  // Vazio, e não pré-preenchido com uma loja: o valor de demonstração que
  // ficava aqui aparecia para todo lojista e sugeria que ele deveria entrar
  // numa loja que não é a dele.
  const [tenant, setTenant] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [turnstileToken, setTurnstileToken] = useState('');
  const turnstileRef = useRef<TurnstileHandle | null>(null);
  // Credenciais certas, e-mail ainda por confirmar: troca o formulário pelo
  // aviso com o reenvio do link.
  const [unverified, setUnverified] = useState<{
    email: string;
    tenant: string;
  } | null>(null);

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
        body: JSON.stringify({
          email,
          password,
          tenant,
          'cf-turnstile-response': turnstileToken,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        // O token vale uma verificação só: sem resetar, a segunda tentativa
        // reenviaria um token gasto e falharia mesmo com a senha certa.
        turnstileRef.current?.reset();

        /*
         * Conta com e-mail pendente: a senha está certa, falta só confirmar.
         *
         * Tratado à parte do erro genérico porque a saída é outra — mostrar
         * "falha ao entrar" em vermelho não diz o que fazer, e o lojista que
         * perdeu o e-mail (ou deixou o link expirar) ficaria sem caminho
         * nenhum. O `code` é o contrato com o backend; a mensagem pode mudar.
         */
        if (data?.code === 'email_unverified') {
          setUnverified({ email: data.email ?? email, tenant: data.tenant ?? tenant });

          return;
        }

        throw new ApiError(data?.message ?? 'Falha ao entrar.', response.status);
      }

      const session: Session = {
        token: data.token,
        tenantSlug: data.tenant.slug,
        tenantName: data.tenant.name,
        userName: data.user.name,
        role: data.user.role,
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

  if (unverified) {
    return (
      <UnverifiedNotice
        email={unverified.email}
        tenant={unverified.tenant}
        onBack={() => setUnverified(null)}
      />
    );
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
            // Sem token o backend recusaria de qualquer forma; desabilitar
            // evita a ida perdida enquanto o widget ainda resolve.
            disabled={loading || (isTurnstileEnabled() && turnstileToken === '')}
            className="mt-1 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
          >
            {loading ? 'Entrando…' : 'Entrar'}
          </button>

          <button
            type="button"
            onClick={onForgotPassword}
            className="text-xs text-muted underline-offset-2 hover:underline"
          >
            Esqueci minha senha
          </button>
        </form>
      </div>
    </div>
  );
}

/**
 * "Confirme seu e-mail antes de prosseguir", na tentativa de login.
 *
 * Aparece quando a senha confere mas a conta ainda não foi confirmada — o caso
 * de quem se cadastrou, fechou a aba e voltou depois, ou deixou o link
 * expirar. O reenvio é a única saída possível daqui, então é ele que ganha o
 * botão principal.
 */
function UnverifiedNotice({
  email,
  tenant,
  onBack,
}: {
  email: string;
  tenant: string;
  onBack: () => void;
}) {
  const [sent, setSent] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleResend() {
    setSending(true);
    setError(null);

    try {
      const response = await fetch(`${API_BASE}/api/auth/verify-email/resend`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, tenant }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new ApiError(
          data?.errors?.email?.[0] ?? data?.message ?? 'Não foi possível reenviar.',
          response.status,
        );
      }

      setSent(true);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Falha ao reenviar.');
    } finally {
      setSending(false);
    }
  }

  return (
    <Shell title="Confirme seu e-mail">
      <div className="panel grid gap-3 p-5 text-sm">
        <p>
          Sua conta em <strong>{tenant}</strong> ainda não foi confirmada. Abra
          o link que enviamos para <strong>{email}</strong> para liberar o
          acesso ao painel.
        </p>

        {sent ? (
          <p className="text-xs text-emerald-600">
            Link reenviado. Verifique sua caixa de entrada e o spam.
          </p>
        ) : (
          <p className="text-xs text-muted">
            Se o link expirou ou não chegou, peça outro.
          </p>
        )}

        {error && (
          <p
            role="alert"
            className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950/40 dark:text-red-300"
          >
            {error}
          </p>
        )}

        {!sent && (
          <button
            type="button"
            onClick={handleResend}
            disabled={sending}
            className="mt-1 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
          >
            {sending ? 'Enviando…' : 'Reenviar o link'}
          </button>
        )}

        <button
          type="button"
          onClick={onBack}
          className="text-xs text-muted underline-offset-2 hover:underline"
        >
          Voltar ao login
        </button>
      </div>
    </Shell>
  );
}
