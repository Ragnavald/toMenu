import type { Metadata } from 'next';
import Link from 'next/link';
import { Clause, Items, LegalPage, P } from '@/components/legal-page';
import { LEGAL, SITE_URL } from '@/lib/legal';

export const metadata: Metadata = {
  title: 'Termos de Uso — ToMenu',
  description:
    'Condições de contratação e uso da plataforma ToMenu por estabelecimentos: planos, período de teste, pagamentos, responsabilidades e cancelamento.',
  alternates: { canonical: `${SITE_URL}/termos` },
};

export default function TermsPage() {
  return (
    <LegalPage
      title="Termos de Uso"
      summary={`Estas condições regem o uso da plataforma ${LEGAL.brand} pelo estabelecimento que cria uma loja. Ao criar a conta, o contratante declara que leu e aceita o que está escrito aqui.`}
    >
      <Clause title="1. Quem são as partes">
        <P>
          A plataforma é operada por {LEGAL.legalName}, inscrita no CNPJ{' '}
          {LEGAL.cnpj}, com sede em {LEGAL.address} (a &ldquo;
          {LEGAL.brand}&rdquo;).
        </P>
        <P>
          &ldquo;Estabelecimento&rdquo; é a pessoa física ou jurídica que cria
          uma loja na plataforma. &ldquo;Cliente final&rdquo; é o consumidor que
          acessa o cardápio do estabelecimento e eventualmente faz um pedido.
        </P>
        <P>
          O contrato de consumo do pedido é celebrado entre o cliente final e o
          estabelecimento. A {LEGAL.brand} fornece a tecnologia que intermedia
          essa relação e não é parte dela — o que tem efeito direto sobre as
          responsabilidades descritas na cláusula 7.
        </P>
      </Clause>

      <Clause title="2. O que a plataforma faz">
        <P>
          A {LEGAL.brand} disponibiliza, em modelo de assinatura, um sistema
          para que o estabelecimento publique seu cardápio digital em endereço
          próprio e, conforme o plano contratado, receba pedidos pelo site.
        </P>
        <Items>
          <li>
            <strong>Cardápio digital:</strong> publicação do catálogo em
            subdomínio próprio, com identidade visual configurável.
          </li>
          <li>
            <strong>Pedidos:</strong> recebimento de pedidos com entrega,
            retirada ou consumo no local, quando incluído no plano.
          </li>
          <li>
            <strong>Painel:</strong> gestão de produtos, pedidos, horários e
            relatórios.
          </li>
        </Items>
        <P>
          As funcionalidades de cada plano são as descritas na página de planos
          no momento da contratação. A {LEGAL.brand} pode evoluir, alterar ou
          descontinuar funcionalidades; quando a mudança reduzir de forma
          relevante o que foi contratado, o estabelecimento será avisado com ao
          menos 30 dias de antecedência e poderá rescindir sem ônus.
        </P>
      </Clause>

      <Clause title="3. Conta, endereço da loja e credenciais">
        <P>
          O cadastro exige dados verdadeiros e atualizados. O estabelecimento é
          responsável por tudo que ocorrer em sua conta e deve guardar a senha
          com cuidado, comunicando imediatamente qualquer uso não autorizado.
        </P>
        <P>
          Cada loja recebe um endereço na forma{' '}
          <code>sualoja.{process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'to-menu.com'}</code>
          . O subdomínio é concedido em licença de uso enquanto durar o
          contrato, não é vendido e não constitui propriedade do
          estabelecimento. Endereços que reproduzam marcas de terceiros, induzam
          a erro ou reproduzam nomes reservados pela plataforma podem ser
          recusados ou revogados.
        </P>
      </Clause>

      <Clause title="4. Teste grátis, planos e pagamento">
        <P>
          Novas lojas começam com 14 dias de teste, sem necessidade de cartão. Ao
          fim do período, o acesso às funcionalidades pagas depende da
          contratação de um plano; os dados já cadastrados permanecem
          disponíveis pelo prazo da cláusula 9.
        </P>
        <Items>
          <li>
            A assinatura é mensal e renovada automaticamente até que o
            estabelecimento a cancele.
          </li>
          <li>
            Os valores são os vigentes na página de planos. Reajustes serão
            comunicados com ao menos 30 dias de antecedência e valem a partir do
            ciclo seguinte.
          </li>
          <li>
            A {LEGAL.brand} não cobra comissão sobre o valor dos pedidos. Taxas
            de meios de pagamento, quando houver, são do respectivo provedor.
          </li>
          <li>
            Falta de pagamento pode levar à suspensão da loja após comunicação
            prévia. A loja suspensa deixa de exibir o cardápio ao público.
          </li>
        </Items>
      </Clause>

      <Clause title="5. Pagamentos dos clientes finais">
        <P>
          Quando o plano incluir pagamento online, o processamento é feito por
          instituição de pagamento parceira, e os valores dos pedidos são
          repassados diretamente ao estabelecimento, conforme as regras e prazos
          do provedor. A {LEGAL.brand} não retém, não custodia e não é
          responsável por esses valores.
        </P>
        <P>
          Estornos, chargebacks e disputas sobre o pedido são resolvidos entre o
          cliente final, o estabelecimento e o provedor de pagamento.
        </P>
      </Clause>

      <Clause title="6. Uso aceitável">
        <P>É vedado ao estabelecimento, entre outras condutas:</P>
        <Items>
          <li>
            publicar conteúdo ilícito, enganoso, que viole direitos de terceiros
            ou que não corresponda ao que efetivamente comercializa;
          </li>
          <li>
            comercializar produtos cuja venda dependa de licença ou autorização
            que não possua, incluindo bebidas alcoólicas a menores;
          </li>
          <li>
            usar a plataforma para enviar comunicação não solicitada aos
            clientes finais ou para finalidade diversa da relação de consumo;
          </li>
          <li>
            tentar acessar áreas, dados ou contas de outros estabelecimentos, ou
            comprometer a segurança e a disponibilidade do serviço;
          </li>
          <li>
            fazer engenharia reversa, revender ou sublicenciar a plataforma sem
            autorização escrita.
          </li>
        </Items>
        <P>
          O descumprimento pode levar à suspensão imediata da loja, sem prejuízo
          das medidas legais cabíveis.
        </P>
      </Clause>

      <Clause title="7. Responsabilidades">
        <P>
          O estabelecimento é o único responsável pelo conteúdo que publica —
          descrições, fotos, preços, informações de alérgenos e composição — e
          pelo cumprimento dos pedidos: preparo, prazo, qualidade, entrega e
          atendimento ao consumidor, inclusive quanto ao Código de Defesa do
          Consumidor e às normas sanitárias aplicáveis.
        </P>
        <P>
          A {LEGAL.brand} responde pelo funcionamento da plataforma, mas não
          garante operação ininterrupta: manutenções, falhas de terceiros
          (provedores de infraestrutura, pagamento ou telecomunicações) e casos
          fortuitos podem afetar a disponibilidade. Salvo dolo ou culpa grave, e
          nos limites da lei, a responsabilidade da {LEGAL.brand} por perdas e
          danos fica limitada ao valor pago pelo estabelecimento nos 12 meses
          anteriores ao evento.
        </P>
      </Clause>

      <Clause title="8. Propriedade intelectual">
        <P>
          O software, a marca e o design da plataforma pertencem à{' '}
          {LEGAL.brand}. O conteúdo publicado pelo estabelecimento continua sendo
          dele, que concede à {LEGAL.brand} licença limitada para hospedar,
          exibir e reproduzir esse conteúdo na medida necessária para prestar o
          serviço.
        </P>
      </Clause>

      <Clause title="9. Cancelamento e exclusão">
        <P>
          O estabelecimento pode cancelar a qualquer momento pelo próprio
          painel, sem multa. O acesso permanece até o fim do ciclo já pago, e não
          há devolução proporcional do período em curso.
        </P>
        <P>
          Após a exclusão, a loja sai do ar imediatamente. Os dados são mantidos
          por até 30 dias para permitir a reativação e, findo esse prazo, são
          eliminados, ressalvadas as hipóteses de guarda obrigatória descritas na{' '}
          <Link className="underline underline-offset-2" href="/privacidade">
            Política de Privacidade
          </Link>
          .
        </P>
        <P>
          A {LEGAL.brand} pode encerrar o contrato mediante aviso de 30 dias, ou
          imediatamente em caso de violação da cláusula 6.
        </P>
      </Clause>

      <Clause title="10. Proteção de dados">
        <P>
          O tratamento de dados pessoais é regido pela{' '}
          <Link className="underline underline-offset-2" href="/privacidade">
            Política de Privacidade
          </Link>
          . Em relação aos dados dos clientes finais, o estabelecimento atua como
          controlador e a {LEGAL.brand} como operadora — divisão de papéis
          detalhada na{' '}
          <Link className="underline underline-offset-2" href="/lgpd">
            página sobre a LGPD
          </Link>
          , que integra estes Termos.
        </P>
      </Clause>

      <Clause title="11. Alterações destes Termos">
        <P>
          Estes Termos podem ser atualizados. Mudanças materiais serão
          comunicadas por e-mail ou pelo painel com ao menos 30 dias de
          antecedência, e o uso continuado após a entrada em vigor caracteriza
          aceite. Cada versão é identificada pela data no topo desta página, e a
          versão aceita por cada estabelecimento fica registrada no momento do
          cadastro.
        </P>
      </Clause>

      <Clause title="12. Foro e lei aplicável">
        <P>
          Aplica-se a legislação brasileira. Fica eleito o foro da{' '}
          {LEGAL.jurisdiction} para dirimir controvérsias, ressalvado, ao
          consumidor, o foro de seu domicílio.
        </P>
        <P>
          Dúvidas sobre estes Termos:{' '}
          <a
            className="underline underline-offset-2"
            href={`mailto:${LEGAL.supportContact}`}
          >
            {LEGAL.supportContact}
          </a>
          .
        </P>
      </Clause>
    </LegalPage>
  );
}
