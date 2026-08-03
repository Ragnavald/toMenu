<?php

/*
 * Mensagens de validação em português.
 *
 * O Laravel só embarca `en`. Sem este arquivo, `APP_LOCALE=pt_BR` não muda
 * nada: o tradutor cai no fallback e o lojista recebe "The promo price cents
 * field must be less than 1000" ao cadastrar um produto.
 *
 * Cobre as regras que a aplicação realmente usa — não é uma tradução completa
 * do pacote. Uma regra nova sem entrada aqui volta a aparecer em inglês, então
 * ao introduzir uma, acrescente a linha correspondente.
 */

return [
    'accepted' => 'É preciso aceitar :attribute.',
    'after' => ':Attribute deve ser uma data posterior a :date.',
    'array' => ':Attribute deve ser uma lista.',
    'before' => ':Attribute deve ser uma data anterior a :date.',
    'boolean' => ':Attribute deve ser verdadeiro ou falso.',
    'confirmed' => 'A confirmação de :attribute não confere.',
    'date' => ':Attribute deve ser uma data válida.',
    'different' => ':Attribute e :other devem ser diferentes.',
    'digits' => ':Attribute deve ter :digits dígitos.',
    'distinct' => ':Attribute está repetido.',
    'email' => 'Informe um e-mail válido.',
    'exists' => 'A opção escolhida em :attribute não existe.',
    'filled' => ':Attribute não pode ficar vazio.',
    'image' => ':Attribute deve ser uma imagem.',
    'in' => 'A opção escolhida em :attribute não é válida.',
    'integer' => ':Attribute deve ser um número inteiro.',
    'mimes' => ':Attribute deve ser um arquivo do tipo: :values.',
    'numeric' => ':Attribute deve ser um número.',
    'prohibited' => ':Attribute não é permitido.',
    'regex' => 'O formato de :attribute não é válido.',
    'required' => 'Informe :attribute.',
    'required_if' => 'Informe :attribute quando :other for :value.',
    'required_with' => 'Informe :attribute quando :values estiver preenchido.',
    'same' => ':Attribute e :other devem ser iguais.',
    'unique' => 'Este :attribute já está em uso.',
    'uploaded' => 'Falha ao enviar :attribute.',
    'url' => ':Attribute deve ser um endereço válido.',

    /*
     * Regras que mudam de texto conforme o tipo do valor. O Laravel escolhe o
     * sub-item pelo que recebeu, então os quatro precisam existir.
     */
    'between' => [
        'array' => ':Attribute deve ter entre :min e :max itens.',
        'file' => ':Attribute deve ter entre :min e :max kilobytes.',
        'numeric' => ':Attribute deve estar entre :min e :max.',
        'string' => ':Attribute deve ter entre :min e :max caracteres.',
    ],

    'gt' => [
        'array' => ':Attribute deve ter mais de :value itens.',
        'file' => ':Attribute deve ser maior que :value kilobytes.',
        'numeric' => ':Attribute deve ser maior que :value.',
        'string' => ':Attribute deve ter mais de :value caracteres.',
    ],

    'gte' => [
        'array' => ':Attribute deve ter :value itens ou mais.',
        'file' => ':Attribute deve ser maior ou igual a :value kilobytes.',
        'numeric' => ':Attribute deve ser maior ou igual a :value.',
        'string' => ':Attribute deve ter :value caracteres ou mais.',
    ],

    'lt' => [
        'array' => ':Attribute deve ter menos de :value itens.',
        'file' => ':Attribute deve ser menor que :value kilobytes.',
        'numeric' => ':Attribute deve ser menor que :value.',
        'string' => ':Attribute deve ter menos de :value caracteres.',
    ],

    'lte' => [
        'array' => ':Attribute não pode ter mais de :value itens.',
        'file' => ':Attribute deve ser menor ou igual a :value kilobytes.',
        'numeric' => ':Attribute deve ser menor ou igual a :value.',
        'string' => ':Attribute deve ter :value caracteres ou menos.',
    ],

    'max' => [
        'array' => ':Attribute não pode ter mais de :max itens.',
        'file' => ':Attribute não pode passar de :max kilobytes.',
        'numeric' => ':Attribute não pode ser maior que :max.',
        'string' => ':Attribute não pode passar de :max caracteres.',
    ],

    'min' => [
        'array' => ':Attribute deve ter ao menos :min itens.',
        'file' => ':Attribute deve ter ao menos :min kilobytes.',
        'numeric' => ':Attribute deve ser no mínimo :min.',
        'string' => ':Attribute deve ter ao menos :min caracteres.',
    ],

    'size' => [
        'array' => ':Attribute deve conter :size itens.',
        'file' => ':Attribute deve ter :size kilobytes.',
        'numeric' => ':Attribute deve ser :size.',
        'string' => ':Attribute deve ter :size caracteres.',
    ],

    /*
     * Mensagens por campo, quando a genérica não basta.
     *
     * O preço promocional é o caso que motivou isto: a regra é `lt:price_cents`
     * e a mensagem padrão citava o limite em centavos ("menor que 1000"), o que
     * o lojista lê como mil reais. Aqui a frase explica a regra em vez de
     * despejar o número.
     */
    'custom' => [
        'promo_price_cents' => [
            'lt' => 'O preço promocional precisa ser menor que o preço normal.',
            'min' => 'O preço promocional não pode ser negativo.',
        ],
        'price_cents' => [
            'required' => 'Informe o preço do item.',
            'min' => 'O preço não pode ser negativo.',
        ],
        'email' => [
            'unique' => 'Este e-mail já está cadastrado.',
        ],
        'password' => [
            'confirmed' => 'As senhas não conferem.',
        ],
    ],

    /*
     * Nome dos campos como o lojista os vê na tela.
     *
     * Sem isto o Laravel usa o nome da coluna: "promo price cents", "cep",
     * "category_id". A chave é o nome do campo na requisição.
     */
    'attributes' => [
        'name' => 'o nome',
        'email' => 'o e-mail',
        'password' => 'a senha',
        'phone' => 'o telefone',
        'description' => 'a descrição',
        'price_cents' => 'o preço',
        'promo_price_cents' => 'o preço promocional',
        'category_id' => 'a categoria',
        'image_url' => 'a imagem',
        'is_available' => 'a disponibilidade',
        'position' => 'a posição',
        'slug' => 'o endereço da loja',
        'street' => 'a rua',
        'number' => 'o número',
        'complement' => 'o complemento',
        'district' => 'o bairro',
        'city' => 'a cidade',
        'state' => 'o estado',
        'zip' => 'o CEP',
        'cpf' => 'o CPF',
        'notes' => 'as observações',
        'payment_method' => 'a forma de pagamento',
        'fulfillment' => 'a modalidade de entrega',
        'items' => 'os itens',
        'quantity' => 'a quantidade',
        'fee_cents' => 'a taxa de entrega',
        'min_order_cents' => 'o pedido mínimo',
        'eta_minutes' => 'o tempo de entrega',
    ],
];
