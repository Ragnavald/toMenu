import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import { API_BASE, loadSession } from '@/lib/api';

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

export type OrderReceivedEvent = {
  orderId: number;
  tenantId: number;
  orderNumber: number;
};

type EchoClient = Echo<'reverb'>;

let echo: EchoClient | null = null;

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

  if (!session) return null;

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

  return echo;
}

/**
 * Encerra o socket. Chamado no logout: sem isso a conexão seguiria aberta
 * autenticada com o token recém-revogado, e o próximo login criaria uma
 * segunda instância — a instância única guardada aqui só é limpa deste ponto.
 */
export function disconnectEcho(): void {
  echo?.disconnect();
  echo = null;
}
