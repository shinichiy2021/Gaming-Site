import type { Metadata } from 'next';
import { Manrope, Orbitron } from 'next/font/google';
import Providers from '@/components/Providers';
import './globals.css';

const orbitron = Orbitron({
  variable: '--font-display',
  subsets: ['latin'],
  weight: ['400', '700', '900'],
});

const manrope = Manrope({
  variable: '--font-body',
  subsets: ['latin'],
  weight: ['400', '500', '600', '700'],
});

export const metadata: Metadata = {
  title: 'Crypto Portfolio · Gaming Hub',
  description: 'Cryptocurrency portfolio balances',
  robots: { index: false, follow: false },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="ja">
      <body className={`${orbitron.variable} ${manrope.variable} antialiased`}>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
