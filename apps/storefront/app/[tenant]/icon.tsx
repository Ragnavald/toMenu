import { ImageResponse } from 'next/og';
import { fetchMenuResult } from '@/lib/api';
import { getContrastInk } from '@/lib/contrast';

/**
 * Favicon por loja.
 *
 * Sem este arquivo toda loja herda o ícone da plataforma: a aba do navegador, o
 * atalho na tela inicial e o resultado do Google exibem o logo da ToMenu no
 * lugar da marca do restaurante. Para o lojista isso lê como "meu site é de
 * outra empresa" — e para o cliente que tem cinco abas abertas, nenhuma delas é
 * reconhecível como a pizzaria que ele estava vendo.
 *
 * O ícone é gerado a partir do tema da loja, não do logo enviado: em 32×32 um
 * logo com nome escrito vira borrão ilegível, enquanto a inicial na cor da
 * marca continua distinguível. É também o que garante ícone para toda loja,
 * inclusive as que nunca subiram logo.
 */
export const size = { width: 32, height: 32 };
export const contentType = 'image/png';

type Props = { params: Promise<{ tenant: string }> };

export default async function Icon({ params }: Props) {
  const { tenant } = await params;
  const result = await fetchMenuResult(tenant);

  // Loja inexistente ou suspensa cai no ícone neutro: sem tema não há cor de
  // marca, e inventar uma seria pior que a ausência.
  const theme = result.status === 'ok' ? result.menu.theme : null;
  const name = result.status === 'ok' ? result.menu.tenant.name : 'ToMenu';

  const brand = theme?.brand ?? '17 24 39';
  const ink = theme?.brandInk ?? getContrastInk(brand);

  return new ImageResponse(
    (
      <div
        style={{
          width: '100%',
          height: '100%',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          background: `rgb(${brand})`,
          color: `rgb(${ink})`,
          fontSize: 20,
          fontWeight: 700,
          // Quadrado com cantos suaves: em 32px um raio maior come a letra.
          borderRadius: 6,
        }}
      >
        {initialOf(name)}
      </div>
    ),
    size,
  );
}

/**
 * Inicial exibida no ícone.
 *
 * Usa a primeira letra da primeira palavra significativa — "O Forno de Minas"
 * vira F, não O. Artigos iniciais são comuns em nome de restaurante e não
 * distinguem uma loja da outra, que é a única função do ícone neste tamanho.
 */
function initialOf(name: string): string {
  const skip = new Set(['o', 'a', 'os', 'as', 'do', 'da', 'de', 'e']);

  const word =
    name
      .trim()
      .split(/\s+/)
      .find((part) => !skip.has(part.toLowerCase())) ?? name.trim();

  return (word[0] ?? '?').toUpperCase();
}
