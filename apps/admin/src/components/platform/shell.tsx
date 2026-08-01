import { useTheme } from '@/lib/theme';
import { clearPlatformSession, type PlatformSession } from '@/lib/platform';

/**
 * Moldura do painel da plataforma.
 *
 * Deliberadamente mais sóbria que o Shell do lojista, e sem a sidebar de
 * navegação: o painel tem uma seção só. A diferença visual é intencional — o
 * staff precisa saber num relance se está operando a plataforma ou dentro da
 * loja de alguém, principalmente com as duas abas abertas durante um
 * atendimento.
 */
export function PlatformShell({
  session,
  onLogout,
  children,
}: {
  session: PlatformSession;
  onLogout: () => void;
  children: React.ReactNode;
}) {
  const { theme, toggle: toggleTheme } = useTheme();

  return (
    <div className="flex min-h-full flex-col">
      <header className="sticky top-0 z-20 border-b border-line bg-panel/85 backdrop-blur-md">
        <div className="mx-auto flex w-full max-w-[1400px] items-center gap-3 px-4 py-3 sm:px-6">
          <div className="grid size-8 shrink-0 place-items-center rounded-lg bg-ink text-sm font-bold text-panel">
            T
          </div>

          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold leading-tight">
              ToMenu Plataforma
            </p>
            <p className="truncate text-xs text-muted">{session.email}</p>
          </div>

          <button
            type="button"
            onClick={toggleTheme}
            aria-label={
              theme === 'dark' ? 'Mudar para tema claro' : 'Mudar para tema escuro'
            }
            className="grid size-8 shrink-0 place-items-center rounded-lg text-muted transition-colors hover:bg-line hover:text-ink"
          >
            {theme === 'dark' ? <IconSun /> : <IconMoon />}
          </button>

          <button
            type="button"
            onClick={() => {
              clearPlatformSession();
              onLogout();
            }}
            className="shrink-0 rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted transition-colors hover:bg-line"
          >
            Sair
          </button>
        </div>
      </header>

      <main className="mx-auto w-full max-w-[1400px] flex-1 px-4 py-6 sm:px-6">
        {children}
      </main>
    </div>
  );
}

function IconSun() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden>
      <circle cx="12" cy="12" r="4" stroke="currentColor" strokeWidth="1.8" />
      <path
        d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
      />
    </svg>
  );
}

function IconMoon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path
        d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79Z"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}
