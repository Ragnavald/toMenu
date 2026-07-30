/**
 * Calcula a cor de texto contrastante (clara ou escura) para uma cor RGB.
 * Utiliza o padrão de luminância relativa percebida da W3C / WCAG.
 */
export function getContrastInk(rgbString: string): string {
  const channels = rgbString.trim().split(/\s+/).map(Number);
  if (channels.length < 3) return '255 255 255';

  const [r, g, b] = channels;
  const luminance = (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;

  return luminance > 0.55 ? '17 17 19' : '255 255 255';
}
