import Image from 'next/image';

/**
 * Logotipo do ToMenu com troca automática por esquema de cor.
 *
 * A arte original tem o texto em cinza-ardósia, que some no tema escuro. O
 * <picture> troca o arquivo no próprio navegador — sem JS e sem esperar a
 * hidratação, então o logo nunca aparece errado por um instante.
 *
 * `variant` escolhe o lockup: `wordmark` (ícone + ToMenu) para cabeçalhos, onde
 * a tagline viraria um borrão ilegível; `full` inclui a tagline e só serve em
 * espaços generosos.
 */
const LOCKUPS = {
  wordmark: { light: '/tomenu-wordmark.png', dark: '/tomenu-wordmark-dark.png', width: 560, height: 133 },
  full: { light: '/tomenu-logo.png', dark: '/tomenu-logo-dark.png', width: 720, height: 298 },
} as const;

export function BrandLogo({
  className = '',
  variant = 'wordmark',
  priority = false,
  scheme = 'auto',
}: {
  className?: string;
  variant?: keyof typeof LOCKUPS;
  priority?: boolean;
  /**
   * Qual fundo o logo vai encontrar.
   *
   * `auto` segue a preferência do sistema, o que só está certo quando a página
   * também segue. O cardápio da loja NÃO segue: ele fixa `colorScheme: light`
   * e pinta o fundo com o tema do lojista. Um visitante com o celular em modo
   * escuro recebia ali a arte clara — o texto "ToMenu" em branco sobre fundo
   * branco, sobrando só o ícone.
   *
   * `light` significa "fundo claro, use a arte escura" (e vice-versa).
   */
  scheme?: 'auto' | 'light' | 'dark';
}) {
  const lockup = LOCKUPS[variant];

  // Fundo fixo: escolhe o arquivo direto, sem <picture> e sem media query.
  if (scheme !== 'auto') {
    return (
      <Image
        src={scheme === 'dark' ? lockup.dark : lockup.light}
        alt="ToMenu"
        width={lockup.width}
        height={lockup.height}
        priority={priority}
        className={className}
      />
    );
  }

  return (
    <picture>
      <source srcSet={lockup.dark} media="(prefers-color-scheme: dark)" />
      <Image
        src={lockup.light}
        alt="ToMenu"
        width={lockup.width}
        height={lockup.height}
        priority={priority}
        className={className}
      />
    </picture>
  );
}
