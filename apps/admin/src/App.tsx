import { useEffect, useState } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { apiFetch, loadSession, saveSession, type Session } from '@/lib/api';
import type { Settings } from '@/lib/types';
import { LoginPage } from '@/pages/login';
import { ForgotPasswordPage } from '@/pages/forgot-password';
import { ResetPasswordPage } from '@/pages/reset-password';
import { Shell } from '@/components/shell';
import { OrdersPage } from '@/pages/orders';
import { FinancePage } from '@/pages/finance';
import { MenuPage } from '@/pages/menu';
import { CategoriesPage } from '@/pages/categories';
import { OptionsPage } from '@/pages/options';
import { StorePage } from '@/pages/store';
import { DeliveryPage } from '@/pages/delivery';
import { HoursPage } from '@/pages/hours';
import { AppearancePage } from '@/pages/appearance';
import { OnboardingPage } from '@/pages/onboarding';
import { PlatformApp } from '@/pages/platform/app';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 10_000,
      retry: 1,
      refetchOnWindowFocus: true,
    },
  },
});

/**
 * Recebe uma sessão entregue por fora do login.
 *
 * São duas origens hoje: o cadastro na landing e a impersonação pelo painel da
 * plataforma. As duas passam o token no fragmento (#) e não na query string de
 * propósito — o fragmento não é enviado ao servidor nem gravado em log de
 * acesso. É consumido e apagado da barra de endereço imediatamente.
 */
function consumeHandoff(): Session | null {
  const hash = window.location.hash.slice(1);
  if (!hash) return null;

  const params = new URLSearchParams(hash);
  const token = params.get('token');
  const tenant = params.get('tenant');

  if (!token || !tenant) return null;

  const session: Session = {
    token,
    tenantSlug: tenant,
    tenantName: params.get('name') ?? tenant,
    userName: params.get('user') ?? '',
    // Sem o papel, `isOwner()` devolve false e a sessão perde as telas de dono
    // — na impersonação isso esconderia justamente o que o suporte precisa ver.
    // Continua opcional: o backend é quem decide, este valor só controla o que
    // a interface oferece.
    role: params.get('role') ?? undefined,
  };

  saveSession(session);
  window.history.replaceState(null, '', window.location.pathname);

  return session;
}

export default function App() {
  // O painel da plataforma vive no MESMO bundle, servido em admin.{domínio}.
  // A escolha é feita pelo host e não por rota porque as duas áreas têm
  // sessões, identidades visuais e superfícies de risco distintas — e porque o
  // nginx já separa os dois hosts em server blocks diferentes.
  if (isPlatformHost()) {
    return (
      <QueryClientProvider client={queryClient}>
        <BrowserRouter>
          <PlatformApp />
        </BrowserRouter>
      </QueryClientProvider>
    );
  }

  return <StoreAdminApp />;
}

/**
 * O host atual é o do painel da plataforma?
 *
 * `admin` é subdomínio reservado (ver `IdentifyTenant::RESERVED` e
 * `TenantRegistrar::RESERVED_SLUGS`), então nenhuma loja pode ocupá-lo — a
 * checagem não colide com um slug legítimo.
 *
 * O prefixo cobre desenvolvimento e produção sem configuração: `*.localhost`
 * resolve nativamente, então `admin.localhost:5173` já cai aqui.
 * `VITE_PLATFORM_HOST` fica como escape para hospedagens em que o painel não
 * mora num subdomínio `admin.`.
 */
function isPlatformHost(): boolean {
  const configured = import.meta.env.VITE_PLATFORM_HOST;

  if (configured) return window.location.host === configured;

  return window.location.hostname.startsWith('admin.');
}

/**
 * Telas acessíveis sem sessão: login e o fluxo de senha.
 *
 * Não usa o BrowserRouter porque ele só é montado depois do gate de sessão, e
 * `/redefinir-senha` precisa abrir para quem justamente NÃO consegue entrar —
 * é o link que chega por e-mail. Ler o caminho direto do `window.location`
 * resolve sem reestruturar o gate.
 *
 * O caminho é lido uma vez, no estado inicial: navegar entre estas telas é
 * troca de estado, não de URL, então não há histórico a acompanhar.
 */
function UnauthenticatedRoutes({
  onAuthenticated,
}: {
  onAuthenticated: (session: Session) => void;
}) {
  const [view, setView] = useState<'login' | 'forgot' | 'reset'>(() =>
    window.location.pathname === '/redefinir-senha' ? 'reset' : 'login',
  );

  // Limpa token e e-mail da barra de endereços ao sair da tela de redefinição:
  // são credenciais de uso único que não devem ficar no histórico do navegador
  // nem vazar por Referer para outra origem.
  function backToLogin() {
    window.history.replaceState(null, '', '/');
    setView('login');
  }

  if (view === 'reset') {
    return <ResetPasswordPage onDone={backToLogin} />;
  }

  if (view === 'forgot') {
    return <ForgotPasswordPage onBack={() => setView('login')} />;
  }

  return (
    <LoginPage
      onAuthenticated={onAuthenticated}
      onForgotPassword={() => setView('forgot')}
    />
  );
}

function StoreAdminApp() {
  const [session, setSession] = useState<Session | null>(
    () => consumeHandoff() ?? loadSession(),
  );

  // Sessão limpa por um 401 em qualquer requisição precisa derrubar a UI
  // autenticada, senão a tela fica presa em telas vazias.
  useEffect(() => {
    function handleStorage() {
      setSession(loadSession());
    }

    window.addEventListener('storage', handleStorage);
    return () => window.removeEventListener('storage', handleStorage);
  }, []);

  if (!session) {
    return (
      <QueryClientProvider client={queryClient}>
        <UnauthenticatedRoutes onAuthenticated={setSession} />
      </QueryClientProvider>
    );
  }

  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Routes>
          {/* O wizard ocupa a tela inteira: sem sidebar, sem distração. */}
          <Route path="/bem-vindo" element={<OnboardingShell />} />

          <Route
            path="*"
            element={
              <Shell session={session} onLogout={() => setSession(null)}>
                <StoreRoutes />
              </Shell>
            }
          />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  );
}

/**
 * Rotas do painel, recortadas pelo plano.
 *
 * As telas de pedido, financeiro e entrega não são montadas no plano
 * somente-cardápio: as rotas por trás delas respondem 403, e renderizar uma
 * tela que só sabe mostrar erro é pior do que não ter a rota. O destino padrão
 * acompanha — sem pedidos, a casa do lojista é o cardápio.
 *
 * A query é a mesma do Shell (chave `settings`): o React Query devolve o cache,
 * sem segunda requisição.
 */
function StoreRoutes() {
  const { data: settings } = useQuery({
    queryKey: ['settings'],
    queryFn: () => apiFetch<Settings>('/admin/settings'),
    staleTime: 60_000,
  });

  const allowsOrders = settings?.plan.allowsOrders !== false;
  const home = allowsOrders ? '/pedidos' : '/cardapio';

  return (
    <Routes>
      {allowsOrders && (
        <>
          <Route path="/pedidos" element={<OrdersPage />} />
          <Route path="/financeiro" element={<FinancePage />} />
          <Route path="/entrega" element={<DeliveryPage />} />
        </>
      )}
      <Route path="/cardapio" element={<MenuPage />} />
      <Route path="/categorias" element={<CategoriesPage />} />
      <Route path="/opcoes" element={<OptionsPage />} />
      <Route path="/loja" element={<StorePage />} />
      <Route path="/horarios" element={<HoursPage />} />
      <Route path="/aparencia" element={<AppearancePage />} />
      <Route path="*" element={<Navigate to={home} replace />} />
    </Routes>
  );
}

function OnboardingShell() {
  return (
    <div className="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6 sm:py-12">
      <OnboardingPage />
    </div>
  );
}
