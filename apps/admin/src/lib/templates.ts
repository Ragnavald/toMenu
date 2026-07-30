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
] as const;

export function getSegmentLabel(segmentKey?: string | null): string {
  if (!segmentKey) return 'Estabelecimento';
  const found = SEGMENT_OPTIONS.find((s) => s.value === segmentKey);
  return found ? found.label : segmentKey;
}
