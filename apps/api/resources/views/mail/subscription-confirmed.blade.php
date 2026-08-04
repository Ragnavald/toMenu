{{--
    Confirmação da assinatura da mensalidade.

    Mesmo layout markdown dos demais e-mails da conta: HTML responsivo e versão
    em texto puro já vêm prontos do framework.

    O valor e a data da próxima cobrança aparecem em destaque de propósito —
    é uma cobrança recorrente em cartão, e o lojista precisa saber quanto e
    quando sem ter que abrir o painel. E-mail de cobrança que esconde o valor é
    o que gera contestação no cartão.
--}}
<x-mail::message>
# Assinatura confirmada

Olá, {{ $userName }}.

O pagamento da **{{ $storeName }}** foi confirmado e a assinatura já está
ativa. Obrigado!

@if ($planName && $priceCents)
**Plano {{ $planName }}** — R$ {{ number_format($priceCents / 100, 2, ',', '.') }} por mês.
@endif

{{--
    A data pode não existir ainda: ela chega em `customer.subscription.*`, e a
    ordem de entrega dos eventos do Stripe não é garantida — o
    `checkout.session.completed` costuma vir primeiro. Omitir a linha é melhor
    que anunciar uma data errada num aviso de cobrança.
--}}
@if ($currentPeriodEndsAt)
A próxima cobrança acontece em **{{ $currentPeriodEndsAt->format('d/m/Y') }}**,
no mesmo cartão, automaticamente.
@endif

<x-mail::button :url="$adminUrl">
Abrir o painel
</x-mail::button>

Você pode trocar o cartão, ver as faturas ou cancelar a qualquer momento em
**Assinatura**, dentro do painel. O cancelamento vale até o fim do período já
pago — nada é perdido no meio do mês.

Se ficar com alguma dúvida, é só responder este e-mail.

Boas vendas,<br>
Equipe {{ config('app.name') }}

<x-mail::subcopy>
Se o botão acima não funcionar, copie e cole este endereço no navegador:
{{ $adminUrl }}
</x-mail::subcopy>
</x-mail::message>
