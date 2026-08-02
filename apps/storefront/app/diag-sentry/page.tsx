// TEMPORÁRIO — rota de diagnóstico do Sentry. Removida logo após o teste.
export const dynamic = 'force-dynamic';

export default function Page() {
  throw new Error('diag-sentry: erro proposital para validar a captura');
}
