import { NextResponse } from 'next/server';
import type { NextRequest } from 'next/server';
import { fetchMenuResult } from '@/lib/api';

export const dynamic = 'force-dynamic';

export async function GET(request: NextRequest) {
  const { searchParams } = new URL(request.url);
  let tenant = searchParams.get('tenant') || searchParams.get('t');

  if (!tenant) {
    const host = (request.headers.get('host') ?? '').split(':')[0].toLowerCase();
    const rootDomain = (process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost').toLowerCase();

    if (host.endsWith(`.${rootDomain}`) && host !== `www.${rootDomain}`) {
      tenant = host.slice(0, -(rootDomain.length + 1));
    }
  }

  if (tenant) {
    const result = await fetchMenuResult(tenant);
    if (result.status === 'ok') {
      const { name, logoUrl } = result.menu.tenant;
      const theme = result.menu.theme;

      // Converter cor da marca "234 88 12" para formato css "rgb(234, 88, 12)"
      const themeColor = theme?.brand
        ? `rgb(${theme.brand.split(' ').join(', ')})`
        : '#ea580c';

      let logoType = 'image/png';
      if (logoUrl) {
        if (logoUrl.endsWith('.jpg') || logoUrl.endsWith('.jpeg')) {
          logoType = 'image/jpeg';
        } else if (logoUrl.endsWith('.webp')) {
          logoType = 'image/webp';
        } else if (logoUrl.endsWith('.svg')) {
          logoType = 'image/svg+xml';
        }
      }

      const icons = logoUrl
        ? [
            {
              src: logoUrl,
              sizes: '192x192 512x512',
              type: logoType,
            },
          ]
        : [
            {
              src: '/img/icon-192x192.png',
              sizes: '192x192',
              type: 'image/png',
            },
            {
              src: '/img/icon-512x512.png',
              sizes: '512x512',
              type: 'image/png',
            },
          ];

      return NextResponse.json(
        {
          name: name,
          short_name: name,
          start_url: '/',
          display: 'standalone',
          background_color: '#ffffff',
          theme_color: themeColor,
          icons: icons,
        },
        {
          headers: {
            'Content-Type': 'application/manifest+json; charset=utf-8',
            'Cache-Control': 'public, max-age=0, must-revalidate',
          },
        }
      );
    }
  }

  // Fallback para ToMenu padrão
  return NextResponse.json(
    {
      name: 'ToMenu',
      short_name: 'ToMenu',
      start_url: '/',
      display: 'standalone',
      background_color: '#ffffff',
      theme_color: '#ea580c',
      icons: [
        {
          src: '/img/icon-192x192.png',
          sizes: '192x192',
          type: 'image/png',
        },
        {
          src: '/img/icon-512x512.png',
          sizes: '512x512',
          type: 'image/png',
        },
      ],
    },
    {
      headers: {
        'Content-Type': 'application/manifest+json; charset=utf-8',
        'Cache-Control': 'public, max-age=0, must-revalidate',
      },
    }
  );
}
