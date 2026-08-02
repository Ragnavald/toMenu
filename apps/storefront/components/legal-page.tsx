import Link from 'next/link';
import { BrandLogo } from '@/components/brand-logo';
import { LEGAL, PENDING_IDENTITY } from '@/lib/legal';

/**
 * Moldura comum aos três documentos legais.
 *
 * Os textos mudam; o entorno não — cabeçalho com a marca, data de vigência,
 * navegação entre os três e rodapé. Concentrar isso aqui evita que uma edição
 * na Privacidade deixe os Termos com outro cabeçalho.
 *
 * A leitura é o objetivo da página, então a coluna é estreita (`max-w-2xl`,
 * ~70 caracteres) e o corpo usa `leading-relaxed`: documento legal já é denso
 * o bastante sem uma linha de 140 caracteres para ajudar a perder o lugar.
 */
export function LegalPage({
  title,
  summary,
  children,
}: {
  title: string;
  summary: string;
  children: React.ReactNode;
}) {
  return (
    <div className="min-h-full">
      <header className="border-b border-[var(--hairline)]">
        <div className="mx-auto flex max-w-2xl items-center justify-between px-6 py-5">
          <Link href="/" aria-label="ToMenu — início">
            <BrandLogo variant="wordmark" className="h-5 w-auto" />
          </Link>

          <Link
            href="/#comecar"
            className="text-xs font-medium text-muted transition-colors hover:text-[rgb(var(--brand))]"
          >
            Criar minha loja
          </Link>
        </div>
      </header>

      <main className="mx-auto max-w-2xl px-6 py-12">
        <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
          {title}
        </h1>

        <p className="mt-3 text-sm leading-relaxed text-muted">{summary}</p>

        <p className="mt-4 text-xs text-subtle">
          Versão {LEGAL.version} — em vigor desde {LEGAL.effectiveDate}.
        </p>

        {/* O aviso só existe enquanto os marcadores não forem preenchidos.
            Fica no topo, e não no rodapé, porque quem publicar o site sem
            trocar os dados precisa esbarrar nele antes de ler o contrato. */}
        {PENDING_IDENTITY && (
          <p
            role="alert"
            className="mt-6 border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-xs leading-relaxed text-amber-700 dark:text-amber-400"
            style={{ borderRadius: 'calc(var(--radius) * 0.5)' }}
          >
            <strong className="font-semibold">Documento em preparação.</strong>{' '}
            Os dados de identificação da empresa ainda estão marcados como{' '}
            <code>[assim]</code> e precisam ser preenchidos em{' '}
            <code>lib/legal.ts</code> antes da publicação. Recomenda-se revisão
            jurídica do texto final.
          </p>
        )}

        <LegalNav />

        {/* `space-y` em vez de margem em cada elemento: o ritmo vertical do
            documento é do documento, não de cada parágrafo que alguém
            adicionar depois. */}
        <div className="mt-10 space-y-7 text-sm leading-relaxed">{children}</div>

        <footer className="mt-14 border-t border-[var(--hairline)] pt-6 text-xs leading-relaxed text-subtle">
          <p>
            {LEGAL.legalName} — CNPJ {LEGAL.cnpj}
            <br />
            {LEGAL.address}
          </p>
          <p className="mt-3">
            Dúvidas sobre este documento:{' '}
            <a
              className="underline underline-offset-2 hover:text-[rgb(var(--brand))]"
              href={`mailto:${LEGAL.privacyContact}`}
            >
              {LEGAL.privacyContact}
            </a>
          </p>
        </footer>
      </main>
    </div>
  );
}

const DOCUMENTS = [
  { href: '/termos', label: 'Termos de Uso' },
  { href: '/privacidade', label: 'Política de Privacidade' },
  { href: '/lgpd', label: 'LGPD' },
] as const;

/**
 * Navegação entre os três documentos.
 *
 * Eles se citam o tempo todo ("conforme a Política de Privacidade"), e sem
 * isto a única forma de ir de um ao outro é voltar à landing e procurar o
 * rodapé.
 */
function LegalNav() {
  return (
    <nav className="mt-7 flex flex-wrap gap-2" aria-label="Documentos legais">
      {DOCUMENTS.map((doc) => (
        <Link
          key={doc.href}
          href={doc.href}
          className="border px-3 py-1.5 text-xs font-medium text-muted transition-colors hover:border-[rgb(var(--brand))] hover:text-[rgb(var(--brand))]"
          style={{
            borderRadius: 'calc(var(--radius) * 0.4)',
            borderColor: 'var(--hairline)',
          }}
        >
          {doc.label}
        </Link>
      ))}
    </nav>
  );
}

/** Seção numerada do documento. */
export function Clause({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section className="space-y-2.5">
      <h2 className="text-base font-semibold tracking-tight">{title}</h2>
      {children}
    </section>
  );
}

/** Lista de itens dentro de uma cláusula. */
export function Items({ children }: { children: React.ReactNode }) {
  return (
    <ul className="ml-4 list-disc space-y-1.5 text-muted marker:text-[var(--ink-subtle)]">
      {children}
    </ul>
  );
}

/** Parágrafo corrido do documento. */
export function P({ children }: { children: React.ReactNode }) {
  return <p className="text-muted">{children}</p>;
}
