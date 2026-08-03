{{--
    Confirmação do e-mail do lojista.

    Mesmo layout markdown do framework usado nos outros e-mails: HTML
    responsivo e versão em texto puro sem CSS escrito à mão.
--}}
<x-mail::message>
# Confirme seu e-mail

Olá, {{ $userName }}.

Sua loja **{{ $storeName }}** já foi criada. Falta só confirmar este endereço
de e-mail para liberar o acesso ao painel.

<x-mail::button :url="$url">
Confirmar meu e-mail
</x-mail::button>

O link vale por {{ $minutes }} minutos e só pode ser usado uma vez. Se ele
expirar, é só pedir outro na tela de confirmação.

Se não foi você quem criou esta conta, ignore este e-mail — sem a confirmação
ninguém consegue entrar no painel.

Obrigado,<br>
Equipe {{ config('app.name') }}

{{--
    Parte dos clientes de e-mail bloqueia ou reescreve URLs no botão; repetir
    o endereço em texto é o que salva quem não consegue clicar.
--}}
<x-mail::subcopy>
Se o botão acima não funcionar, copie e cole este endereço no navegador:
{{ $url }}
</x-mail::subcopy>
</x-mail::message>
