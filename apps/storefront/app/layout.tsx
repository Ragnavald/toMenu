import type { Metadata } from 'next';
import './globals.css';

const ROOT_DOMAIN = process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost';

// Sem isto o Next resolve as imagens de og:/twitter: contra localhost:3000, e a
// prévia do link quebra em produção. Em dev o domínio raiz não tem TLS.
const SITE_URL =
  ROOT_DOMAIN === 'localhost'
    ? 'http://localhost:3000'
    : `https://${ROOT_DOMAIN}`;

export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  title: 'ToMenu',
  description: 'Cardápios digitais para o seu estabelecimento.',
  manifest: '/manifest.json',
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="pt-BR" className="h-full">
      <body className="min-h-full">
        {children}
      </body>
    </html>
  );
}
