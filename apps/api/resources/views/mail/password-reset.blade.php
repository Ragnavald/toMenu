{{--
    Link de redefinição de senha do painel.

    Usa o layout markdown do próprio framework, que já traz HTML responsivo e
    a versão em texto puro — cliente de e-mail é terreno hostil demais para
    escrever CSS à mão sem ganho nenhum aqui.
--}}
<x-mail::message>
# Redefinir sua senha

Olá, {{ $userName }}.

Recebemos um pedido para redefinir a senha do painel de **{{ $storeName }}**.

<x-mail::button :url="$url">
Criar uma senha nova
</x-mail::button>

O link vale por {{ $minutes }} minutos e só pode ser usado uma vez.

Se não foi você quem pediu, ignore este e-mail — sua senha continua a mesma e
ninguém teve acesso à sua conta.

Obrigado,<br>
Equipe {{ config('app.name') }}

{{--
    O botão vira link clicável no HTML, mas parte dos clientes bloqueia ou
    reescreve URLs. Repetir o endereço em texto é o que salva o lojista que
    não consegue clicar.
--}}
<x-mail::subcopy>
Se o botão acima não funcionar, copie e cole este endereço no navegador:
{{ $url }}
</x-mail::subcopy>
</x-mail::message>
