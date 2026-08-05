import { useEffect, useState } from 'react';
import { useTheme } from '@/lib/theme';
import { NavLink } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiFetch, clearSession, type Session } from '@/lib/api';
import { isUnlocked, unlock } from '@/lib/alert-sound';
import { disconnectEcho } from '@/lib/echo';
import { useOrderAlert } from '@/lib/use-order-alert';
import type { Settings } from '@/lib/types';

type NavItem = {
  to: string;
  label: string;
  icon: React.ReactNode;
  badge?: 'orders';
  /** Item que só existe onde a loja recebe pedidos. */
  needsOrders?: boolean;
};

const NAV: { section: string; items: NavItem[] }[] = [
  {
    section: 'Operação',
    items: [
      { to: '/pedidos', label: 'Pedidos', icon: <IconReceipt />, badge: 'orders', needsOrders: true },
      { to: '/historico', label: 'Histórico', icon: <IconHistory />, needsOrders: true },
      { to: '/financeiro', label: 'Financeiro', icon: <IconChart />, needsOrders: true },
    ],
  },
  {
    section: 'Cardápio',
    items: [
      { to: '/cardapio', label: 'Produtos', icon: <IconMenu /> },
      { to: '/categorias', label: 'Categorias', icon: <IconTag /> },
      { to: '/opcoes', label: 'Opções', icon: <IconOptions /> },
    ],
  },
  {
    section: 'Configurações',
    items: [
      { to: '/loja', label: 'Dados da loja', icon: <IconStore /> },
      { to: '/entrega', label: 'Entrega e pagamento', icon: <IconTruck />, needsOrders: true },
      { to: '/horarios', label: 'Horários', icon: <IconClock /> },
      { to: '/aparencia', label: 'Aparência', icon: <IconPalette /> },
      // Sem `needsOrders`: o plano somente-cardápio também paga mensalidade.
      { to: '/assinatura', label: 'Assinatura', icon: <IconCard /> },
    ],
  },
];

/**
 * Navegação do plano contratado.
 *
 * Enquanto as configurações não chegam, `allowsOrders` é indefinido e o menu
 * mostra tudo — piscar o menu completo e encolher é menos ruim para o lojista
 * Pro (a maioria) do que a navegação aparecer vazia e depois crescer.
 */
function navFor(allowsOrders: boolean): typeof NAV {
  if (allowsOrders) return NAV;

  return NAV.map((group) => ({
    ...group,
    items: group.items.filter((item) => !item.needsOrders),
  })).filter((group) => group.items.length > 0);
}

/**
 * Marca da loja no topo da barra lateral.
 *
 * Antes daqui havia um "T" fixo — o logo da ToMenu, não o da loja. Para quem
 * opera mais de uma loja no mesmo navegador, o painel ficava sem sinal visual
 * de qual delas estava aberta.
 *
 * A inicial do nome é o fallback, não um estado de carregamento: a maioria das
 * lojas nunca sobe logo, e um quadrado vazio seria pior do que a letra. Vale
 * também quando a imagem falha ao carregar — URL externa que saiu do ar, ou
 * objeto já apagado do bucket cuja URL ainda está no banco.
 */
function StoreAvatar({
  logoUrl,
  name,
}: {
  logoUrl: string | null;
  name: string;
}) {
  const [failed, setFailed] = useState(false);

  // Sem isto, trocar o logo por um que falhe (ou voltar para um que funcione)
  // manteria o estado de erro da imagem anterior.
  useEffect(() => setFailed(false), [logoUrl]);

  if (logoUrl && !failed) {
    return (
      <img
        src={logoUrl}
        alt=""
        onError={() => setFailed(true)}
        className="size-8 shrink-0 rounded-lg border border-line object-cover"
      />
    );
  }

  return (
    <div className="grid size-8 shrink-0 place-items-center rounded-lg bg-accent text-sm font-bold text-white">
      {name.trim().charAt(0).toUpperCase() || 'T'}
    </div>
  );
}

export function Shell({
  session,
  onLogout,
  children,
}: {
  session: Session;
  onLogout: () => void;
  children: React.ReactNode;
}) {
  const [mobileOpen, setMobileOpen] = useState(false);
  const { theme, toggle: toggleTheme } = useTheme();

  const [deferredPrompt, setDeferredPrompt] = useState<any>(null);
  const [isInstallable, setIsInstallable] = useState(false);
  const [showIosModal, setShowIosModal] = useState(false);

  useEffect(() => {
    const registerSW = () => {
      navigator.serviceWorker
        .register('/sw.js')
        .then((reg) => console.log('Admin PWA: Service Worker registrado:', reg.scope))
        .catch((err) => console.error('Admin PWA: Falha ao registrar Service Worker:', err));
    };

    // Registro do Service Worker do painel admin
    if ('serviceWorker' in navigator) {
      if (document.readyState === 'complete') {
        registerSW();
      } else {
        window.addEventListener('load', registerSW);
      }
    }

    const handleBeforeInstall = (e: Event) => {
      e.preventDefault();
      setDeferredPrompt(e);
      setIsInstallable(true);
    };

    window.addEventListener('beforeinstallprompt', handleBeforeInstall);

    // Detecção de iOS Safari
    const checkIosSafari = () => {
      const ua = window.navigator.userAgent;
      const isIOS = /iPad|iPhone|iPod/.test(ua);
      const isSafari = /^((?!chrome|android).)*safari/i.test(ua);
      const isStandalone = (window.navigator as any).standalone === true || 
                            window.matchMedia('(display-mode: standalone)').matches;

      if (isIOS && isSafari && !isStandalone) {
        setIsInstallable(true);
      }
    };
    checkIosSafari();

    return () => {
      window.removeEventListener('beforeinstallprompt', handleBeforeInstall);
      window.removeEventListener('load', registerSW);
    };
  }, []);

  const handleInstallClick = async () => {
    if (deferredPrompt) {
      await deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      console.log(`Admin PWA: Escolha do usuário: ${outcome}`);
      setDeferredPrompt(null);
      setIsInstallable(false);
    } else {
      setShowIosModal(true);
    }
  };

  const { data: settings } = useQuery({
    queryKey: ['settings'],
    queryFn: () => apiFetch<Settings>('/admin/settings'),
    staleTime: 60_000,
  });

  // `!== false` e não `=== true`: enquanto as configurações carregam o plano é
  // desconhecido, e assumir o completo evita o menu encolher depois.
  const allowsOrders = settings?.plan.allowsOrders !== false;
  const nav = navFor(allowsOrders);

  // Contador de pedidos ativos no menu lateral: em horário de pico é o número
  // que o operador mais precisa ver sem trocar de tela. No plano
  // somente-cardápio a rota responde 403 — não vale pedir a cada 20s.
  const { data: activeOrders } = useQuery({
    queryKey: ['orders', 'confirmed'],
    queryFn: () => apiFetch<{ total: number }>('/admin/orders?status=confirmed'),
    refetchInterval: 20_000,
    enabled: settings?.plan.allowsOrders === true,
  });

  const storefrontUrl = settings?.store.storefrontUrl;

  /*
   * Trial vencido, sem assinatura encaminhada.
   *
   * A data é conferida aqui e não só pelo `status`: quem marca `past_due` é o
   * `trials:expire`, que roda de madrugada — sem isto o lojista que venceu às
   * 10h passaria o dia inteiro sem aviso. `past_due` continua valendo como
   * gatilho porque o staff pode marcar a loja à mão.
   *
   * Quem tem `active`/`trialing` no Stripe nunca vê a faixa: já cadastrou
   * cartão e a cobrança acontece sozinha.
   */
  const store = settings?.store;
  const trialExpired =
    !!store &&
    store.status !== 'suspended' &&
    !['active', 'trialing'].includes(store.subscriptionStatus ?? '') &&
    (store.status === 'past_due' ||
      (store.trialEndsAt !== null && new Date(store.trialEndsAt) < new Date()));

  // Alerta de pedido novo. Só onde há pedidos: no plano somente-cardápio não
  // existe operação para avisar.
  const alertEnabled = settings?.plan.allowsOrders === true;
  const { lastOrderNumber, dismiss } = useOrderAlert(
    alertEnabled ? settings?.store.id : undefined,
  );

  // O navegador bloqueia áudio até um gesto do usuário. Enquanto isso não
  // acontece o painel oferece o botão abaixo — sem ele o pedido chegaria mudo
  // e ninguém entenderia o porquê.
  const [soundReady, setSoundReady] = useState(isUnlocked);

  useEffect(() => {
    if (!lastOrderNumber) return;

    // O aviso some sozinho: quem está na cozinha não volta ao painel para
    // fechar um toast.
    const timer = setTimeout(dismiss, 20_000);

    return () => clearTimeout(timer);
  }, [lastOrderNumber, dismiss]);

  return (
    <div className="flex min-h-full">
      {/* Backdrop do drawer em telas pequenas. */}
      {mobileOpen && (
        <button
          type="button"
          aria-label="Fechar menu"
          onClick={() => setMobileOpen(false)}
          className="fixed inset-0 z-30 bg-black/40 lg:hidden"
        />
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-line bg-panel transition-transform lg:translate-x-0 ${
          mobileOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="flex items-center gap-2.5 border-b border-line px-4 py-4">
          <StoreAvatar
            logoUrl={settings?.profile.logoUrl ?? null}
            name={settings?.store.name ?? session.tenantName}
          />
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold leading-tight">
              {settings?.store.name ?? session.tenantName}
            </p>
            <p className="truncate text-xs text-muted">
              {settings?.store.slug ?? session.tenantSlug}
            </p>
          </div>
        </div>

        <nav className="min-h-0 flex-1 overflow-y-auto px-2 py-3">
          {nav.map((group) => (
            <div key={group.section} className="mb-4">
              <p className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted">
                {group.section}
              </p>

              <ul className="grid gap-0.5">
                {group.items.map((item) => (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      onClick={() => setMobileOpen(false)}
                      className={({ isActive }) =>
                        `flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
                          isActive
                            ? 'bg-accent/10 text-accent'
                            : 'text-muted hover:bg-line/60 hover:text-ink'
                        }`
                      }
                    >
                      <span aria-hidden className="shrink-0">
                        {item.icon}
                      </span>
                      <span className="flex-1">{item.label}</span>

                      {item.badge === 'orders' && (activeOrders?.total ?? 0) > 0 && (
                        <span className="rounded-full bg-accent px-1.5 py-0.5 text-[11px] font-bold tabular-nums text-white">
                          {activeOrders?.total}
                        </span>
                      )}
                    </NavLink>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>

        <div className="border-t border-line p-3">
          {isInstallable && (
            <button
              type="button"
              onClick={handleInstallClick}
              className="mb-1 flex w-full items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-accent transition-colors hover:bg-line/60 cursor-pointer"
            >
              <IconDownload />
              Instalar app
            </button>
          )}

          {storefrontUrl && (
            <a
              href={storefrontUrl}
              target="_blank"
              rel="noreferrer"
              className="mb-1 flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-muted transition-colors hover:bg-line/60"
            >
              <IconExternal />
              Ver minha loja
            </a>
          )}

          <div className="flex items-center gap-2 px-3 py-1.5">
            <div className="min-w-0 flex-1">
              <p className="truncate text-xs font-medium">{session.userName}</p>
            </div>

            <button
              type="button"
              onClick={toggleTheme}
              aria-label={theme === 'dark' ? 'Mudar para tema claro' : 'Mudar para tema escuro'}
              className="grid size-7 shrink-0 place-items-center rounded-lg text-muted transition-colors hover:bg-line hover:text-ink"
            >
              {theme === 'dark' ? <IconSun /> : <IconMoon />}
            </button>

            <button
              type="button"
              onClick={() => {
                // Antes de limpar a sessão: o socket foi autenticado com o
                // token que está prestes a ser descartado.
                disconnectEcho();
                clearSession();
                onLogout();
              }}
              className="shrink-0 rounded-lg px-2 py-1 text-xs text-muted transition-colors hover:bg-line"
            >
              Sair
            </button>
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col lg:pl-64">
        <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-panel/85 px-4 py-3 backdrop-blur-md lg:hidden">
          <button
            type="button"
            onClick={() => setMobileOpen(true)}
            aria-label="Abrir menu"
            className="grid size-9 place-items-center rounded-lg text-muted hover:bg-line"
          >
            <IconBars />
          </button>
          <StoreAvatar
            logoUrl={settings?.profile.logoUrl ?? null}
            name={settings?.store.name ?? session.tenantName}
          />
          <p className="truncate text-sm font-semibold flex-1">
            {settings?.store.name ?? session.tenantName}
          </p>
          {isInstallable && (
            <button
              type="button"
              onClick={handleInstallClick}
              aria-label="Instalar aplicativo"
              className="grid size-9 place-items-center rounded-lg text-accent hover:bg-line cursor-pointer shrink-0"
            >
              <IconDownload />
            </button>
          )}
        </header>

        {/* O alerta sonoro depende de um gesto do usuário para poder tocar.
            Enquanto o som não é liberado a faixa fica visível — um pedido que
            chega em silêncio é indistinguível de nenhum pedido. */}
        {alertEnabled && !soundReady && (
          <div className="flex items-center gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
            <span className="min-w-0 flex-1">
              O aviso sonoro de pedido novo está desligado.
            </span>
            <button
              type="button"
              onClick={async () => {
                await unlock();
                setSoundReady(isUnlocked());
              }}
              className="shrink-0 rounded-lg bg-amber-900 px-2.5 py-1 font-medium text-amber-50 transition-opacity hover:opacity-90 dark:bg-amber-200 dark:text-amber-950"
            >
              Ativar som
            </button>
          </div>
        )}

        {/* Pedido novo. `aria-live` faz o leitor de tela anunciar sem que o
            foco saia de onde o operador estava. */}
        {lastOrderNumber !== null && (
          <div
            aria-live="assertive"
            className="flex items-center gap-3 border-b border-emerald-200 bg-emerald-50 px-4 py-2.5 text-xs text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-200"
          >
            <span className="min-w-0 flex-1 font-medium">
              Pedido #{lastOrderNumber} acabou de chegar.
            </span>
            <NavLink
              to="/pedidos"
              onClick={dismiss}
              className="shrink-0 rounded-lg bg-emerald-900 px-2.5 py-1 font-medium text-emerald-50 transition-opacity hover:opacity-90 dark:bg-emerald-200 dark:text-emerald-950"
            >
              Ver pedidos
            </NavLink>
            <button
              type="button"
              onClick={dismiss}
              aria-label="Dispensar aviso"
              className="shrink-0 rounded-lg px-1.5 py-1 text-emerald-900/70 transition-colors hover:bg-emerald-900/10 dark:text-emerald-200/70"
            >
              ✕
            </button>
          </div>
        )}

        {/* Faixa de pendência: a loja não aparece publicamente até o wizard
            terminar, e o dono precisa saber disso sem procurar. */}
        {settings && !settings.store.onboardingCompleted && (
          <div className="border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-200">
            <NavLink to="/bem-vindo" className="font-medium underline">
              Termine de configurar sua loja
            </NavLink>{' '}
            para começar a receber pedidos.
          </div>
        )}

        {/* Cobrança pendente. Não é dispensável: some quando for resolvida,
            e enquanto isso a loja segue no ar — a suspensão é decisão do
            staff, não um efeito automático do vencimento. */}
        {trialExpired && (
          <div
            role="status"
            className="border-b border-red-200 bg-red-50 px-4 py-2.5 text-xs text-red-900 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-200"
          >
            Seus 14 dias gratuitos terminaram. Sua conta pode ser suspensa a
            qualquer momento.{' '}
            <NavLink to="/assinatura" className="font-medium underline">
              Assinar agora
            </NavLink>
          </div>
        )}

        <main className="mx-auto w-full max-w-[1600px] flex-1 px-4 py-6 sm:px-6">
          {children}
        </main>
      </div>

      {showIosModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
          <div className="bg-panel max-w-sm w-full rounded-2xl border border-line p-6 shadow-xl">
            <div className="flex items-start gap-4">
              <div className="grid size-12 place-items-center rounded-xl bg-accent/10 text-accent shrink-0">
                <svg className="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                </svg>
              </div>
              <div className="flex-1 min-w-0">
                <h3 className="text-sm font-semibold text-ink">
                  Instalar Painel ToMenu
                </h3>
                <p className="mt-2 text-xs text-muted leading-relaxed">
                  Para instalar o painel de controle em seu iPhone:
                </p>
                <ol className="mt-2 text-xs text-muted list-decimal list-inside space-y-1.5 bg-bg/50 rounded-lg p-3 border border-line">
                  <li>
                    Toque no botão de <strong>Compartilhar</strong> 
                    <span className="inline-flex items-center mx-1 align-middle">
                      <svg className="size-3.5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                      </svg>
                    </span> 
                    na barra inferior do Safari.
                  </li>
                  <li>
                    Role para baixo e selecione <strong>Adicionar à Tela de Início (+)</strong>.
                  </li>
                </ol>
              </div>
            </div>
            <div className="mt-5 flex justify-end">
              <button
                type="button"
                onClick={() => setShowIosModal(false)}
                className="rounded-lg bg-accent text-white px-4 py-2 text-xs font-semibold shadow-sm hover:opacity-90 transition-opacity cursor-pointer"
              >
                Entendi
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/* Ícones inline: evitam uma dependência inteira por seis glifos. */

function IconReceipt() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path d="M6 3v18l2-1.5L10 21l2-1.5L14 21l2-1.5L18 21V3H6Z" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
      <path d="M9.5 8h5M9.5 12h5" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
    </svg>
  );
}

function IconChart() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path d="M4 20V4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
      <path d="M4 20h16" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
      <path d="M8.5 20v-6M13 20V8m4.5 12v-9" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
    </svg>
  );
}

function IconMenu() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path d="M4 6h16M4 12h16M4 18h10" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
    </svg>
  );
}

function IconTag() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path d="M4 12.5V5a1 1 0 0 1 1-1h7.5L20 11.5 12.5 19 4 12.5Z" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
      <circle cx="8.5" cy="8.5" r="1.3" fill="currentColor" />
    </svg>
  );
}

/** Círculo dividido: a metáfora do meio a meio, que é o caso central da tela. */
function IconOptions() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden>
      <circle cx="12" cy="12" r="8" stroke="currentColor" strokeWidth="1.7" />
      <path d="M12 4v16" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
      <circle cx="8.4" cy="10" r="1.1" fill="currentColor" />
      <circle cx="8.4" cy="14.4" r="1.1" fill="currentColor" />
    </svg>
  );
}

function IconStore() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path d="M4 9h16v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V9Z" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
      <path d="M3 9l1.5-5h15L21 9" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
    </svg>
  );
}

function IconTruck() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path d="M3 7h11v9H3V7Zm11 3h4l3 3v3h-7v-6Z" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
      <circle cx="7" cy="18" r="1.6" stroke="currentColor" strokeWidth="1.7" />
      <circle cx="17" cy="18" r="1.6" stroke="currentColor" strokeWidth="1.7" />
    </svg>
  );
}

function IconClock() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden>
      <circle cx="12" cy="12" r="8.5" stroke="currentColor" strokeWidth="1.7" />
      <path d="M12 7.5V12l3 2" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
    </svg>
  );
}

function IconHistory() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path
        d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1M3.5 5v4h4"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      <path d="M12 7.5V12l3 2" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
    </svg>
  );
}

function IconCard() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden>
      <rect
        x="3"
        y="5.5"
        width="18"
        height="13"
        rx="2.5"
        stroke="currentColor"
        strokeWidth="1.7"
      />
      <path d="M3 10h18" stroke="currentColor" strokeWidth="1.7" />
    </svg>
  );
}

function IconPalette() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path d="M12 3.5a8.5 8.5 0 0 0 0 17c1.2 0 1.8-.8 1.8-1.6 0-.9-.7-1.4-.7-2.2 0-.7.6-1.3 1.4-1.3h1.4A4.6 4.6 0 0 0 20.5 11c0-4.2-3.8-7.5-8.5-7.5Z" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round" />
      <circle cx="8" cy="11" r="1.2" fill="currentColor" />
      <circle cx="12" cy="8" r="1.2" fill="currentColor" />
    </svg>
  );
}

function IconExternal() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path d="M14 4h6v6M20 4l-8 8M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function IconBars() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
    </svg>
  );
}

function IconSun() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden>
      <circle cx="12" cy="12" r="4" stroke="currentColor" strokeWidth="1.8" />
      <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
    </svg>
  );
}

function IconMoon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79Z" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function IconDownload() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden>
      <path d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1M12 4v12m0 0l-4-4m4 4l4-4" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}
