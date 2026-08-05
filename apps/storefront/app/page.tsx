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

/*
 * Atendimento da ToMenu para montar o cardápio no lugar do lojista.
 *
 * O número vai com DDI porque o wa.me exige, e a mensagem já vem escrita: quem
 * chega numa conversa em branco costuma não saber o que dizer e desiste.
 */
const SETUP_WHATSAPP_URL = `https://wa.me/5511910401320?text=${encodeURIComponent(
  'Olá! Quero que a equipe ToMenu monte o meu cardápio.',
)}`;

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
 * Os três pontos da seção de identidade digital.
 *
 * São deliberadamente os únicos itens da landing escritos por oposição — o que
 * a loja própria tem e o perfil num marketplace não tem. Em BENEFITS esse tom
 * ficaria fora de lugar, porque lá o leitor ainda está entendendo o produto;
 * aqui ele já está olhando para a tela pronta e comparando com o que conhece.
 */
const IDENTITY_POINTS = [
  {
    title: 'Endereço que é seu.',
    body: 'sualoja.tomenu.app, ou o domínio que você já tem. O cliente salva o link e volta direto, sem passar por vitrine de concorrente.',
  },
  {
    title: 'Sem comissão por pedido.',
    body: 'Você paga a mensalidade e pronto. O que o cliente gasta é seu — não uma fatia do que sobra depois da taxa da plataforma.',
  },
  {
    title: 'Abre em qualquer celular.',
    body: 'Um link ou um QR code na mesa. Sem instalar aplicativo, sem criar conta, sem barreira entre a fome e o pedido.',
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

/** Glifo do WhatsApp: uma dependência inteira por um ícone não se paga. */
function IconWhatsApp() {
  return (
    <svg
      width="18"
      height="18"
      viewBox="0 0 24 24"
      fill="currentColor"
      aria-hidden
      className="shrink-0"
    >
      <path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.87 9.87 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.23 8.23 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.17c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.25-.64.8-.79.97-.14.16-.29.19-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.51.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.17 0-.43.06-.66.31-.23.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.11-.23-.17-.48-.29Z" />
    </svg>
  );
}

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

        {/* A identidade digital da loja, mostrada em vez de descrita.

            As seções seguintes são todas texto: capacidades, planos, vantagens.
            Nenhuma responde "com o que a minha loja vai parecer", que é o que
            trava quem nunca viu o produto. Um aparelho com a loja aberta na
            tela responde isso antes do primeiro parágrafo — e por isso vem
            logo depois do herói, ainda na altura em que a pessoa decide se
            continua rolando. */}
        <section className="relative overflow-hidden border-y border-[var(--hairline)]">
          {/* O halo é o que separa o aparelho do branco da página: sem fundo
              na imagem, o celular recortado flutuaria sobre nada e a sombra
              própria dele não teria onde cair. */}
          <div
            aria-hidden
            className="pointer-events-none absolute left-1/2 top-1/2 -z-10 h-[42rem] w-[42rem] -translate-x-1/2 -translate-y-1/2 rounded-full blur-3xl lg:left-[72%]"
            style={{
              background:
                'radial-gradient(circle, rgb(var(--brand) / 0.16) 0%, rgb(var(--brand) / 0.05) 45%, transparent 70%)',
            }}
          />

          <div className="mx-auto grid max-w-5xl items-center gap-12 px-6 py-20 lg:grid-cols-[1fr_auto] lg:gap-16">
            <div className="max-w-xl">
              <p
                className="text-sm font-semibold uppercase tracking-wide"
                style={{ color: 'rgb(var(--brand))' }}
              >
                Identidade digital
              </p>

              <h2 className="mt-3 text-3xl font-semibold leading-[1.15] tracking-tight sm:text-4xl">
                A sua loja no bolso do cliente — com a cara da sua marca
              </h2>

              <p className="mt-5 text-lg leading-relaxed text-muted">
                Não é um perfil dentro do aplicativo de outra empresa, dividindo
                a tela com o concorrente ao lado. É um endereço seu, com as suas
                cores, a sua tipografia e as suas fotos — aberto direto no
                navegador, sem download e sem cadastro para quem vai pedir.
              </p>

              <ul className="mt-8 grid gap-4">
                {IDENTITY_POINTS.map((point) => (
                  <li key={point.title} className="flex gap-3">
                    <span
                      aria-hidden
                      className="mt-1 grid size-5 shrink-0 place-items-center rounded-full text-[11px]"
                      style={{
                        background: 'rgb(var(--brand-soft))',
                        color: 'rgb(var(--brand))',
                      }}
                    >
                      ✓
                    </span>
                    <span className="text-sm leading-relaxed">
                      <strong className="font-semibold">{point.title}</strong>{' '}
                      <span className="text-muted">{point.body}</span>
                    </span>
                  </li>
                ))}
              </ul>

              <Link
                href="/to-menu-loja"
                className="mt-8 inline-flex items-center gap-2 text-sm font-semibold underline decoration-[var(--hairline)] underline-offset-4 transition-colors hover:decoration-[rgb(var(--ink))]"
              >
                Abrir uma loja de exemplo no seu celular
              </Link>
            </div>

            {/* `priority` fica de fora: a imagem é pesada e está abaixo da
                dobra, então disputar banda com o herói atrasaria o LCP da
                página em troca de nada. */}
            <div className="relative mx-auto w-[16rem] sm:w-[19rem] lg:w-[21rem]">
              <Image
                src="/flating-device.png"
                alt="Celular dobrável exibindo uma loja ToMenu, com o cardápio, as categorias e os preços na identidade visual do restaurante."
                width={475}
                height={1024}
                sizes="(min-width: 1024px) 21rem, (min-width: 640px) 19rem, 16rem"
                className="animate-float-device h-auto w-full drop-shadow-[0_35px_60px_rgb(23_23_23_/_0.28)]"
              />
            </div>
          </div>
        </section>

        <section className="border-b border-[var(--hairline)] bg-[var(--elevated)]">
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

        {/* Última objeção antes do cadastro: quem já se convenceu do produto
            trava em "vou ter que montar tudo isso sozinho". A resposta vale
            mais aqui, encostada no formulário, do que entre as vantagens. */}
        <section className="border-t border-[var(--hairline)]">
          <div className="mx-auto max-w-3xl px-6 py-16 text-center">
            <h2 className="text-2xl font-semibold tracking-tight">
              Preencher Cardápio ou Loja Digital pela equipe ToMenu
            </h2>

            <p className="mx-auto mt-3 max-w-xl leading-relaxed text-muted">
              Nós montamos o cardápio para você: basta nos enviar por WhatsApp
              as opções, fotos e etc. Se não tiver foto, sem problemas — nós
              adicionamos.
            </p>

            <a
              href={SETUP_WHATSAPP_URL}
              target="_blank"
              rel="noreferrer"
              className="mt-7 inline-flex items-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold transition-opacity hover:opacity-90"
              style={{
                background: 'rgb(var(--brand))',
                color: 'rgb(var(--brand-ink))',
              }}
            >
              <IconWhatsApp />
              Falar com a equipe no WhatsApp
            </a>
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
