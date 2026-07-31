/// <reference types="vite/client" />

interface ImportMetaEnv {
  /**
   * Origem absoluta da API (https://api.to-menu.com), obrigatória no build de
   * produção. Ausente em desenvolvimento, onde o proxy do vite.config.ts
   * resolve `/api` na mesma origem.
   */
  readonly VITE_API_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
