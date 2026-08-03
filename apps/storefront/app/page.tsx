import type { Metadata } from 'next';
import Image from 'next/image';
import Link from 'next/link';
import { platformSchema } from '@/lib/structured-data';
import { BrandLogo } from '@/components/brand-logo';
import { JsonLd } from '@/components/json-ld';
import { SignupForm } from '@/components/signup-form';

const SITE_URL =
  (process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost') === 'localhost'
    ? 'http://localhost:3000'
    : `https://${process.env.NEXT_PUBLIC_ROOT_DOMAIN}`;

const TITLE = 'ToMenu — Cardápio digital e pedidos online para restaurantes';
const DESCRIPTION =
  'Cardápio digital por QR Code em minutos, a partir de R$ 29/mês. No plano Pro, receba pedidos com entrega, painel em tempo real e relatórios. Sem comissão por pedido.';

export const metadata: Metadata = {
  title: TITLE,
  description: DESCRIPTION,
  // A landing responde em to-menu.com e www.to-menu.com; a canônica elege uma.
  alternates: { canonical: SITE_URL },
  keywords: [
    'cardápio digital',
    'cardápio online',
    'QR code cardápio',
    'cardápio digital para restaurante',
    'sistema para restaurante',
    'gestão de pedidos',
    'delivery próprio',
    'pedidos online sem comissão',
  ],
  openGraph: {
    title: TITLE,
    description: DESCRIPTION,
    url: SITE_URL,
    siteName: 'ToMenu',
    locale: 'pt_BR',
    type: 'website',
  },
  twitter: {
    card: 'summary_large_image',
    title: TITLE,
    description: DESCRIPTION,
  },
};

/**
 * Os dois planos, na ordem em que aparecem.
 *
 * A diferença entre eles é uma só e precisa ser óbvia: um mostra o cardápio, o
 * outro recebe o pedido. Por isso as listas não são "features de cada um" —
 * são a mesma lista de capacidades, respondida com sim ou não nos dois. Duas
 * listas independentes obrigariam o leitor a cruzar itens para descobrir o que
 * falta no plano barato, que é exatamente a dúvida que ele veio tirar.
 */
const CAPABILITIES = [
  { label: 'Cardápio digital com fotos, categorias e preços', menu: true, pro: true },
  { label: 'Endereço próprio (sualoja.tomenu.app)', menu: true, pro: true },
  { label: 'Cores, tipografia e layout da sua marca', menu: true, pro: true },
  { label: 'Atualização do cardápio na hora, por você', menu: true, pro: true },
  { label: 'Carrinho e pedido pelo site', menu: false, pro: true },
  { label: 'Aviso sonoro de pedido novo no painel', menu: false, pro: true },
  { label: 'Entrega: taxa, raio, mínimo e tempo', menu: false, pro: true },
  { label: 'Painel de pedidos em tempo real', menu: false, pro: true },
  { label: 'Relatórios de vendas', menu: false, pro: true },
];

/**
 * `demo` aponta para a loja de exemplo daquele plano, semeada pelo DemoSeeder.
 *
 * Cada cartão leva ao exemplo do próprio plano: a lista riscada diz o que falta
 * no plano barato, mas quem está decidindo quer ver o que recebe. A cafeteria
 * roda no plano vitrine de verdade, então a ausência de carrinho na página dela
 * é a mesma regra que vale para o assinante — não uma maquete.
 */
const PLANS = [
  {
    slug: 'cardapio',
    name: 'Cardápio digital',
    price: 'R$ 29',
    tagline: 'Sua carta na internet, sempre atualizada.',
    forWho:
      'Para quem atende no salão ou no balcão e só quer trocar o cardápio impresso por um link e um QR code.',
    highlight: false,
    demo: { href: '/to-menu-cardapio', label: 'Ver loja somente com cardápio digital' },
  },
  {
    slug: 'pro',
    name: 'Pro',
    price: 'R$ 89',
    tagline: 'O cardápio e a operação de delivery inteira.',
    forWho:
      'Para quem vende para viagem e quer receber o pedido pronto, com endereço e itens, sem depender de conversa no WhatsApp.',
    highlight: true,
    demo: { href: '/to-menu-loja', label: 'Ver loja completa de exemplo' },
  },
];

/**
 * O argumento de valor, em benefício e não em funcionalidade.
 *
 * FEATURES abaixo responde "o que o sistema faz"; esta seção responde "o que
 * muda no meu restaurante", que é a pergunta de quem ainda não decidiu trocar
 * o cardápio impresso. As duas listas não se sobrepõem de propósito: repetir
 * as mesmas capacidades em outras palavras faria a página parecer mais longa
 * sem responder nada novo.
 */
const BENEFITS = [
  {
    title: 'Experiência do cliente impecável',
    body: 'Navegação rápida, interface intuitiva e zero necessidade de baixar aplicativos — o seu cliente foca apenas em escolher o que vai pedir.',
  },
  {
    title: 'Assinatura transparente e sem surpresas',
    body: 'Cobrança segura e automatizada, sem comissão por pedido. Você foca na comida, a tecnologia cuida da estabilidade da sua conta.',
  },
  {
    title: 'Operação livre de gargalos',
    body: 'Elimine erros de anotação de pedidos e libere a sua equipe de salão para focar no relacionamento e na satisfação do cliente.',
  },
  {
    title: 'Crescimento baseado em dados',
    body: 'Com o plano Pro você para de adivinhar: saiba quais pratos são mais rentáveis, os horários de pico e o desempenho financeiro do dia.',
  },
];

/**
 * `proOnly` marca o que só existe no Pro.
 *
 * Sem essa marca a grade prometia entrega e painel de pedidos para quem estava
 * lendo sobre o plano de R$ 29 — e a frustração apareceria depois da compra,
 * que é o pior lugar possível para descobrir o limite do plano.
 */
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
    title: 'A cara do seu restaurante',
    body: 'Escolha cores, tipografia e layout. Sem template genérico que parece de outra marca.',
  },
  {
    title: 'A cozinha sabe na hora',
    body: 'Cada pedido aparece no painel com aviso sonoro, endereço e itens escolhidos — sem recarregar a página.',
    proOnly: true,
  },
  {
    title: 'Entrega do seu jeito',
    body: 'Defina taxa, pedido mínimo, raio de entrega, frete grátis acima de um valor e tempo estimado.',
    proOnly: true,
  },
];

export default function LandingPage() {
  return (
    <div className="min-h-dvh bg-[rgb(var(--surface))] text-[rgb(var(--ink))]">
      {/* Identifica a ToMenu como organização e produto, com os dois planos e
          seus preços. É o que permite ao buscador exibir a marca com logo e
          responder "quanto custa" sem abrir a página. */}
      <JsonLd data={platformSchema(SITE_URL)} />

      <header className="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
        {/* A landing é sempre clara — `auto` traria a arte clara no aparelho
            em modo escuro, deixando "ToMenu" branco sobre fundo branco. */}
        <BrandLogo priority scheme="light" className="h-8 w-auto sm:h-9" />

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
            {/* O teste grátis é o que derruba a objeção de quem chega: some
                o risco de assinar antes de ver funcionando. Estava como chip
                miúdo acima do título e passava batido — agora tem peso de
                texto, não de etiqueta. */}
            <p
              className="mb-4 inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-semibold"
              style={{
                background: 'rgb(var(--brand-soft))',
                color: 'rgb(var(--brand))',
              }}
            >
              Teste 14 dias grátis · sem cartão de crédito
            </p>

            <h1 className="text-4xl font-semibold leading-[1.1] tracking-tight sm:text-5xl">
              Transforme seu atendimento com um cardápio digital inteligente
            </h1>

            <p className="mt-5 text-lg leading-relaxed text-muted">
              Da mesa ao delivery, a tecnologia que o seu restaurante precisa
              para vender mais e operar sem complicações.
            </p>

            <p className="mt-4 leading-relaxed text-muted">
              Modernize a experiência dos seus clientes e otimize a sua operação
              em minutos. Ofereça um cardápio atrativo e de carregamento rápido
              via QR Code, ou assuma o controle total da operação com gestão de
              pedidos em tempo real, entrega configurável e relatórios
              financeiros detalhados.
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
                Começar agora
              </Link>

              <Link
                href="#planos"
                className="rounded-xl border border-[var(--hairline)] px-5 py-3 text-sm font-medium transition-colors hover:bg-[var(--hairline)]"
              >
                Comparar planos
              </Link>
            </div>

            {/* Junto do botão, não só no topo: é aqui que a pessoa decide
                clicar, e é aqui que a dúvida "vou ter que pagar agora?"
                aparece. */}
            <p className="mt-3 text-sm text-muted">
              Grátis por 14 dias. Sem cartão de crédito, sem fidelidade —
              cancele quando quiser.
            </p>

            {/* Uma loja de cada plano. Os dois links juntos são o argumento:
                a diferença entre os planos fica visível em dois cliques, sem
                depender de o visitante acreditar na tabela de preços. */}
            <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
              <Link
                href="/to-menu-loja"
                className="font-medium underline decoration-[var(--hairline)] underline-offset-4 transition-colors hover:decoration-[rgb(var(--ink))]"
              >
                Ver loja completa de exemplo
              </Link>

              <Link
                href="/to-menu-cardapio"
                className="font-medium text-muted underline decoration-[var(--hairline)] underline-offset-4 transition-colors hover:text-[rgb(var(--ink))] hover:decoration-[rgb(var(--ink))]"
              >
                Ver loja somente com cardápio digital
              </Link>
            </div>
          </div>
        </section>

        <section className="border-y border-[var(--hairline)] bg-[var(--elevated)]">
          <div className="mx-auto grid max-w-5xl gap-x-10 gap-y-8 px-6 py-14 sm:grid-cols-2 lg:grid-cols-3">
            {FEATURES.map((feature) => (
              <div key={feature.title}>
                <h2 className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                  {feature.title}
                  {feature.proOnly && (
                    <span
                      className="rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                      style={{
                        background: 'rgb(var(--brand-soft))',
                        color: 'rgb(var(--brand))',
                      }}
                    >
                      Pro
                    </span>
                  )}
                </h2>
                <p className="mt-1.5 text-sm leading-relaxed text-muted">
                  {feature.body}
                </p>
              </div>
            ))}
          </div>
        </section>

        {/* Dois planos, uma diferença. A seção existe para deixar essa
            diferença explícita: mostrar o cardápio ou receber o pedido. */}
        <section id="planos" className="mx-auto max-w-5xl px-6 py-16">
          <div className="mx-auto max-w-xl text-center">
            <h2 className="text-2xl font-semibold tracking-tight">
              Dois planos. A diferença é uma só.
            </h2>
            <p className="mt-2 text-muted">
              O <strong className="font-semibold text-[rgb(var(--ink))]">Cardápio digital</strong>{' '}
              mostra o seu menu. O{' '}
              <strong className="font-semibold text-[rgb(var(--ink))]">Pro</strong> também recebe o
              pedido, com entrega e acompanhamento. Sem comissão por venda nos
              dois.
            </p>
          </div>

          <div className="mt-10 grid gap-5 sm:grid-cols-2">
            {PLANS.map((plan) => (
              <div
                key={plan.slug}
                className="flex flex-col overflow-hidden border"
                style={{
                  borderRadius: 'var(--radius)',
                  borderColor: plan.highlight
                    ? 'rgb(var(--brand) / 0.45)'
                    : 'var(--hairline)',
                  // O Pro ganha um traço a mais para não empatar visualmente:
                  // ele é o plano que serve à maioria de quem chega aqui.
                  boxShadow: plan.highlight
                    ? '0 0 0 1px rgb(var(--brand) / 0.25)'
                    : undefined,
                }}
              >
                <div
                  className="px-6 py-7"
                  style={{
                    background: plan.highlight
                      ? 'rgb(var(--brand-soft) / 0.55)'
                      : 'transparent',
                  }}
                >
                  <div className="flex items-center gap-2">
                    <p
                      className="text-sm font-semibold"
                      style={{ color: 'rgb(var(--brand))' }}
                    >
                      {plan.name}
                    </p>
                    {plan.highlight && (
                      <span
                        className="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                        style={{
                          background: 'rgb(var(--brand))',
                          color: 'rgb(var(--brand-ink))',
                        }}
                      >
                        Mais escolhido
                      </span>
                    )}
                  </div>

                  <p className="mt-3 flex items-baseline gap-1.5">
                    <span className="text-4xl font-semibold tracking-tight">
                      {plan.price}
                    </span>
                    <span className="text-sm text-muted">/mês</span>
                  </p>

                  <p className="mt-2 text-sm font-medium">{plan.tagline}</p>
                  <p className="mt-1.5 text-sm leading-relaxed text-muted">
                    {plan.forWho}
                  </p>
                </div>

                <ul className="grid flex-1 gap-2.5 border-t border-[var(--hairline)] px-6 py-6">
                  {CAPABILITIES.map((item) => {
                    const included =
                      plan.slug === 'pro' ? item.pro : item.menu;

                    return (
                      <li
                        key={item.label}
                        className="flex items-start gap-2.5 text-sm"
                      >
                        {/* O item ausente continua na lista, riscado: o que o
                            plano barato NÃO faz é a informação que evita a
                            contratação errada e o pedido de reembolso. */}
                        <span
                          aria-hidden
                          className="mt-0.5 grid size-4 shrink-0 place-items-center rounded-full text-[10px]"
                          style={
                            included
                              ? {
                                  background: 'rgb(var(--brand))',
                                  color: 'rgb(var(--brand-ink))',
                                }
                              : {
                                  background: 'var(--hairline)',
                                  color: 'var(--ink-subtle)',
                                }
                          }
                        >
                          {included ? '✓' : '—'}
                        </span>
                        <span
                          className={
                            included ? 'text-muted' : 'text-subtle line-through'
                          }
                        >
                          {item.label}
                        </span>
                        <span className="sr-only">
                          {included ? 'incluído' : 'não incluído'}
                        </span>
                      </li>
                    );
                  })}
                </ul>

                <div className="px-6 pb-6">
                  <Link
                    // `?plano=` de verdade (query string, não fragmento): a
                    // página é estática e o formulário lê o parâmetro para já
                    // nascer com o plano escolhido. O #comecar rola até ele.
                    href={`/?plano=${plan.slug}#comecar`}
                    className="block w-full px-4 py-3 text-center text-sm font-semibold transition-opacity hover:opacity-90"
                    style={{
                      borderRadius: 'calc(var(--radius) * 0.6)',
                      background: plan.highlight
                        ? 'rgb(var(--brand))'
                        : 'transparent',
                      color: plan.highlight
                        ? 'rgb(var(--brand-ink))'
                        : 'rgb(var(--brand))',
                      border: plan.highlight
                        ? '1px solid transparent'
                        : '1px solid rgb(var(--brand) / 0.4)',
                    }}
                  >
                    Testar {plan.name} grátis
                  </Link>

                  {/* No próprio cartão de preço: é onde o número R$ 89 está
                      olhando para a pessoa, e onde ela precisa saber que não
                      paga hoje. */}
                  <p className="mt-2 text-center text-xs text-muted">
                    14 dias grátis · sem cartão
                  </p>

                  <Link
                    href={plan.demo.href}
                    className="mt-3 block text-center text-sm font-medium text-muted underline decoration-[var(--hairline)] underline-offset-4 transition-colors hover:text-[rgb(var(--ink))] hover:decoration-[rgb(var(--ink))]"
                  >
                    {plan.demo.label}
                  </Link>
                </div>
              </div>
            ))}
          </div>

          <p className="mt-6 text-center text-xs text-muted">
            14 dias grátis nos dois planos, sem cartão de crédito. Você troca de
            plano quando quiser.
          </p>
        </section>

        {/* Vem depois dos planos: quem chegou até aqui já viu o preço, e a
            última dúvida deixa de ser "o que faz" para virar "vale a pena". */}
        <section className="border-t border-[var(--hairline)]">
          <div className="mx-auto max-w-5xl px-6 py-16">
            <h2 className="max-w-2xl text-2xl font-semibold tracking-tight">
              Por que levar a nossa tecnologia para o seu restaurante?
            </h2>

            <div className="mt-10 grid gap-x-10 gap-y-8 sm:grid-cols-2">
              {BENEFITS.map((benefit) => (
                <div key={benefit.title}>
                  <h3 className="text-sm font-semibold">{benefit.title}</h3>
                  <p className="mt-1.5 text-sm leading-relaxed text-muted">
                    {benefit.body}
                  </p>
                </div>
              ))}
            </div>
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
                Leva menos de um minuto. Escolha o plano e configure o cardápio
                depois — dá para trocar de plano a qualquer momento.
              </p>
            </div>

            <SignupForm />
          </div>
        </section>
      </main>

      <footer className="mx-auto max-w-5xl px-6 py-8 text-xs text-subtle">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <span className="flex items-center gap-2">
            <Image
              src="/tomenu-icon.png"
              alt=""
              aria-hidden
              width={512}
              height={512}
              className="size-5 w-auto"
            />
            © {new Date().getFullYear()} ToMenu
          </span>
          <span>Feito para quem vive de servir bem.</span>
        </div>

        {/* Os documentos legais precisam ser alcançáveis de qualquer página, e
            o rodapé é onde o visitante os procura. */}
        <nav
          aria-label="Documentos legais"
          className="mt-5 flex flex-wrap gap-x-5 gap-y-2 border-t border-[var(--hairline)] pt-5"
        >
          <Link className="transition-colors hover:text-[rgb(var(--brand))]" href="/termos">
            Termos de Uso
          </Link>
          <Link className="transition-colors hover:text-[rgb(var(--brand))]" href="/privacidade">
            Política de Privacidade
          </Link>
          <Link className="transition-colors hover:text-[rgb(var(--brand))]" href="/lgpd">
            LGPD
          </Link>
        </nav>
      </footer>
    </div>
  );
}
