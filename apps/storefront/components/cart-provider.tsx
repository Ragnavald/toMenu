'use client';

import {
  createContext,
  useContext,
  useEffect,
  useMemo,
  useReducer,
  type ReactNode,
} from 'react';
import type { CartLine, Modifier, Product } from '@/lib/types';

type CartState = { lines: CartLine[] };

type Action =
  | { type: 'add'; product: Product; modifiers: Modifier[]; quantity: number }
  | { type: 'setQuantity'; key: string; quantity: number }
  | { type: 'remove'; key: string }
  | { type: 'clear' }
  | { type: 'hydrate'; lines: CartLine[] };

/**
 * Identidade da linha do carrinho.
 *
 * O mesmo produto com modificadores diferentes (pizza grande vs. média) precisa
 * ocupar linhas distintas; com os mesmos modificadores, deve somar quantidade.
 * Os IDs são ordenados para que a ordem de seleção do usuário não gere duas
 * linhas equivalentes.
 */
function lineKey(productId: number, modifiers: Modifier[]): string {
  const ids = modifiers.map((m) => m.id).sort((a, b) => a - b);
  return `${productId}:${ids.join('-')}`;
}

function reducer(state: CartState, action: Action): CartState {
  switch (action.type) {
    case 'hydrate':
      return { lines: action.lines };

    case 'add': {
      const key = lineKey(action.product.id, action.modifiers);
      const unitPriceCents =
        (action.product.promoPriceCents ?? action.product.priceCents) +
        action.modifiers.reduce((sum, m) => sum + m.priceDeltaCents, 0);

      const existing = state.lines.find((line) => line.key === key);

      if (existing) {
        return {
          lines: state.lines.map((line) =>
            line.key === key
              ? { ...line, quantity: line.quantity + action.quantity }
              : line,
          ),
        };
      }

      return {
        lines: [
          ...state.lines,
          {
            key,
            productId: action.product.id,
            name: action.product.name,
            unitPriceCents,
            quantity: action.quantity,
            modifiers: action.modifiers.map((m) => ({
              id: m.id,
              name: m.name,
              priceDeltaCents: m.priceDeltaCents,
            })),
          },
        ],
      };
    }

    case 'setQuantity':
      return {
        lines: state.lines.flatMap((line) =>
          line.key !== action.key
            ? [line]
            : action.quantity <= 0
              ? []
              : [{ ...line, quantity: action.quantity }],
        ),
      };

    case 'remove':
      return { lines: state.lines.filter((line) => line.key !== action.key) };

    case 'clear':
      return { lines: [] };
  }
}

type CartContextValue = {
  lines: CartLine[];
  subtotalCents: number;
  itemCount: number;
  add: (product: Product, modifiers: Modifier[], quantity?: number) => void;
  setQuantity: (key: string, quantity: number) => void;
  remove: (key: string) => void;
  clear: () => void;
};

const CartContext = createContext<CartContextValue | null>(null);

export function CartProvider({
  tenantSlug,
  children,
}: {
  tenantSlug: string;
  children: ReactNode;
}) {
  const [state, dispatch] = useReducer(reducer, { lines: [] });

  // Chave namespaced por loja. Sem isso, um cliente que navega entre dois
  // restaurantes acaba com itens de ambos no mesmo carrinho.
  const storageKey = `tomenu:cart:${tenantSlug}`;

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(storageKey);
      if (saved) dispatch({ type: 'hydrate', lines: JSON.parse(saved) });
    } catch {
      // localStorage pode estar indisponível (modo privado, cota cheia).
      // O carrinho continua funcionando apenas em memória.
    }
  }, [storageKey]);

  useEffect(() => {
    try {
      window.localStorage.setItem(storageKey, JSON.stringify(state.lines));
    } catch {
      /* idem */
    }
  }, [state.lines, storageKey]);

  const value = useMemo<CartContextValue>(() => {
    const subtotalCents = state.lines.reduce(
      (sum, line) => sum + line.unitPriceCents * line.quantity,
      0,
    );

    return {
      lines: state.lines,
      subtotalCents,
      itemCount: state.lines.reduce((sum, line) => sum + line.quantity, 0),
      add: (product, modifiers, quantity = 1) =>
        dispatch({ type: 'add', product, modifiers, quantity }),
      setQuantity: (key, quantity) =>
        dispatch({ type: 'setQuantity', key, quantity }),
      remove: (key) => dispatch({ type: 'remove', key }),
      clear: () => dispatch({ type: 'clear' }),
    };
  }, [state.lines]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart(): CartContextValue {
  const context = useContext(CartContext);

  if (!context) {
    throw new Error('useCart precisa estar dentro de <CartProvider>.');
  }

  return context;
}
