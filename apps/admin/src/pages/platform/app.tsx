import { useState } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import {
  loadPlatformSession,
  type PlatformSession,
} from '@/lib/platform';
import { PlatformShell } from '@/components/platform/shell';
import { PlatformLoginPage } from '@/pages/platform/login';
import { PlatformStoresPage } from '@/pages/platform/stores';
import { PlatformStoreDetailPage } from '@/pages/platform/store-detail';

/**
 * Painel da plataforma, servido em admin.{domínio}.
 *
 * Mora dentro do app do lojista porque compartilha build, tema e cliente HTTP,
 * mas a sessão é totalmente separada (`tomenu:platform:token`): entrar aqui não
 * mexe na sessão de nenhuma loja, e sair daqui não derruba a loja aberta em
 * outra aba.
 */
export function PlatformApp() {
  const [session, setSession] = useState<PlatformSession | null>(() =>
    loadPlatformSession(),
  );

  if (!session) {
    return <PlatformLoginPage onAuthenticated={setSession} />;
  }

  return (
    <PlatformShell session={session} onLogout={() => setSession(null)}>
      <Routes>
        <Route path="/plataforma/lojas" element={<PlatformStoresPage />} />
        <Route
          path="/plataforma/lojas/:slug"
          element={<PlatformStoreDetailPage />}
        />
        <Route path="*" element={<Navigate to="/plataforma/lojas" replace />} />
      </Routes>
    </PlatformShell>
  );
}
