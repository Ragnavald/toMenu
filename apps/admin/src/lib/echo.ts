import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import { API_BASE, loadSession, SESSION_CLEARED_EVENT } from '@/lib/api';

/**
 * Conexão WebSocket com o Reverb.
 *
 * O painel escuta o canal privado da loja para saber de pedido novo no
 * instante em que ele entra. É o único aviso que a cozinha recebe — o polling
 * da tela de pedidos existe para reconciliar o estado, não para alertar.
 *
 * A autorização do canal usa o mesmo par bearer token + X-Tenant do apiFetch:
 * o backend cruza os dois em routes/channels.php e recusa a assinatura do canal
 * de outra loja.
 */

// O laravel-echo espera o Pusher no escopo global — o protocolo do Reverb é
// compatível com o do Pusher, e é este cliente que ele instancia.
declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

window.Pusher = Pusher;

/*
 * Um 401 em qualquer requisição limpa a sessão — e o socket precisa cair
 * junto. Sem isto ele seguiria aberto e reautorizando canais com o token
 * revogado, gastando uma conexão do Reverb por aba órfã até o reload.
 *
 * O listener fica no módulo, não num componente: o socket também é do módulo,
 * e amarrá-lo ao ciclo de vida de uma tela deixaria a limpeza a cargo de quem
 * por acaso estivesse montado.
 */
window.addEventListener(SESSION_CLEARED_EVENT, () => disconnectEcho());

export type OrderReceivedEvent = {
  orderId: number;
  tenantId: number;
  orderNumber: number;
};

type EchoClient = Echo<'reverb'>;

let echo: EchoClient | null = null;

/**
 * Token e tenant que autenticaram a instância viva.
 *
 * O `auth.headers` do Echo é lido uma vez, na construção: a instância carrega
 * aquele bearer para sempre. Depois de um logout/login — ou de uma troca de
 * loja por impersonation — o socket memoizado seguiria reautorizando canais
 * com credencial morta, e o painel ficaria mudo sem nenhum sinal de erro.
 * Guardar as credenciais permite detectar a troca e reconstruir o socket.
 */
let credentials: { token: string; tenantSlug: string } | null = null;

/**
 * Instância única e preguiçosa.
 *
 * Preguiçosa porque no login a sessão ainda não existe: conectar na carga do
 * módulo produziria um socket sem token que falha na autorização do canal.
 * Única porque cada instância abre um socket próprio — criar uma por render
 * vazaria conexões a cada navegação.
 */
export function getEcho(): EchoClient | null {
  const session = loadSession();

  if (!session) {
    // A sessão acabou (logout ou 401). Deixar o socket aberto manteria uma
    // conexão autenticada com um token que o servidor já não aceita.
    disconnectEcho();
    return null;
  }

  // Credencial diferente da que abriu o socket: derruba e reconstrói.
  if (
    echo &&
    credentials &&
    (credentials.token !== session.token ||
      credentials.tenantSlug !== session.tenantSlug)
  ) {
    disconnectEcho();
  }

  if (echo) return echo;

  const key = import.meta.env.VITE_REVERB_APP_KEY;

  // Sem chave configurada não há servidor para conectar. Devolver null deixa o
  // painel funcionar sem tempo real, em vez de quebrar a tela inteira.
  if (!key) {
    console.warn(
      'VITE_REVERB_APP_KEY ausente: o alerta de pedido novo está desligado.',
    );
    return null;
  }

  credentials = { token: session.token, tenantSlug: session.tenantSlug };

  echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    // Rota registrada em routes/api.php sob auth:sanctum. A padrão do Laravel
    // (/broadcasting/auth) autentica por sessão e recusaria o token do painel.
    authEndpoint: `${API_BASE}/api/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${session.token}`,
        'X-Tenant': session.tenantSlug,
      },
    },
  });

  /*
   * Estado da conexão, espelhado para quem precisa reagir a ele.
   *
   * O Pusher já reconecta sozinho — o valor aqui não é para gerenciar o
   * socket, e sim para a UI saber quando NÃO pode confiar no tempo real e
   * precisa cair no polling. Sem este sinal a tela de pedidos teria de fazer
   * polling permanente, por precaução, contra um canal que quase sempre está
   * saudável.
   */
  echo.connector.pusher.connection.bind('state_change', (states: {
    current: string;
  }) => {
    setConnected(states.current === 'connected');
  });

  return echo;
}

/** Estado atual da conexão e assinantes interessados em mudanças. */
let connected = false;
const listeners = new Set<() => void>();

function setConnected(value: boolean): void {
  if (connected === value) return;

  connected = value;
  for (const listener of listeners) listener();
}

/**
 * Store no formato que o `useSyncExternalStore` espera.
 *
 * Exposto como store em vez de callback porque o React precisa reler o valor
 * durante o render, sem o atraso de um `useEffect` — o polling da tela de
 * pedidos depende dele para decidir a cadência já na primeira renderização.
 */
export const connectionStore = {
  subscribe(listener: () => void): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
  },
  getSnapshot(): boolean {
    return connected;
  },
};

/**
 * Encerra o socket. Chamado no logout: sem isso a conexão seguiria aberta
 * autenticada com o token recém-revogado, e o próximo login criaria uma
 * segunda instância — a instância única guardada aqui só é limpa deste ponto.
 */
export function disconnectEcho(): void {
  echo?.disconnect();
  echo = null;
  credentials = null;
  setConnected(false);
}
