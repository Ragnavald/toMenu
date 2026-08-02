/**
 * Injeta JSON-LD no HTML.
 *
 * Server Component: o buscador precisa do bloco no HTML inicial. Inserido por
 * JS no cliente, ele existe para quem executa script e não para o crawler que
 * lê o HTML cru — que é justamente o caso a atender.
 *
 * A escapada de `<` é obrigatória. O conteúdo vem do nome e da descrição que o
 * lojista digita; uma loja chamada `</script><script>…` fecharia a tag e
 * injetaria script na página de todo visitante. Escapar a sequência que fecha
 * a tag remove essa possibilidade sem alterar o JSON — `<` é o mesmo
 * caractere para qualquer parser.
 */
export function JsonLd({ data }: { data: object }) {
  const json = JSON.stringify(data).replace(/</g, '\\u003c');

  return (
    <script
      type="application/ld+json"
      dangerouslySetInnerHTML={{ __html: json }}
    />
  );
}
