'use client';

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import type { Fulfillment } from '@/lib/types';

type FulfillmentContextValue = {
  /** Modalidade escolhida. Sempre uma das ofertadas pela loja. */
  fulfillment: Fulfillment;
  setFulfillment: (value: Fulfillment) => void;
  /** Opções ofertadas, na ordem vinda da API. */
  options: Fulfillment[];
};

const FulfillmentContext = createContext<FulfillmentContextValue | null>(null);

/**
 * Modalidade de recebimento escolhida no cardápio.
 *
 * Fica num provider — e não dentro do checkout — porque a escolha acontece
 * antes: o cliente decide "entregar ou consumir aqui" no topo do cardápio e o
 * checkout apenas herda. Guardar no contexto mantém as duas telas em uma única
 * fonte de verdade, e o preço mostrado na barra do carrinho já reflete se há
 * taxa de entrega.
 */
export function FulfillmentProvider({
  tenantSlug,
  options,
  children,
}: {
  tenantSlug: string;
  options: Fulfillment[];
  children: ReactNode;
}) {
  // Uma loja sempre oferta ao menos uma modalidade; o fallback cobre o caso
  // degenerado de todas desligadas, onde o checkout seria recusado de qualquer
  // forma pela API.
  const fallback: Fulfillment = options[0] ?? 'delivery';
  const [fulfillment, setFulfillment] = useState<Fulfillment>(fallback);

  const storageKey = `tomenu:fulfillment:${tenantSlug}`;

  // `options` chega serializada do servidor e é um array novo a cada render;
  // usar a lista como string mantém o efeito atrelado ao conteúdo, não à
  // identidade — do contrário ele reexecutaria sempre e desfaria a escolha.
  const optionsKey = options.join(',');

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(storageKey);

      // A loja pode ter desligado a modalidade desde a última visita. Restaurar
      // uma opção que não é mais ofertada levaria a um pedido recusado no fim
      // do checkout, quando o cliente já preencheu tudo.
      if (saved && optionsKey.split(',').includes(saved)) {
        setFulfillment(saved as Fulfillment);
      }
    } catch {
      // localStorage indisponível (modo privado, cota cheia): a escolha vale
      // só para esta sessão.
    }
  }, [storageKey, optionsKey]);

  const choose = useCallback(
    (value: Fulfillment) => {
      setFulfillment(value);

      try {
        window.localStorage.setItem(storageKey, value);
      } catch {
        /* idem */
      }
    },
    [storageKey],
  );

  const value = useMemo<FulfillmentContextValue>(
    () => ({
      fulfillment,
      setFulfillment: choose,
      options: optionsKey ? (optionsKey.split(',') as Fulfillment[]) : [],
    }),
    [fulfillment, choose, optionsKey],
  );

  return (
    <FulfillmentContext.Provider value={value}>
      {children}
    </FulfillmentContext.Provider>
  );
}

export function useFulfillment(): FulfillmentContextValue {
  const context = useContext(FulfillmentContext);

  if (!context) {
    throw new Error('useFulfillment precisa estar dentro de <FulfillmentProvider>.');
  }

  return context;
}
