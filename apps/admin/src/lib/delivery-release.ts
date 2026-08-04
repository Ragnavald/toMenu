const STORAGE_KEY = 'tomenu:admin:delivered-by-drag';

/**
 * Teto de IDs guardados.
 *
 * O conjunto só cresce — um pedido arquivado nunca mais aparece no painel para
 * ser removido daqui. Sem o corte, um restaurante movimentado acumularia meses
 * de IDs no localStorage, que tem cota de poucos megabytes por origem: estourar
 * faz o `setItem` lançar, e aí a liberação para de persistir justamente na loja
 * com mais pedidos. Mil cobre com folga o painel de qualquer dia.
 */
const MAX_ENTRIES = 1000;

/**
 * Pedidos liberados para finalizar, por terem sido arrastados até "Entregue".
 *
 * Mora no `localStorage` e não em memória para sobreviver ao F5 e à troca de
 * aba: sem isso o lojista que recarregasse a página perderia a liberação e
 * teria de arrastar de novo um card que já está na coluna certa — parecendo
 * defeito, não regra.
 *
 * É uma trava de interface, não um dado do pedido. Por isso não vira coluna no
 * banco: quem impede arquivar o que não está entregue é o servidor, que recusa
 * com 422. Aqui só se registra por onde o lojista passou nesta máquina.
 */
export function readDeliveredByDrag(): Set<number> {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return new Set();
    }

    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) {
      return new Set();
    }

    // O conteúdo é editável pelo usuário e pode ter sido escrito por uma versão
    // antiga: filtrar aqui evita que um valor estranho vire `NaN` no `has()`.
    return new Set(
      parsed.filter((id): id is number => typeof id === 'number' && id > 0),
    );
  } catch {
    // localStorage bloqueado (modo privado, cookies desativados) ou JSON
    // corrompido. A tela continua funcionando, só exigindo o arraste de novo.
    return new Set();
  }
}

export function writeDeliveredByDrag(ids: Set<number>): void {
  try {
    // Os mais recentes são os que importam: o painel mostra o movimento de
    // agora, e um pedido antigo já saiu de cena.
    const trimmed = [...ids].slice(-MAX_ENTRIES);
    localStorage.setItem(STORAGE_KEY, JSON.stringify(trimmed));
  } catch {
    // Cota estourada ou storage indisponível — a liberação vale só para esta
    // sessão, que é degradação aceitável para uma trava de UI.
  }
}
