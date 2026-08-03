{{--
    Boas-vindas ao lojista que acabou de criar a loja.

    Mesmo layout markdown do framework usado na redefinição de senha: já traz
    HTML responsivo e a versão em texto puro, e cliente de e-mail é terreno
    hostil demais para escrever CSS à mão sem ganho nenhum aqui.
--}}
<x-mail::message>
# Sua loja está no ar

Olá, {{ $userName }}.

A **{{ $storeName }}** já está criada e o endereço abaixo é público desde
agora — falta só montar o cardápio.

<x-mail::button :url="$adminUrl">
Abrir o painel
</x-mail::button>

O que costuma dar mais resultado nos primeiros minutos:

1. Cadastrar as categorias e os primeiros produtos.
2. Conferir os horários de funcionamento e a taxa de entrega.
3. Enviar o endereço da loja para os seus clientes.

@if ($trialEndsAt)
Seu teste gratuito vai até **{{ $trialEndsAt->format('d/m/Y') }}**, sem
cadastro de cartão. Você recebe um aviso antes de acabar.
@endif

Se ficar com alguma dúvida, é só responder este e-mail.

Boas vendas,<br>
Equipe {{ config('app.name') }}

{{--
    O botão vira link clicável no HTML, mas parte dos clientes bloqueia ou
    reescreve URLs. Repetir os dois endereços em texto é o que salva o lojista
    que não consegue clicar — e o da loja ele vai querer copiar de qualquer
    forma, para divulgar.
--}}
<x-mail::subcopy>
O endereço da sua loja: {{ $storefrontUrl }}

Se o botão acima não funcionar, copie e cole este endereço no navegador:
{{ $adminUrl }}
</x-mail::subcopy>
</x-mail::message>
