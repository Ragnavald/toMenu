'use client';

import { useState, useEffect, useRef } from 'react';
import Image from 'next/image';

interface BeforeInstallPromptEvent extends Event {
  readonly platforms: string[];
  readonly userChoice: Promise<{
    outcome: 'accepted' | 'dismissed';
    platform: string;
  }>;
  prompt(): Promise<void>;
}

export function PwaInstallPrompt() {
  const [showPrompt, setShowPrompt] = useState(false);
  const [promptType, setPromptType] = useState<'beforeinstallprompt' | 'ios' | null>(null);
  const deferredPromptRef = useRef<BeforeInstallPromptEvent | null>(null);

  useEffect(() => {
    // 1. Registro do Service Worker
    const registerSW = () => {
      navigator.serviceWorker
        .register('/sw.js')
        .then((reg) => console.log('PWA: Service Worker registrado com sucesso:', reg.scope))
        .catch((err) => console.error('PWA: Falha ao registrar Service Worker:', err));
    };

    if (typeof window !== 'undefined' && 'serviceWorker' in navigator) {
      if (document.readyState === 'complete') {
        registerSW();
      } else {
        window.addEventListener('load', registerSW);
      }
    }

    // 2. Interceptação do prompt de instalação nativo (Chrome/Android)
    const handleBeforeInstallPrompt = (e: Event) => {
      e.preventDefault();
      deferredPromptRef.current = e as BeforeInstallPromptEvent;
      setPromptType('beforeinstallprompt');

      // Verifica se o usuário já dispensou o prompt recentemente
      const dismissed = localStorage.getItem('pwa-install-dismissed');
      if (!dismissed) {
        setShowPrompt(true);
      }
    };

    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);

    // 3. Verificação específica de iOS Safari
    const checkIosSafari = () => {
      if (typeof window === 'undefined') return;

      const ua = window.navigator.userAgent;
      const isIOS = /iPad|iPhone|iPod/.test(ua);
      const isSafari = /^((?!chrome|android).)*safari/i.test(ua);
      
      // Verifica se já está instalado (standalone)
      const isStandalone = (window.navigator as any).standalone === true || 
                            window.matchMedia('(display-mode: standalone)').matches;

      if (isIOS && isSafari && !isStandalone) {
        setPromptType('ios');
        const dismissed = localStorage.getItem('pwa-install-dismissed');
        if (!dismissed) {
          setShowPrompt(true);
        }
      }
    };

    // Executa a verificação após um pequeno delay para não atrapalhar o carregamento inicial
    const timeoutId = setTimeout(checkIosSafari, 3000);

    return () => {
      window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
      if (typeof window !== 'undefined') {
        window.removeEventListener('load', registerSW);
      }
      clearTimeout(timeoutId);
    };
  }, []);

  const handleInstallClick = async () => {
    setShowPrompt(false);
    const deferredPrompt = deferredPromptRef.current;
    
    if (deferredPrompt) {
      await deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      console.log(`PWA: Escolha do usuário: ${outcome}`);
      deferredPromptRef.current = null;
    }
  };

  const handleDismiss = () => {
    setShowPrompt(false);
    // Guarda a rejeição no localStorage para não insistir na mesma sessão
    localStorage.setItem('pwa-install-dismissed', 'true');
  };

  if (!showPrompt) {
    if (promptType === null) return null;

    return (
      <button
        onClick={() => setShowPrompt(true)}
        className="fixed right-0 top-1/2 -translate-y-1/2 bg-orange-600 hover:bg-orange-700 text-white rounded-l-xl p-2.5 shadow-lg z-[9998] flex items-center gap-1.5 transition-all duration-300 transform translate-x-1 hover:translate-x-0 cursor-pointer border border-r-0 border-white/10"
        style={{
          backgroundColor: 'rgb(var(--brand, 234 88 12))',
          color: 'rgb(var(--brand-ink, 255 255 255))',
        }}
        aria-label="Instalar aplicativo"
      >
        <svg className="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
        </svg>
        <span className="text-[11px] font-semibold pr-1 hidden md:inline">Instalar App</span>
      </button>
    );
  }

  return (
    <div 
      className="fixed bottom-4 left-4 right-4 md:left-auto md:right-4 md:w-96 bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-800 rounded-2xl shadow-xl z-[9999] p-5 animate-rise transition-all duration-300"
      style={{
        boxShadow: '0 10px 30px -10px rgba(0, 0, 0, 0.15)',
        backdropFilter: 'blur(8px)',
      }}
    >
      <div className="flex items-start gap-4">
        {/* Ícone do App */}
        <div className="relative size-12 rounded-xl overflow-hidden shrink-0 border border-neutral-100 dark:border-neutral-800 bg-neutral-50 dark:bg-neutral-900 flex items-center justify-center">
          <Image
            src="/img/icon-192x192.png"
            alt="ToMenu Logo"
            width={48}
            height={48}
            className="object-contain"
          />
        </div>

        {/* Informações */}
        <div className="flex-1 min-w-0">
          <div className="flex items-start justify-between">
            <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-50">
              Instalar ToMenu
            </h3>
            <button 
              onClick={handleDismiss}
              className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 p-1 -mt-1 -mr-1 transition-colors"
              aria-label="Fechar"
            >
              <svg className="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
            Adicione nosso app na tela inicial para acesso mais rápido e prático!
          </p>
        </div>
      </div>

      {/* Conteúdo dinâmico por plataforma */}
      {promptType === 'beforeinstallprompt' ? (
        <div className="mt-4 flex items-center gap-2 justify-end">
          <button
            onClick={handleDismiss}
            className="rounded-lg px-3.5 py-1.5 text-xs font-medium text-neutral-500 dark:text-neutral-400 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition-colors"
          >
            Agora não
          </button>
          <button
            onClick={handleInstallClick}
            className="rounded-lg bg-orange-600 hover:bg-orange-700 text-white px-4 py-1.5 text-xs font-semibold shadow-sm transition-colors"
            style={{
              backgroundColor: 'rgb(var(--brand, 234 88 12))',
              color: 'rgb(var(--brand-ink, 255 255 255))',
            }}
          >
            Adicionar
          </button>
        </div>
      ) : (
        <div className="mt-4 border-t border-neutral-100 dark:border-neutral-800 pt-3">
          <div className="rounded-lg bg-neutral-50 dark:bg-neutral-950 p-3 text-[11px] text-neutral-600 dark:text-neutral-400 leading-relaxed">
            <span className="font-semibold text-neutral-800 dark:text-neutral-200 block mb-1">
              Instruções de instalação:
            </span>
            Para instalar no seu iPhone, toque no botão de 
            <span className="inline-flex items-center mx-1 align-middle">
              <svg className="size-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
              </svg>
            </span>
            **Compartilhar** do Safari e escolha **Adicionar à Tela de Início** 
            <span className="inline-flex items-center mx-1 align-middle font-bold text-neutral-800 dark:text-neutral-200">
              (+)
            </span>.
          </div>
          <div className="mt-3 flex justify-end">
            <button
              onClick={handleDismiss}
              className="rounded-lg bg-neutral-100 hover:bg-neutral-200 dark:bg-neutral-800 dark:hover:bg-neutral-700 text-neutral-700 dark:text-neutral-300 px-4 py-1.5 text-xs font-medium transition-colors"
            >
              Entendi
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
