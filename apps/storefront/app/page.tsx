import type { Metadata } from 'next';
import Link from 'next/link';
import { SignupForm } from '@/components/signup-form';

export const metadata: Metadata = {
  title: 'ToMenu — Seu cardápio digital com pedidos online',
  description:
    'Monte o site de pedidos do seu restaurante em minutos. Sem comissão por pedido, sem app para o cliente baixar.',
};

const PLAN_PRICE = 'R$ 89';

const FEATURES = [
  {
    title: 'Cardápio que você mesmo edita',
    body: 'Adicione pratos, fotos, categorias e opções como tamanho e adicionais. As mudanças entram no ar na hora.',
  },
  {
    title: 'Endereço próprio da sua loja',
    body: 'sualoja.tomenu.app desde o primeiro dia, ou conecte o domínio que você já tem.',
  },
  {
    title: 'Pedidos direto no WhatsApp',
    body: 'Cada pedido chega no painel e no WhatsApp da cozinha, com endereço e forma de pagamento.',
  },
  {
    title: 'Entrega do seu jeito',
    body: 'Defina taxa, pedido mínimo, raio de entrega, frete grátis acima de um valor e tempo estimado.',
  },
  {
    title: 'Pague na entrega ou online',
    body: 'Dinheiro, cartão na maquininha ou pagamento pelo site via cartão e Pix.',
  },
  {
    title: 'A cara do seu restaurante',
    body: 'Escolha cores, tipografia e layout. Sem template genérico que parece de outra marca.',
  },
];

const INCLUDED = [
  'Pedidos ilimitados, sem comissão por venda',
  'Cardápio com até 500 itens',
  'Subdomínio próprio + domínio personalizado',
  'Painel de pedidos em tempo real',
  'Notificações no WhatsApp',
  'Pagamento online com cartão e Pix',
  'Relatórios de vendas',
  'Suporte por WhatsApp',
];

export default function LandingPage() {
  return (
    <div className="min-h-dvh bg-[rgb(var(--surface))] text-[rgb(var(--ink))]">
      <header className="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
        <span className="flex items-center gap-2 font-semibold tracking-tight">
          <span
            aria-hidden
            className="grid size-7 place-items-center rounded-lg text-sm"
            style={{
              background: 'rgb(var(--brand))',
              color: 'rgb(var(--brand-ink))',
            }}
          >
            T
          </span>
          ToMenu
        </span>

        <Link
          href="#comecar"
          className="rounded-lg px-4 py-2 text-sm font-medium text-muted transition-colors hover:text-[rgb(var(--ink))]"
        >
          Criar minha loja
        </Link>
      </header>

      <main>
        <section className="mx-auto max-w-5xl px-6 pb-16 pt-10 sm:pt-16">
          <div className="max-w-2xl">
            <p
              className="mb-4 inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium"
              style={{
                background: 'rgb(var(--brand-soft))',
                color: 'rgb(var(--brand))',
              }}
            >
              14 dias grátis · sem cartão de crédito
            </p>

            <h1 className="text-4xl font-semibold leading-[1.1] tracking-tight sm:text-5xl">
              O site de pedidos do seu restaurante, pronto hoje.
            </h1>

            <p className="mt-5 text-lg leading-relaxed text-muted">
              Seu cardápio digital com carrinho, entrega e pagamento — sem
              comissão por pedido e sem app para o cliente baixar. Ele abre o
              link, escolhe e pede.
            </p>

            <div className="mt-8 flex flex-wrap items-center gap-3">
              <Link
                href="#comecar"
                className="rounded-xl px-5 py-3 text-sm font-semibold transition-opacity hover:opacity-90"
                style={{
                  background: 'rgb(var(--brand))',
                  color: 'rgb(var(--brand-ink))',
                }}
              >
                Criar minha loja grátis
              </Link>

              <Link
                href="/forno-di-napoli"
                className="rounded-xl border border-[var(--hairline)] px-5 py-3 text-sm font-medium transition-colors hover:bg-[var(--hairline)]"
              >
                Ver uma loja de exemplo
              </Link>
            </div>
          </div>
        </section>

        <section className="border-y border-[var(--hairline)] bg-[var(--elevated)]">
          <div className="mx-auto grid max-w-5xl gap-x-10 gap-y-8 px-6 py-14 sm:grid-cols-2 lg:grid-cols-3">
            {FEATURES.map((feature) => (
              <div key={feature.title}>
                <h2 className="text-sm font-semibold">{feature.title}</h2>
                <p className="mt-1.5 text-sm leading-relaxed text-muted">
                  {feature.body}
                </p>
              </div>
            ))}
          </div>
        </section>

        {/* Plano único: sem tabela comparativa, sem decisão a tomar. */}
        <section className="mx-auto max-w-5xl px-6 py-16">
          <div className="mx-auto max-w-md text-center">
            <h2 className="text-2xl font-semibold tracking-tight">
              Um plano. Tudo incluso.
            </h2>
            <p className="mt-2 text-muted">
              Sem taxa por pedido, sem surpresa no fim do mês.
            </p>
          </div>

          <div
            className="mx-auto mt-9 max-w-md overflow-hidden border"
            style={{
              borderRadius: 'var(--radius)',
              borderColor: 'rgb(var(--brand) / 0.35)',
            }}
          >
            <div
              className="px-6 py-7 text-center"
              style={{ background: 'rgb(var(--brand-soft) / 0.55)' }}
            >
              <p className="text-sm font-medium" style={{ color: 'rgb(var(--brand))' }}>
                Plano ToMenu
              </p>
              <p className="mt-2 flex items-baseline justify-center gap-1.5">
                <span className="text-4xl font-semibold tracking-tight">
                  {PLAN_PRICE}
                </span>
                <span className="text-sm text-muted">/mês</span>
              </p>
              <p className="mt-2 text-xs text-muted">
                Cancele quando quiser. Os primeiros 14 dias são gratuitos.
              </p>
            </div>

            <ul className="grid gap-2.5 px-6 py-6">
              {INCLUDED.map((item) => (
                <li key={item} className="flex items-start gap-2.5 text-sm">
                  <span
                    aria-hidden
                    className="mt-0.5 grid size-4 shrink-0 place-items-center rounded-full text-[10px]"
                    style={{
                      background: 'rgb(var(--brand))',
                      color: 'rgb(var(--brand-ink))',
                    }}
                  >
                    ✓
                  </span>
                  <span className="text-muted">{item}</span>
                </li>
              ))}
            </ul>
          </div>
        </section>

        <section
          id="comecar"
          className="border-t border-[var(--hairline)] bg-[var(--elevated)]"
        >
          <div className="mx-auto max-w-md px-6 py-16">
            <div className="mb-7 text-center">
              <h2 className="text-2xl font-semibold tracking-tight">
                Criar minha loja
              </h2>
              <p className="mt-2 text-sm text-muted">
                Leva menos de um minuto. Você configura o cardápio depois.
              </p>
            </div>

            <SignupForm />
          </div>
        </section>
      </main>

      <footer className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-6 py-8 text-xs text-subtle">
        <span>© {new Date().getFullYear()} ToMenu</span>
        <span>Feito para quem vive de servir bem.</span>
      </footer>
    </div>
  );
}
