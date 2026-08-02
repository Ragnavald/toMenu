import type { Metadata } from 'next';
import Link from 'next/link';
import { Clause, Items, LegalPage, P } from '@/components/legal-page';
import { LEGAL, SITE_URL } from '@/lib/legal';

export const metadata: Metadata = {
  title: 'LGPD — ToMenu',
  description:
    'Como o ToMenu cumpre a Lei Geral de Proteção de Dados: papéis de controlador e operador, direitos do titular e como exercê-los, e obrigações do lojista.',
  alternates: { canonical: `${SITE_URL}/lgpd` },
};

export default function LgpdPage() {
  return (
    <LegalPage
      title="LGPD — Lei Geral de Proteção de Dados"
      summary={`A Lei nº 13.709/2018 dá a você direitos sobre os seus dados pessoais. Esta página explica, em termos práticos, como o ${LEGAL.brand} cumpre a lei, o que cada parte responde e como exercer cada direito.`}
    >
      <Clause title="Quem responde pelo quê">
        <P>
          A plataforma hospeda lojas de restaurantes independentes. Isso cria
          duas relações distintas, e saber em qual você está determina para quem
          enviar o seu pedido:
        </P>
        <Items>
          <li>
            <strong>Você é lojista (criou uma loja).</strong> O {LEGAL.brand} é{' '}
            <strong>controlador</strong> dos seus dados de cadastro, cobrança e
            uso do painel. Fale direto conosco.
          </li>
          <li>
            <strong>Você é cliente (pediu em um restaurante).</strong> O
            restaurante é o <strong>controlador</strong>: foi ele quem decidiu
            coletar seus dados para entregar o pedido. O {LEGAL.brand} é{' '}
            <strong>operador</strong> — armazena e processa por ordem dele.
            Procure primeiro o restaurante.
          </li>
        </Items>
        <P>
          Como operadores, não usamos os dados dos clientes finais para
          finalidade própria: não os revendemos, não os agregamos entre lojas e
          não os usamos para publicidade. Cada loja acessa somente os próprios
          dados, e esse isolamento é aplicado na camada do banco de dados, não
          apenas na aplicação.
        </P>
      </Clause>

      <Clause title="Seus direitos como titular (art. 18)">
        <Items>
          <li>
            <strong>Confirmação e acesso</strong> — saber se tratamos dados seus
            e obter cópia deles.
          </li>
          <li>
            <strong>Correção</strong> — atualizar dados incompletos, inexatos ou
            desatualizados.
          </li>
          <li>
            <strong>Anonimização, bloqueio ou eliminação</strong> — de dados
            desnecessários, excessivos ou tratados em desconformidade com a lei.
          </li>
          <li>
            <strong>Portabilidade</strong> — receber seus dados em formato
            estruturado e de uso comum.
          </li>
          <li>
            <strong>Eliminação dos dados tratados com consentimento</strong> —
            ressalvadas as hipóteses de guarda obrigatória do art. 16.
          </li>
          <li>
            <strong>Informação sobre compartilhamento</strong> — com quais
            entidades públicas e privadas compartilhamos seus dados.
          </li>
          <li>
            <strong>Informação sobre a possibilidade de não consentir</strong> —
            e quais são as consequências disso.
          </li>
          <li>
            <strong>Revogação do consentimento</strong> — a qualquer momento,
            sem afetar a legalidade do tratamento anterior.
          </li>
          <li>
            <strong>Revisão de decisões automatizadas</strong> — não tomamos
            decisões exclusivamente automatizadas que afetem seus interesses.
          </li>
        </Items>
      </Clause>

      <Clause title="Como exercer">
        <P>
          <strong>Se você é lojista:</strong> parte dos direitos já está no
          painel — o cadastro e os dados da loja são editáveis a qualquer
          momento, e a exclusão da conta está em Configurações. Para os demais,
          escreva para{' '}
          <a
            className="underline underline-offset-2"
            href={`mailto:${LEGAL.privacyContact}`}
          >
            {LEGAL.privacyContact}
          </a>
          .
        </P>
        <P>
          <strong>Se você é cliente de um restaurante:</strong> dirija o pedido
          ao restaurante em que você pediu — ele é o controlador e é quem decide.
          Se não conseguir contato, escreva para nós: encaminharemos ao
          controlador e, quando ele determinar, executaremos a exclusão ou
          correção nos nossos sistemas.
        </P>
        <P>
          Respondemos em até 15 dias. Pode ser necessário confirmar sua
          identidade antes de atender — é o que impede que um terceiro obtenha
          ou apague os seus dados se passando por você.
        </P>
        <P>
          Se a resposta não for satisfatória, você pode reclamar à Autoridade
          Nacional de Proteção de Dados (ANPD).
        </P>
      </Clause>

      <Clause title="Obrigações do lojista como controlador">
        <P>
          Ao usar a plataforma para receber pedidos, o estabelecimento assume as
          responsabilidades de controlador sobre os dados dos seus clientes:
        </P>
        <Items>
          <li>
            usar os dados apenas para atender ao pedido e para a relação de
            consumo dele decorrente;
          </li>
          <li>
            não exportar a base de clientes para enviar mensagens não
            solicitadas nem repassá-la a terceiros;
          </li>
          <li>
            atender aos pedidos dos titulares que chegarem diretamente a ele,
            nos prazos da lei;
          </li>
          <li>
            comunicar-nos imediatamente qualquer incidente de segurança de que
            tome conhecimento;
          </li>
          <li>
            manter a confidencialidade das credenciais de acesso ao painel.
          </li>
        </Items>
        <P>
          Esta seção integra os{' '}
          <Link className="underline underline-offset-2" href="/termos">
            Termos de Uso
          </Link>{' '}
          e funciona como o acordo de tratamento de dados entre controlador e
          operador exigido pelo art. 39 da LGPD.
        </P>
      </Clause>

      <Clause title="O que fazemos pela proteção dos dados">
        <Items>
          <li>
            <strong>Isolamento entre lojas</strong> aplicado no banco de dados,
            de modo que uma consulta de uma loja não alcance os dados de outra
            mesmo diante de uma falha na aplicação.
          </li>
          <li>
            <strong>Minimização</strong> — coletamos apenas o necessário para
            entregar o pedido; o CPF, por exemplo, só é pedido quando há emissão
            de documento fiscal.
          </li>
          <li>
            <strong>Cifragem em trânsito</strong> por TLS em todo o tráfego, e
            senhas guardadas com hash.
          </li>
          <li>
            <strong>Controle de acesso por perfil</strong> e registro das ações
            administrativas relevantes.
          </li>
          <li>
            <strong>Eliminação programada</strong> ao fim dos prazos de retenção
            descritos na{' '}
            <Link className="underline underline-offset-2" href="/privacidade">
              Política de Privacidade
            </Link>
            .
          </li>
          <li>
            <strong>Contratos com os operadores</strong> que nos apoiam,
            limitando o uso à finalidade contratada.
          </li>
        </Items>
      </Clause>

      <Clause title="Incidentes de segurança">
        <P>
          Havendo incidente que possa acarretar risco ou dano relevante,
          comunicaremos a ANPD e os titulares afetados em prazo razoável,
          informando a natureza dos dados, os riscos envolvidos e as medidas
          adotadas. Quando o incidente atingir dados de clientes finais,
          notificaremos o restaurante controlador para que ele cumpra o seu
          dever de comunicação.
        </P>
      </Clause>

      <Clause title="Encarregado (DPO)">
        <P>
          Canal oficial para pedidos de titulares e comunicações da ANPD:{' '}
          <a
            className="underline underline-offset-2"
            href={`mailto:${LEGAL.privacyContact}`}
          >
            {LEGAL.privacyContact}
          </a>
          .
        </P>
      </Clause>
    </LegalPage>
  );
}
