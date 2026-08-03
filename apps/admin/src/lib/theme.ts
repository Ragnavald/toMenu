import { useEffect, useState } from 'react';

type Theme = 'light' | 'dark';

const STORAGE_KEY = 'tomenu-admin-theme';

function getSystemTheme(): Theme {
  return window.matchMedia('(prefers-color-scheme: dark)').matches
    ? 'dark'
    : 'light';
}

function getStoredTheme(): Theme | null {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    return stored === 'light' || stored === 'dark' ? stored : null;
  } catch {
    return null;
  }
}

function applyTheme(theme: Theme) {
  const root = document.documentElement;
  root.classList.toggle('dark', theme === 'dark');
  root.style.colorScheme = theme;
}

/**
 * Aplica o tema antes do React montar, evitando o flash de tema errado.
 * Chamado no main.tsx antes do createRoot.
 */
export function initTheme() {
  applyTheme(getStoredTheme() ?? getSystemTheme());
}

/**
 * Força o tema claro enquanto o componente estiver montado.
 *
 * Serve ao wizard de boas-vindas, que é a primeira tela do lojista e a única
 * apresentada em versão clara — as capturas e o material de apresentação são
 * claros, e o escuro entregava uma primeira impressão diferente conforme o
 * sistema operacional de quem cadastrou.
 *
 * NÃO grava nada: a preferência do lojista continua no localStorage e volta a
 * valer assim que ele entra no painel. Sobrescrevê-la faria quem escolheu o
 * escuro perder a escolha por ter passado pelo cadastro.
 */
export function useForcedLightTheme() {
  useEffect(() => {
    applyTheme('light');

    return () => {
      // Na saída devolve o que estava valendo — a escolha salva, ou o sistema
      // para quem nunca tocou no toggle.
      applyTheme(getStoredTheme() ?? getSystemTheme());
    };
  }, []);
}

/**
 * Hook que gerencia o tema do admin.
 *
 * Persiste a preferência em localStorage, isolada do storefront.
 * A classe `.dark` é aplicada no `<html>` — o Tailwind v4 consome via
 * `@custom-variant dark`.
 */
export function useTheme() {
  const [theme, setThemeState] = useState<Theme>(
    () => getStoredTheme() ?? getSystemTheme(),
  );

  useEffect(() => {
    applyTheme(theme);
    localStorage.setItem(STORAGE_KEY, theme);
  }, [theme]);

  const toggle = () =>
    setThemeState((prev) => (prev === 'dark' ? 'light' : 'dark'));

  return { theme, toggle } as const;
}
