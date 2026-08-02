<?php

return [
    /*
     * Versão vigente dos Termos de Uso e da Política de Privacidade.
     *
     * É esta string que fica gravada em `tenants.terms_version` a cada
     * cadastro, e é ela que responde "o lojista aceitou qual texto?" quando a
     * pergunta aparecer — normalmente meses depois, num pedido de titular ou
     * numa disputa.
     *
     * Data e não semver: o documento não tem "correção de bug", tem edição. A
     * data em que a redação entrou em vigor é a informação que interessa a
     * quem consulta, e é a mesma que aparece no cabeçalho das páginas.
     *
     * **Só mude junto com o texto.** Alterar aqui sem alterar os documentos
     * (ou o contrário) quebra a correspondência entre o que foi aceito e o que
     * está registrado, que é a única coisa que estas colunas existem para
     * provar. Quando houver mudança material, a versão nova vale para os
     * cadastros seguintes; os tenants antigos continuam com a versão que
     * aceitaram, e é assim que se sabe quem precisa reaceitar.
     *
     * O storefront tem a mesma constante em `lib/legal.ts` — as páginas
     * públicas precisam exibir a data sem consultar a API. Os dois valores
     * precisam andar juntos.
     */
    'terms_version' => env('LEGAL_TERMS_VERSION', '2026-08-02'),

    /*
     * Canal do encarregado pelo tratamento de dados (LGPD, art. 41).
     *
     * A lei exige que o contato do encarregado seja divulgado publicamente; as
     * páginas legais o exibem e os pedidos de titular chegam por aqui.
     */
    'privacy_contact' => env('LEGAL_PRIVACY_CONTACT', 'privacidade@to-menu.com'),
];
