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

    const channel = echo.private(`tenant.${tenantId}.orders`);

    channel.listen('.order.received', (event: OrderReceivedEvent) => {
      playOrderAlert();
      setLastOrderNumber(event.orderNumber);

      // O evento carrega só o essencial. Invalidar faz a tela de pedidos
      // buscar o registro completo, e mantém o contador da barra lateral certo.
      void queryClient.invalidateQueries({ queryKey: ['orders'] });
    });

    return () => {
      // `leave` remove a assinatura e derruba o canal; sem isto uma troca de
      // tenant (impersonation) deixaria o canal anterior ativo e o painel
      // receberia pedidos de duas lojas.
      echo.leave(`tenant.${tenantId}.orders`);
    };
  }, [tenantId, queryClient]);

  return {
    lastOrderNumber,
    dismiss: () => setLastOrderNumber(null),
  };
}
