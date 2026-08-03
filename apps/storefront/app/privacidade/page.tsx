import type { Metadata } from 'next';
import Link from 'next/link';
import { Clause, Items, LegalPage, P } from '@/components/legal-page';
import { LEGAL, SITE_URL } from '@/lib/legal';

export const metadata: Metadata = {
  title: 'Política de Privacidade — ToMenu',
  description:
    'Como o ToMenu coleta, usa, compartilha e protege dados pessoais de lojistas e de clientes finais, conforme a Lei Geral de Proteção de Dados.',
  alternates: { canonical: `${SITE_URL}/privacidade` },
};

export default function PrivacyPage() {
  return (
    <LegalPage
      title="Política de Privacidade"
      summary={`Esta política explica quais dados pessoais o ${LEGAL.brand} trata, por quê, com quem compartilha e por quanto tempo guarda — tanto os de quem contrata a plataforma quanto os de quem faz um pedido em uma loja hospedada nela.`}
    >
      <Clause title="1. Quem trata os seus dados">
        <P>
          {LEGAL.legalName}, CNPJ {LEGAL.cnpj}, com sede em {LEGAL.address}.
        </P>
        <P>
          Encarregado pelo tratamento de dados pessoais (DPO):{' '}
          <a
            className="underline underline-offset-2"
            href={`mailto:${LEGAL.privacyContact}`}
          >
            {LEGAL.privacyContact}
          </a>
          .
        </P>
      </Clause>

      <Clause title="2. Dois papéis diferentes — e por que isso importa para você">
        <P>
          A plataforma hospeda lojas de terceiros, e o {LEGAL.brand} ocupa
          posições distintas conforme a origem do dado. A distinção define a
          quem você deve dirigir um pedido sobre seus dados:
        </P>
        <Items>
          <li>
            <strong>Dados do lojista — somos controladores.</strong> Quem cria
            uma loja contrata a plataforma diretamente, e somos nós que decidimos
            como esses dados são tratados. Pedidos vão para{' '}
            {LEGAL.privacyContact}.
          </li>
          <li>
            <strong>Dados do cliente final — somos operadores.</strong> Quando
            você faz um pedido no cardápio de um restaurante, quem determina a
            finalidade é o restaurante: ele é o controlador, e nós tratamos os
            dados por conta e ordem dele. O pedido deve ser dirigido primeiro ao
            restaurante; podemos encaminhá-lo, mas quem decide é ele.
          </li>
        </Items>
        <P>
          A{' '}
          <Link className="underline underline-offset-2" href="/lgpd">
            página sobre a LGPD
          </Link>{' '}
          detalha essa divisão e as obrigações de cada parte.
        </P>
      </Clause>

      <Clause title="3. Dados que tratamos">
        <P>
          <strong>Do lojista, no cadastro e no uso do painel:</strong>
        </P>
        <Items>
          <li>nome, e-mail e senha (armazenada com hash, nunca em texto);</li>
          <li>
            nome, endereço e dados de contato do estabelecimento, e o conteúdo
            que ele publica;
          </li>
          <li>
            registro do aceite destes documentos: data, versão aceita e endereço
            IP;
          </li>
          <li>
            dados de cobrança e identificadores da conta no provedor de
            pagamento — não recebemos nem armazenamos o número do cartão;
          </li>
          <li>
            registros de acesso ao sistema (IP, data e hora), mantidos por
            obrigação legal.
          </li>
        </Items>

        <P>
          <strong>Do cliente final, ao fazer um pedido:</strong>
        </P>
        <Items>
          <li>nome e telefone;</li>
          <li>e-mail, quando informado;</li>
          <li>
            CPF, apenas quando solicitado para emissão de documento fiscal;
          </li>
          <li>
            endereço de entrega e suas coordenadas aproximadas, usadas para
            calcular a taxa e a área de atendimento;
          </li>
          <li>
            itens do pedido, forma de pagamento, status e observações
            escritas por você.
          </li>
        </Items>
        <P>
          Não tratamos dados pessoais sensíveis. Restrições alimentares
          eventualmente escritas no campo de observações são conteúdo livre
          dirigido ao restaurante — recomendamos informar ali apenas o
          necessário ao preparo.
        </P>
        <P>
          A plataforma não se destina a menores de 18 anos. Não coletamos
          intencionalmente dados de crianças e adolescentes.
        </P>
      </Clause>

      <Clause title="4. Para que usamos e com qual base legal">
        <Items>
          <li>
            <strong>Executar o contrato</strong> (art. 7º, V) — criar e manter a
            loja, publicar o cardápio, processar e acompanhar pedidos, dar
            suporte e cobrar a assinatura.
          </li>
          <li>
            <strong>Cumprir obrigação legal</strong> (art. 7º, II) — guarda de
            registros de acesso pelo Marco Civil da Internet, documentos fiscais
            e contábeis.
          </li>
          <li>
            <strong>Legítimo interesse</strong> (art. 7º, IX) — segurança,
            prevenção a fraude e abuso, e métricas agregadas para melhorar o
            produto. Nunca usamos legítimo interesse para publicidade dirigida a
            clientes finais.
          </li>
          <li>
            <strong>Consentimento</strong> (art. 7º, I) — comunicações de
            marketing ao lojista, que pode retirá-lo a qualquer momento sem
            afetar o serviço contratado.
          </li>
        </Items>
        <P>
          Não vendemos dados pessoais, não os cedemos a corretores de dados e
          não fazemos perfilamento para publicidade de terceiros.
        </P>
      </Clause>

      <Clause title="5. Com quem compartilhamos">
        <P>
          Apenas com quem é necessário para o serviço funcionar, sempre sob
          contrato e limitados à finalidade contratada:
        </P>
        <Items>
          <li>
            <strong>O restaurante escolhido</strong> — recebe os dados do pedido
            que você fez nele, e somente dele. Uma loja nunca enxerga os dados de
            outra.
          </li>
          <li>
            <strong>Stripe</strong> — processamento de pagamentos online.
          </li>
          <li>
            <strong>Cloudflare</strong> — entrega de conteúdo, proteção contra
            ataques e verificação anti-robô nos formulários.
          </li>
          <li>
            <strong>OpenStreetMap/Nominatim</strong> — conversão do endereço de
            entrega em coordenadas.
          </li>
          <li>
            <strong>Provedor de infraestrutura e de e-mail</strong> —
            hospedagem, backup e envio de mensagens transacionais.
          </li>
          <li>
            <strong>Autoridades</strong> — mediante requisição legal ou ordem
            judicial.
          </li>
        </Items>
        <P>
          Alguns desses provedores processam dados fora do Brasil. Nesses casos,
          a transferência internacional observa o art. 33 da LGPD, apoiada em
          cláusulas contratuais de proteção equivalentes às exigidas pela lei
          brasileira.
        </P>
      </Clause>

      <Clause title="6. Por quanto tempo guardamos">
        <Items>
          <li>
            <strong>Conta do lojista:</strong> enquanto durar o contrato. Após a
            exclusão, os dados ficam por até 30 dias para permitir reativação e
            então são eliminados.
          </li>
          <li>
            <strong>Pedidos e dados do cliente final:</strong> pelo prazo
            definido pelo restaurante controlador; na ausência de definição,
            enquanto a loja estiver ativa e pelos prazos legais de guarda fiscal.
          </li>
          <li>
            <strong>Registros de acesso:</strong> 6 meses, conforme o Marco
            Civil da Internet.
          </li>
          <li>
            <strong>Documentos fiscais e contábeis:</strong> 5 anos.
          </li>
          <li>
            <strong>Registro de aceite dos Termos:</strong> enquanto durar o
            contrato e pelo prazo prescricional aplicável, por ser a prova do
            consentimento exigida pelo art. 8º, §1º da LGPD.
          </li>
        </Items>
        <P>
          Terminado o prazo, os dados são eliminados ou anonimizados de forma
          irreversível. Dados anonimizados podem ser mantidos para estatística,
          pois deixam de ser dados pessoais.
        </P>
      </Clause>

      <Clause title="7. Segurança">
        <P>
          Adotamos medidas técnicas e administrativas compatíveis com o risco,
          entre elas: tráfego cifrado por TLS, senhas armazenadas com hash,
          isolamento de dados entre lojas aplicado no próprio banco de dados,
          controle de acesso por perfil, registro de ações administrativas e
          backups periódicos.
        </P>
        <P>
          Nenhum sistema é imune. Em caso de incidente com risco relevante aos
          titulares, comunicaremos os afetados e a ANPD nos prazos da lei.
        </P>
      </Clause>

      <Clause title="8. Cookies">
        <P>
          Usamos apenas o essencial: sessão e autenticação, preferências como o
          carrinho e a loja visitada, e cookies do Cloudflare para segurança e
          verificação anti-robô.
        </P>
        <P>
          Parte dessas informações fica no armazenamento local do seu navegador
          — o carrinho, o histórico de pedidos e os dados de contato e entrega
          que você digita no checkout (nome, telefone e endereço), guardados
          para não precisar redigitá-los no próximo pedido. O CPF não é
          guardado: você o informa a cada pedido em que quiser incluí-lo. Esses
          dados ficam só no seu aparelho, e podem ser apagados pelo botão “Não é
          você?” no checkout ou limpando os dados do site. Não usamos cookies de
          publicidade nem rastreamento entre sites.
        </P>
      </Clause>

      <Clause title="9. Seus direitos">
        <P>
          A LGPD garante confirmação de tratamento, acesso, correção,
          anonimização, portabilidade, eliminação, informação sobre
          compartilhamento e revogação de consentimento. Como exercê-los, e a
          quem se dirigir em cada caso, está detalhado na{' '}
          <Link className="underline underline-offset-2" href="/lgpd">
            página sobre a LGPD
          </Link>
          .
        </P>
      </Clause>

      <Clause title="10. Alterações desta política">
        <P>
          Esta política pode ser atualizada. A versão vigente é sempre a
          publicada nesta página, identificada pela data no topo. Mudanças
          materiais serão comunicadas ao lojista por e-mail ou pelo painel com
          ao menos 30 dias de antecedência.
        </P>
      </Clause>
    </LegalPage>
  );
}
