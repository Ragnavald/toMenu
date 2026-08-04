import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';

import { getEcho, type OrderReceivedEvent } from '@/lib/echo';
import { playOrderAlert } from '@/lib/alert-sound';

/**
 * Assina o canal da loja e anuncia cada pedido novo.
 *
 * Fica montado no shell, não na tela de pedidos: em horário de pico o operador
 * costuma estar em outra aba do painel, e é justamente aí que o alerta importa.
 */
export function useOrderAlert(tenantId: number | undefined): {
  lastOrderNumber: number | null;
  dismiss: () => void;
} {
  const queryClient = useQueryClient();
  const [lastOrderNumber, setLastOrderNumber] = useState<number | null>(null);

  useEffect(() => {
    // Sem tenant resolvido ainda não há canal a assinar. O efeito roda de novo
    // quando as configurações carregam.
    if (!tenantId) return;

    const echo = getEcho();

    if (!echo) return;

    const channelName = `tenant.${tenantId}.orders`;
    const channel = echo.private(channelName);

    const handler = (event: OrderReceivedEvent) => {
      playOrderAlert();
      setLastOrderNumber(event.orderNumber);

      // O evento carrega só o essencial. Invalidar faz a tela de pedidos
      // buscar o registro completo, e mantém o contador da barra lateral certo.
      void queryClient.invalidateQueries({ queryKey: ['orders'] });
    };

    channel.listen('.order.received', handler);

    return () => {
      /*
       * A ordem importa e as duas chamadas são necessárias.
       *
       * `stopListening` desfaz este binding específico; sem ele, um efeito que
       * reexecuta (StrictMode em dev, ou o tenant resolvendo depois das
       * settings) empilha handlers no mesmo canal e o alerta toca duas, três
       * vezes por pedido.
       *
       * `leave` derruba a assinatura; sem ele uma troca de loja por
       * impersonation manteria o canal anterior vivo e o painel receberia
       * pedidos das duas.
       *
       * O `echo` capturado no closure é o mesmo que criou a assinatura — usar
       * `getEcho()` aqui poderia devolver uma instância nova (após troca de
       * token) e deixar o canal antigo pendurado no socket morto.
       */
      channel.stopListening('.order.received', handler);
      echo.leave(channelName);
    };
  }, [tenantId, queryClient]);

  return {
    lastOrderNumber,
    dismiss: () => setLastOrderNumber(null),
  };
}
