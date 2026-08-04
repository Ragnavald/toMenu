export type CategoryTemplateItem = {
  id: string;
  nome: string;
  icone: string;
  sort_order: number;
};

export const templateCategorias: Record<string, CategoryTemplateItem[]> = {
  pizzaria: [
    { id: 'pizzas_tradicionais', nome: 'Pizzas Tradicionais', icone: '🍕', sort_order: 1 },
    { id: 'pizzas_especiais', nome: 'Pizzas Especiais', icone: '⭐', sort_order: 2 },
    { id: 'pizzas_doces', nome: 'Pizzas Doces', icone: '🍫', sort_order: 3 },
    { id: 'esfihas_salgadas', nome: 'Esfihas Salgadas', icone: '🥟', sort_order: 4 },
    { id: 'esfihas_doces', nome: 'Esfihas Doces', icone: '🍩', sort_order: 5 },
    { id: 'bebidas', nome: 'Bebidas e Refrigerantes', icone: '🥤', sort_order: 6 },
  ],

  hamburgueria: [
    { id: 'smash_burgers', nome: 'Smash Burgers', icone: '🍔', sort_order: 1 },
    { id: 'artesanais', nome: 'Hambúrgueres Artesanais', icone: '🥩', sort_order: 2 },
    { id: 'combos', nome: 'Combos Promocionais', icone: '🍟', sort_order: 3 },
    { id: 'porcoes', nome: 'Porções e Acompanhamentos', icone: '🧅', sort_order: 4 },
    { id: 'bebidas', nome: 'Bebidas', icone: '🥤', sort_order: 5 },
    { id: 'milkshakes', nome: 'Milkshakes e Sobremesas', icone: '🍨', sort_order: 6 },
  ],

  bar_pub: [
    { id: 'chopp_cervejas', nome: 'Chopp e Cervejas', icone: '🍺', sort_order: 1 },
    { id: 'drinks_classicos', nome: 'Drinks Clássicos', icone: '🍸', sort_order: 2 },
    { id: 'drinks_autorais', nome: 'Drinks da Casa', icone: '🍹', sort_order: 3 },
    { id: 'porcoes_quentes', nome: 'Porções Quentes', icone: '🍤', sort_order: 4 },
    { id: 'petiscos_frios', nome: 'Petiscos Frios', icone: '🧀', sort_order: 5 },
    { id: 'espetinhos', nome: 'Espetinhos', icone: '🍢', sort_order: 6 },
  ],

  restaurante_comum: [
    { id: 'pratos_executivos', nome: 'Pratos Executivos', icone: '🍛', sort_order: 1 },
    { id: 'pratos_feitos', nome: 'Pratos Feitos (PF)', icone: '🍽️', sort_order: 2 },
    { id: 'carnes_grelhados', nome: 'Carnes e Grelhados', icone: '🥩', sort_order: 3 },
    { id: 'saladas', nome: 'Saladas e Saudáveis', icone: '🥗', sort_order: 4 },
    { id: 'bebidas', nome: 'Bebidas', icone: '🥤', sort_order: 5 },
    { id: 'sobremesas', nome: 'Sobremesas', icone: '🍮', sort_order: 6 },
  ],

  japones: [
    { id: 'combinados', nome: 'Combinados', icone: '🍱', sort_order: 1 },
    { id: 'entradas', nome: 'Entradas (Sunomono, Shimeji)', icone: '🥢', sort_order: 2 },
    { id: 'temakis', nome: 'Temakis', icone: '🍙', sort_order: 3 },
    { id: 'sushis_sashimis', nome: 'Sushis e Sashimis', icone: '🍣', sort_order: 4 },
    { id: 'pratos_quentes', nome: 'Pratos Quentes (Yakisoba, Hot Roll)', icone: '🍜', sort_order: 5 },
    { id: 'bebidas', nome: 'Bebidas', icone: '🥤', sort_order: 6 },
  ],

  cafeteria: [
    { id: 'cafes_quentes', nome: 'Cafés e Espressos', icone: '☕', sort_order: 1 },
    { id: 'cafes_gelados', nome: 'Cafés Gelados e Frappés', icone: '🧊', sort_order: 2 },
    { id: 'salgados', nome: 'Salgados e Lanches', icone: '🥐', sort_order: 3 },
    { id: 'bolos_tortas', nome: 'Bolos e Fatias', icone: '🍰', sort_order: 4 },
    { id: 'doces', nome: 'Doces e Biscoitos', icone: '🍪', sort_order: 5 },
  ],

  acaiteria: [
    { id: 'copos_montados', nome: 'Copos Montados', icone: '🥤', sort_order: 1 },
    { id: 'barcas', nome: 'Barcas e Roletas', icone: '🛶', sort_order: 2 },
    { id: 'adicionais', nome: 'Adicionais e Cremes', icone: '🍓', sort_order: 3 },
    { id: 'sucos', nome: 'Sucos Naturais', icone: '🧃', sort_order: 4 },
  ],

  sorveteria: [
    { id: 'tamanhos_copo', nome: 'Tamanhos de Copo', icone: '🍦', sort_order: 1 },
    { id: 'acompanhamentos_gratis', nome: 'Acompanhamentos Grátis', icone: '🍒', sort_order: 2 },
    { id: 'extras_pagos', nome: 'Extras Pagos', icone: '🍫', sort_order: 3 },
    { id: 'bebidas', nome: 'Bebidas', icone: '🥤', sort_order: 4 },
  ],

  adega: [
    { id: 'cervejas_packs', nome: 'Cervejas (Packs)', icone: '📦', sort_order: 1 },
    { id: 'cervejas_unidade', nome: 'Cervejas (Unidade)', icone: '🍺', sort_order: 2 },
    { id: 'destilados', nome: 'Destilados', icone: '🥃', sort_order: 3 },
    { id: 'gelo_carvao', nome: 'Gelo e Carvão', icone: '🧊', sort_order: 4 },
    { id: 'tabacaria', nome: 'Tabacaria', icone: '🚬', sort_order: 5 },
  ],

  padaria: [
    { id: 'paes', nome: 'Pães', icone: '🥖', sort_order: 1 },
    { id: 'frios_laticinios', nome: 'Frios e Laticínios', icone: '🧀', sort_order: 2 },
    { id: 'lanches_na_hora', nome: 'Lanches Feitos na Hora', icone: '🥪', sort_order: 3 },
    { id: 'kits_festa', nome: 'Kits Festa', icone: '🎉', sort_order: 4 },
  ],

  espetaria: [
    { id: 'espetos_tradicionais', nome: 'Espetos Tradicionais', icone: '🍢', sort_order: 1 },
    { id: 'espetos_premium', nome: 'Espetos Premium', icone: '⭐', sort_order: 2 },
    { id: 'porcoes_extras', nome: 'Porções Extras', icone: '🍟', sort_order: 3 },
    { id: 'combos', nome: 'Combos', icone: '🔥', sort_order: 4 },
  ],

  saudavel: [
    { id: 'bowls_proteicos', nome: 'Bowls Proteicos', icone: '🥣', sort_order: 1 },
    { id: 'saladas', nome: 'Saladas Personalizadas', icone: '🥗', sort_order: 2 },
    { id: 'sucos_detox', nome: 'Sucos Detox', icone: '🥬', sort_order: 3 },
  ],

  servico_quarto: [
    { id: 'cafe_da_manha', nome: 'Café da Manhã', icone: '🥐', sort_order: 1 },
    { id: 'lanches_rapidos', nome: 'Lanches Rápidos', icone: '🥪', sort_order: 2 },
    { id: 'frigobar', nome: 'Itens de Frigobar', icone: '🧊', sort_order: 3 },
    { id: 'conveniencia', nome: 'Conveniência', icone: '🛎️', sort_order: 4 },
  ],

  food_truck: [
    { id: 'especialidade', nome: 'Especialidade da Casa', icone: '🌟', sort_order: 1 },
    { id: 'vegetarianos', nome: 'Opções Vegetarianas', icone: '🥬', sort_order: 2 },
    { id: 'sobremesas', nome: 'Sobremesas', icone: '🍩', sort_order: 3 },
  ],
};

export const categoriasMap = new Map(Object.entries(templateCategorias));

export const SEGMENT_OPTIONS = [
  { value: 'pizzaria', label: '🍕 Pizzaria' },
  { value: 'hamburgueria', label: '🍔 Hambúrgueria' },
  { value: 'bar_pub', label: '🍺 Bar / Pub' },
  { value: 'restaurante_comum', label: '🍽️ Restaurante (PF / Executivo)' },
  { value: 'japones', label: '🍱 Comida Japonesa / Sushi' },
  { value: 'cafeteria', label: '☕ Cafeteria / Doceria' },
  { value: 'acaiteria', label: '🍧 Açaiteria' },
  { value: 'sorveteria', label: '🍦 Sorveteria' },
  { value: 'adega', label: '🍾 Adega / Distribuidora' },
  { value: 'padaria', label: '🥖 Padaria / Panificadora' },
  { value: 'espetaria', label: '🍢 Espetaria / Churrasco' },
  { value: 'saudavel', label: '🥗 Comida Saudável / Vegana' },
  { value: 'servico_quarto', label: '🛎️ Serviço de Quarto (Hotéis)' },
  { value: 'food_truck', label: '🚚 Food Truck' },
] as const;

/**
 * Modelo de cardápio pronto — categorias, produtos e grupos de opções.
 *
 * Diferente de `templateCategorias`, que só sugere seções vazias: aqui o
 * lojista importa itens que já saem vendáveis, com preço a revisar.
 */
export type MenuTemplate = {
  /** Rótulo do botão de importação. */
  label: string;
  categorias: MenuTemplateCategory[];
  produtos: MenuTemplateProduct[];
  grupos: MenuTemplateGroup[];
};

export type MenuTemplateCategory = {
  /** Chave interna do template; os produtos apontam para ela, não para o id do banco. */
  ref: string;
  nome: string;
  /** Categoria-insumo: abastece grupo composto e não vira seção do cardápio. */
  is_option_only?: boolean;
};

export type MenuTemplateProduct = {
  ref: string;
  categoria_ref: string;
  nome: string;
  descricao?: string;
  /**
   * Preço sugerido, em centavos. É ponto de partida para o lojista editar —
   * nenhum valor aqui pretende acertar o mercado de cada praça.
   */
  price_cents: number;
  /** Grupos de opções vinculados a este produto. */
  grupos_ref?: string[];
};

export type MenuTemplateGroup = {
  ref: string;
  nome: string;
  min_select: number;
  max_select: number;
  is_required?: boolean;
  /** 'category' lê as opções dos produtos da categoria apontada. */
  source: 'list' | 'category';
  source_categoria_ref?: string;
  pricing_rule: 'sum' | 'highest' | 'average';
};

/**
 * Pizzaria — tamanhos, sabores e as variantes de dois sabores.
 *
 * Os itens "2 Sabores" não trazem sabor escrito dentro do grupo. Eles apontam
 * para a categoria "Sabores de Pizza", que é `is_option_only` e nasce com dois
 * sabores genéricos de exemplo. O lojista renomeia esses dois e cadastra o
 * resto ali uma vez só — e todos os tamanhos passam a oferecê-los, sem repetir
 * foto e descrição em cada grupo. É o motivo de os grupos compostos existirem;
 * ver a migration `add_composed_groups_to_catalog`.
 *
 * Duas escolhas aqui não são estéticas e quebram o preço se invertidas:
 *
 * 1. O FORMATO CUSTA ZERO. Quem carrega o preço é o sabor. Um formato de 6900
 *    com sabor de 7200 por cima cobraria 14100 — quase duas pizzas. O produto
 *    é o tamanho; o valor entra pela opção escolhida.
 *
 * 2. UM GRUPO DE SABORES POR TAMANHO, e não um grupo compartilhado. O preço do
 *    sabor por formato mora na pivot do grupo, então Média e Grande dividindo
 *    um grupo dividiriam também o preço, e a Grande sairia pelo valor da Média.
 *    Mesma razão documentada no seeder de demonstração.
 *
 * `pricing_rule: 'highest'` porque somar dois sabores inteiros cobraria duas
 * pizzas. Quem cobra pela média troca para 'average' na tela do grupo.
 */
export const menuTemplates: Record<string, MenuTemplate> = {
  pizzaria: {
    label: 'Pizzas (tamanhos, sabores e meio a meio)',
    categorias: [
      { ref: 'pizzas', nome: '🍕 Pizzas Salgadas' },
      { ref: 'pizzas_doces', nome: '🍫 Pizzas Doces' },
      { ref: 'sabores', nome: '🧀 Sabores de Pizza', is_option_only: true },
      { ref: 'sabores_doces', nome: '🍫 Sabores Doces', is_option_only: true },
    ],
    grupos: [
      {
        ref: 'media_1',
        nome: 'Escolha o sabor (Média)',
        min_select: 1,
        max_select: 1,
        is_required: true,
        source: 'category',
        source_categoria_ref: 'sabores',
        pricing_rule: 'highest',
      },
      {
        ref: 'media_2',
        nome: 'Escolha 2 sabores (Média)',
        min_select: 2,
        max_select: 2,
        is_required: true,
        source: 'category',
        source_categoria_ref: 'sabores',
        pricing_rule: 'highest',
      },
      {
        ref: 'grande_1',
        nome: 'Escolha o sabor (Grande)',
        min_select: 1,
        max_select: 1,
        is_required: true,
        source: 'category',
        source_categoria_ref: 'sabores',
        pricing_rule: 'highest',
      },
      {
        ref: 'grande_2',
        nome: 'Escolha 2 sabores (Grande)',
        min_select: 2,
        max_select: 2,
        is_required: true,
        source: 'category',
        source_categoria_ref: 'sabores',
        pricing_rule: 'highest',
      },
      {
        ref: 'doce_1',
        nome: 'Escolha o sabor doce',
        min_select: 1,
        max_select: 1,
        is_required: true,
        source: 'category',
        source_categoria_ref: 'sabores_doces',
        pricing_rule: 'highest',
      },
      {
        ref: 'doce_2',
        nome: 'Escolha 2 sabores doces',
        min_select: 2,
        max_select: 2,
        is_required: true,
        source: 'category',
        source_categoria_ref: 'sabores_doces',
        pricing_rule: 'highest',
      },
    ],
    produtos: [
      // Os formatos custam zero de propósito — ver o item 1 do comentário
      // acima. O preço que o cliente paga é o do sabor escolhido.
      {
        ref: 'media',
        categoria_ref: 'pizzas',
        nome: 'Pizza Média',
        descricao: '8 fatias — um sabor.',
        price_cents: 0,
        grupos_ref: ['media_1'],
      },
      {
        ref: 'grande',
        categoria_ref: 'pizzas',
        nome: 'Pizza Grande',
        descricao: '12 fatias — um sabor.',
        price_cents: 0,
        grupos_ref: ['grande_1'],
      },
      {
        ref: 'doce',
        categoria_ref: 'pizzas_doces',
        nome: 'Pizza Doce',
        descricao: '8 fatias — um sabor doce.',
        price_cents: 0,
        grupos_ref: ['doce_1'],
      },
      {
        ref: 'media_meio',
        categoria_ref: 'pizzas',
        nome: 'Pizza Média 2 Sabores',
        descricao: '8 fatias — metade de cada sabor.',
        price_cents: 0,
        grupos_ref: ['media_2'],
      },
      {
        ref: 'grande_meio',
        categoria_ref: 'pizzas',
        nome: 'Pizza Grande 2 Sabores',
        descricao: '12 fatias — metade de cada sabor.',
        price_cents: 0,
        grupos_ref: ['grande_2'],
      },
      {
        ref: 'doce_meio',
        categoria_ref: 'pizzas_doces',
        nome: 'Pizza Doce 2 Sabores',
        descricao: '8 fatias — metade de cada sabor doce.',
        price_cents: 0,
        grupos_ref: ['doce_2'],
      },
      // Os sabores genéricos: dois por categoria-insumo porque o grupo de meio
      // a meio exige duas escolhas — com um só, o produto ficaria invendável.
      // São produtos comuns; renomear e ajustar o preço já basta.
      //
      // O preço aqui é o da pizza inteira daquele sabor. Os tamanhos partem do
      // mesmo valor: quem cobra mais pela Grande ajusta o preço do sabor
      // dentro do grupo dela, que é onde o valor por formato mora.
      {
        ref: 'sabor_1',
        categoria_ref: 'sabores',
        nome: 'Sabor 1',
        descricao: 'Renomeie, descreva os ingredientes e ajuste o preço.',
        price_cents: 4900,
      },
      {
        ref: 'sabor_2',
        categoria_ref: 'sabores',
        nome: 'Sabor 2',
        descricao: 'Renomeie, descreva os ingredientes e ajuste o preço.',
        price_cents: 4900,
      },
      {
        ref: 'sabor_doce_1',
        categoria_ref: 'sabores_doces',
        nome: 'Sabor Doce 1',
        descricao: 'Renomeie, descreva os ingredientes e ajuste o preço.',
        price_cents: 4500,
      },
      {
        ref: 'sabor_doce_2',
        categoria_ref: 'sabores_doces',
        nome: 'Sabor Doce 2',
        descricao: 'Renomeie, descreva os ingredientes e ajuste o preço.',
        price_cents: 4500,
      },
    ],
  },
};

export function getSegmentLabel(segmentKey?: string | null): string {
  if (!segmentKey) return 'Estabelecimento';
  const found = SEGMENT_OPTIONS.find((s) => s.value === segmentKey);
  return found ? found.label : segmentKey;
}
