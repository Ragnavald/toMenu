import type { Metadata } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: 'ToMenu',
  description: 'Cardápios digitais para o seu estabelecimento.',
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="pt-BR" className="h-full">
      <body className="min-h-full">{children}</body>
    </html>
  );
}
