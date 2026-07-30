import Link from 'next/link';

export default function TenantNotFound() {
  return (
    <main className="mx-auto grid min-h-dvh max-w-md place-items-center px-6 text-center">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">
          Loja não encontrada
        </h1>
        <p className="mt-2 text-muted">
          O endereço acessado não corresponde a nenhuma loja ativa.
        </p>
        <Link href="/" className="mt-6 inline-block text-sm font-medium underline">
          Voltar ao início
        </Link>
      </div>
    </main>
  );
}
