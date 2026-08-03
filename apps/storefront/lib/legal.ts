/**
 * Identidade e datas dos documentos legais.
 *
 * Um só lugar porque as três páginas (Termos, Privacidade e LGPD) citam os
 * mesmos dados da empresa e a mesma data de vigência: repetir isso em três
 * arquivos garante que uma edição futura atualize dois deles e esqueça o
 * terceiro — e um documento legal que se contradiz é pior que nenhum.
 *
 * ⚠️ Os valores entre colchetes são marcadores e precisam ser substituídos
 * pelos dados reais antes de publicar. Enquanto estiverem assim, as páginas
 * exibem um aviso visível no topo (ver `PENDING_IDENTITY`) — o alerta some
 * sozinho quando os marcadores saírem.
 */
export const LEGAL = {
  /** Nome fantasia, usado no texto corrido. */
  brand: 'ToMenu',

  /** Razão social completa, como registrada na Receita Federal. */
  legalName: '[RAZÃO SOCIAL LTDA]',

  cnpj: '[00.000.000/0001-00]',

  address: '[Rua, número, complemento — Cidade/UF, CEP]',

  /** Canal do encarregado de dados (LGPD, art. 41). */
  privacyContact: 'privacidade@to-menu.com',

  /** Canal de suporte comercial e contratual. */
  supportContact: 'suporte@to-menu.com',

  /*
   * Versão vigente dos documentos.
   *
   * Precisa ser idêntica ao `legal.terms_version` da API (config/legal.php):
   * é aquele valor que fica gravado no aceite de cada lojista, e este que o
   * lojista vê na tela ao aceitar. Se divergirem, o registro deixa de provar
   * qual texto estava no ar no momento do cadastro.
   */
  version: '2026-08-03',

  /** A mesma data de `version`, escrita para leitura humana. */
  effectiveDate: '3 de agosto de 2026',

  /** Foro eleito para as controvérsias do contrato. */
  jurisdiction: '[Comarca de Cidade/UF]',
} as const;

/**
 * Ainda há marcadores por preencher?
 *
 * Serve para a página avisar em vez de publicar em silêncio um contrato que
 * cita "[RAZÃO SOCIAL LTDA]" como parte. Detectar pelo colchete, e não por uma
 * flag manual, faz o aviso desaparecer no mesmo commit que preenche os dados —
 * sem depender de alguém lembrar de desligá-lo.
 */
export const PENDING_IDENTITY: boolean = [
  LEGAL.legalName,
  LEGAL.cnpj,
  LEGAL.address,
  LEGAL.jurisdiction,
].some((value) => value.includes('['));

/** Base absoluta da plataforma; em dev o domínio raiz não tem TLS. */
export const SITE_URL =
  (process.env.NEXT_PUBLIC_ROOT_DOMAIN ?? 'localhost') === 'localhost'
    ? 'http://localhost:3000'
    : `https://${process.env.NEXT_PUBLIC_ROOT_DOMAIN}`;
