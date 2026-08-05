import { useState } from 'react';

export function PwaBanner() {
  const [show, setShow] = useState(() => {
    if (typeof window === 'undefined') return false;
    const isStandalone = (window.navigator as any).standalone === true || 
                          window.matchMedia('(display-mode: standalone)').matches;
    const isMobile = /iPad|iPhone|iPod|Android/i.test(window.navigator.userAgent);
    const dismissed = localStorage.getItem('admin-pwa-banner-dismissed');
    return isMobile && !isStandalone && !dismissed;
  });

  if (!show) return null;

  const isIos = /iPhone|iPad|iPod/i.test(window.navigator.userAgent);

  return (
    <div className="flex items-center justify-between gap-3 border border-line bg-panel rounded-xl px-4 py-2.5 text-xs text-ink mb-4 shadow-sm">
      <div className="flex items-center gap-2 min-w-0">
        <span className="grid size-5 place-items-center rounded bg-accent/10 text-accent shrink-0">
          <svg className="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
          </svg>
        </span>
        <span className="truncate font-medium">
          {isIos 
            ? 'Abra o ToMenu pela sua tela de início para usar o aplicativo.'
            : 'Você tem o aplicativo instalado? Continue usando no App.'}
        </span>
      </div>
      <div className="flex items-center gap-2 shrink-0">
        {!isIos && (
          <a
            href={window.location.href}
            className="rounded-lg bg-accent text-white px-2.5 py-1 font-semibold transition-opacity hover:opacity-90 cursor-pointer"
          >
            Abrir no App
          </a>
        )}
        <button
          type="button"
          onClick={() => {
            setShow(false);
            localStorage.setItem('admin-pwa-banner-dismissed', 'true');
          }}
          className="rounded p-1 text-muted hover:bg-line transition-colors cursor-pointer"
          aria-label="Fechar aviso"
        >
          ✕
        </button>
      </div>
    </div>
  );
}
