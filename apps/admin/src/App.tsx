import { useEffect, useState } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { loadSession, saveSession, type Session } from '@/lib/api';
import { LoginPage } from '@/pages/login';
import { Shell } from '@/components/shell';
import { OrdersPage } from '@/pages/orders';
import { MenuPage } from '@/pages/menu';
import { CategoriesPage } from '@/pages/categories';
import { StorePage } from '@/pages/store';
import { DeliveryPage } from '@/pages/delivery';
import { HoursPage } from '@/pages/hours';
import { AppearancePage } from '@/pages/appearance';
import { OnboardingPage } from '@/pages/onboarding';

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
 * Recebe a sessão criada no cadastro da landing.
 *
 * O token chega no fragmento (#) e não na query string de propósito: o
 * fragmento não é enviado ao servidor nem gravado em logs de acesso. É
 * consumido e apagado da barra de endereço imediatamente.
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
  };

  saveSession(session);
  window.history.replaceState(null, '', window.location.pathname);

  return session;
}

export default function App() {
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
        <LoginPage onAuthenticated={setSession} />
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
                <Routes>
                  <Route path="/pedidos" element={<OrdersPage />} />
                  <Route path="/cardapio" element={<MenuPage />} />
                  <Route path="/categorias" element={<CategoriesPage />} />
                  <Route path="/loja" element={<StorePage />} />
                  <Route path="/entrega" element={<DeliveryPage />} />
                  <Route path="/horarios" element={<HoursPage />} />
                  <Route path="/aparencia" element={<AppearancePage />} />
                  <Route path="*" element={<Navigate to="/pedidos" replace />} />
                </Routes>
              </Shell>
            }
          />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  );
}

function OnboardingShell() {
  return (
    <div className="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6 sm:py-12">
      <OnboardingPage />
    </div>
  );
}
