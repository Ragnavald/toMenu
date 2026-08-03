/**
 * Dados do cliente guardados entre pedidos.
 *
 * Diferente do carrinho, a chave NÃO é namespaced por loja: é a mesma pessoa
 * pedindo em qualquer restaurante do to-menu, e obrigá-la a redigitar o
 * endereço a cada cardápio novo é justamente o atrito que isto remove.
 *
 * **CPF fica de fora de propósito** e não deve ser adicionado: é dado de
 * documento, e o ganho de conveniência não paga deixá-lo em repouso no
 * aparelho, legível por qualquer script da página. É opcional no checkout e
 * rápido de digitar. Forma de pagamento também fica fora — depende do que cada
 * loja aceita — e observações são específicas do pedido.
 */
export interface StoredCustomer {
  name: string;
  phone: string;
  zip: string;
  street: string;
  number: string;
  complement: string;
  district: string;
  city: string;
  state: string;
}

const STORAGE_KEY = 'tomenu:customer';

const EMPTY: StoredCustomer = {
  name: '',
  phone: '',
  zip: '',
  street: '',
  number: '',
  complement: '',
  district: '',
  city: '',
  state: '',
};

/**
 * Lê o perfil salvo, campo a campo.
 *
 * O merge com EMPTY não é zelo excessivo: o formato pode mudar entre versões e
 * um JSON antigo sem os campos novos deixaria `undefined` num input controlado,
 * que o React trata como não-controlado e reclama em runtime.
 */
export function getStoredCustomer(): StoredCustomer | null {
  if (typeof window === 'undefined') return null;

  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;

    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') return null;

    const merged = { ...EMPTY };
    for (const key of Object.keys(EMPTY) as (keyof StoredCustomer)[]) {
      if (typeof parsed[key] === 'string') merged[key] = parsed[key];
    }

    // Uma versão anterior guardava o CPF. Parar de lê-lo não bastaria: o valor
    // seguiria em repouso no aparelho para sempre. Reescreve o registro sem os
    // campos que saíram do formato — assim o resíduo some na primeira abertura
    // do checkout, sem precisar de migração à parte.
    for (const key of Object.keys(parsed)) {
      if (!(key in EMPTY)) {
        saveStoredCustomer(merged);
        break;
      }
    }

    return merged;
  } catch {
    // localStorage indisponível (modo privado) ou JSON corrompido: o checkout
    // simplesmente começa em branco.
    return null;
  }
}

export function saveStoredCustomer(customer: StoredCustomer): void {
  if (typeof window === 'undefined') return;

  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(customer));
  } catch {
    /* idem */
  }
}

export function clearStoredCustomer(): void {
  if (typeof window === 'undefined') return;

  try {
    window.localStorage.removeItem(STORAGE_KEY);
  } catch {
    /* idem */
  }
}
