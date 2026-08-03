import { useEffect, useRef, useState } from 'react';
import { API_BASE, saveSession, type Session } from '@/lib/api';
import { Shell } from '@/pages/forgot-password';

/**
 * Tela aberta pelo link de confirmação enviado no cadastro.
 *
 * Confirma sozinha, ao montar: quem chegou aqui já clicou no link do e-mail, e
 * pedir um segundo clique em "confirmar" só somaria um passo sem acrescentar
 * decisão nenhuma.
 *
 * Os três identificadores (token, e-mail, loja) chegam na query string, como no
 * fluxo de senha: o token sozinho não basta porque a conta é o par
 * (loja, e-mail) — o mesmo endereço pode existir em lojas diferentes.
 */
export function VerifyEmailPage({
  onAuthenticated,
  onDone,
}: {
  onAuthenticated: (session: Session) => void;
  onDone: () => void;
}) {
  const params = new URLSearchParams(window.location.search);
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';
  const tenant = params.get('tenant') ?? '';

  const [status, setStatus] = useState<'verifying' | 'expired' | 'done'>(
    'verifying',
  );
  const [message, setMessage] = useState<string | null>(null);
  const [resent, setResent] = useState(false);
  const [sending, setSending] = useState(false);
  // O React 19 monta duas vezes em desenvolvimento (StrictMode). Sem a trava, a
  // segunda montagem consumiria o token de novo — e como ele é de uso único, o
  // lojista veria "link expirado" logo depois de uma confirmação bem-sucedida.
  const attempted = useRef(false);

  useEffect(() => {
    if (attempted.current) return;
    attempted.current = true;

    if (!token || !email || !tenant) {
      setStatus('expired');
      setMessage(
        'Este endereço não tem todos os dados necessários. Ele pode ter sido cortado pelo seu programa de e-mail.',
      );

      return;
    }

    void verify();
  }, []);

  async function verify() {
    try {
      const response = await fetch(`${API_BASE}/api/auth/verify-email`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ token, email, tenant }),
      });

      const data = await response.json();

      if (!response.ok) {
        setStatus('expired');
        setMessage(
          data?.errors?.token?.[0] ??
            data?.message ??
            'Este link de confirmação é inválido ou expirou.',
        );

        return;
      }

      /*
       * Conta já confirmada (link aberto duas vezes): o backend responde 200
       * sem sessão, de propósito. Não há credencial para salvar, então a tela
       * manda para o login em vez de tentar entrar com um token inexistente.
       */
      if (!data.token) {
        setStatus('done');
        setMessage(data.message ?? 'Este e-mail já foi confirmado.');

        return;
      }

      const session: Session = {
        token: data.token,
        tenantSlug: data.tenant.slug,
        tenantName: data.tenant.name,
        userName: data.user.name,
        role: data.user.role,
      };

      saveSession(session);

      // Limpa token e e-mail da barra de endereços antes de entrar: são
      // credenciais que não devem ficar no histórico nem vazar por Referer.
      window.history.replaceState(null, '', '/bem-vindo');

      onAuthenticated(session);
    } catch {
      setStatus('expired');
      setMessage('Não foi possível falar com o servidor. Tente abrir o link de novo.');
    }
  }

  async function handleResend() {
    setSending(true);

    try {
      const response = await fetch(`${API_BASE}/api/auth/verify-email/resend`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, tenant }),
      });

      const data = await response.json();

      if (!response.ok) {
        setMessage(
          data?.errors?.email?.[0] ??
            data?.message ??
            'Não foi possível reenviar o e-mail.',
        );

        return;
      }

      setResent(true);
    } catch {
      setMessage('Não foi possível falar com o servidor.');
    } finally {
      setSending(false);
    }
  }

  if (status === 'verifying') {
    return (
      <Shell title="Confirmando seu e-mail">
        <div className="panel grid gap-3 p-5 text-sm">
          <p className="text-muted">Um instante…</p>
        </div>
      </Shell>
    );
  }

  if (status === 'done') {
    return (
      <Shell title="E-mail confirmado">
        <div className="panel grid gap-3 p-5 text-sm">
          <p>{message}</p>
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
    <Shell title="Link expirado">
      <div className="panel grid gap-3 p-5 text-sm">
        <p>{message}</p>

        {resent ? (
          <p className="text-xs text-emerald-600">
            Enviamos um link novo para <strong>{email}</strong>. Abra o e-mail
            mais recente.
          </p>
        ) : (
          <p className="text-muted">
            Peça um link novo — ele chega no mesmo endereço e vale por mais 30
            minutos.
          </p>
        )}

        {/* Sem e-mail e loja não há a quem reenviar: é o caso do link cortado
            pelo programa de e-mail, em que só o login resolve. */}
        {email && tenant && !resent && (
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
          onClick={onDone}
          className="text-xs text-muted underline-offset-2 hover:underline"
        >
          Voltar ao login
        </button>
      </div>
    </Shell>
  );
}
