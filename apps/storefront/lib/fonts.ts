import {
  DM_Serif_Display,
  Inter,
  Manrope,
  Playfair_Display,
  Sora,
  Space_Grotesk,
} from 'next/font/google';

/**
 * Fontes disponíveis para os tenants.
 *
 * Allowlist fechada, espelhando ThemeSanitizer::FONTS no backend. next/font
 * precisa conhecer as fontes em build time para auto-hospedá-las (sem requisição
 * a servidores do Google, o que também resolve o custo de privacidade), e
 * aceitar um nome arbitrário permitiria injeção via o bloco <style> do tema.
 */
const inter = Inter({ subsets: ['latin'], display: 'swap' });
const manrope = Manrope({ subsets: ['latin'], display: 'swap' });
const sora = Sora({ subsets: ['latin'], display: 'swap' });
const spaceGrotesk = Space_Grotesk({ subsets: ['latin'], display: 'swap' });
const playfair = Playfair_Display({ subsets: ['latin'], display: 'swap' });
const dmSerif = DM_Serif_Display({
  subsets: ['latin'],
  weight: '400',
  display: 'swap',
});

const FONTS = {
  inter,
  manrope,
  sora,
  'space-grotesk': spaceGrotesk,
  playfair,
  'dm-serif': dmSerif,
} as const;

export type FontKey = keyof typeof FONTS;

/**
 * Classe CSS da fonte escolhida, com fallback seguro.
 *
 * É preciso usar `className` (e não interpolar `style.fontFamily` no bloco de
 * tema): o next/font só emite a regra @font-face e pré-carrega o arquivo para
 * as fontes efetivamente referenciadas por classe. Injetar apenas o nome da
 * família resultava em fallback sans-serif, porque a fonte nunca era carregada.
 */
export function fontClassFor(key: string): string {
  const font = FONTS[key as FontKey] ?? FONTS.inter;

  return font.className;
}
