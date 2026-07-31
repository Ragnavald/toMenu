/**
 * Tela pública de loja suspensa.
 *
 * Deliberadamente neutra: não diz o motivo da suspensão. Quem chega aqui é o
 * cliente final, vindo do QR code ou de um link no WhatsApp, e expor que o
 * lojista está com pendência seria constrangê-lo diante dos próprios clientes.
 *
 * Não usa as variáveis de tema da loja — o layout do tenant aborta antes de
 * aplicá-las, então cores e fontes da marca não existem neste ponto da árvore.
 */
export function StoreSuspended({ storeName }: { storeName?: string }) {
  return (
    <main className="mx-auto grid min-h-dvh max-w-md place-items-center px-6 text-center">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">
          {storeName ? `${storeName} está temporariamente indisponível` : 'Loja temporariamente indisponível'}
        </h1>

        <p className="mt-3 text-muted">
          Esta loja está suspensa e não está recebendo pedidos no momento.
        </p>

        <p className="mt-6 text-sm text-muted">
          Para reativar, entre em contato com a equipe ToMenu.
        </p>
      </div>
    </main>
  );
}
