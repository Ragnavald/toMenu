<?php

use App\Services\StripeMode;

/**
 * O que estes testes protegem é a troca entre teste e produção.
 *
 * O erro que motiva a validação de prefixo não é hipotético: colar a chave do
 * ambiente errado no .env não quebra nada visível — o Stripe aceita a chamada —
 * e o sintoma aparece só quando um cliente real tenta pagar, ou pior, quando
 * uma loja em homologação cobra um cartão de verdade.
 */
function stripeMode(array $config = []): StripeMode
{
    config(array_merge([
        'services.stripe.mode' => 'test',
        'services.stripe.test.key' => 'pk_test_abc',
        'services.stripe.test.secret' => 'sk_test_abc',
        'services.stripe.test.webhook_secret' => 'whsec_test',
        'services.stripe.live.key' => 'pk_live_abc',
        'services.stripe.live.secret' => 'sk_live_abc',
        'services.stripe.live.webhook_secret' => 'whsec_live',
    ], $config));

    return new StripeMode;
}

it('usa as chaves de teste no modo test', function () {
    $mode = stripeMode();

    expect($mode->current())->toBe('test')
        ->and($mode->isLive())->toBeFalse()
        ->and($mode->secretKey())->toBe('sk_test_abc')
        ->and($mode->publishableKey())->toBe('pk_test_abc')
        ->and($mode->webhookSecret())->toBe('whsec_test');
});

it('usa as chaves de produção no modo live', function () {
    $mode = stripeMode(['services.stripe.mode' => 'live']);

    expect($mode->current())->toBe('live')
        ->and($mode->isLive())->toBeTrue()
        ->and($mode->secretKey())->toBe('sk_live_abc')
        ->and($mode->publishableKey())->toBe('pk_live_abc')
        ->and($mode->webhookSecret())->toBe('whsec_live');
});

it('aceita apelidos comuns de ambiente', function (string $written, string $expected) {
    expect(stripeMode(['services.stripe.mode' => $written])->current())->toBe($expected);
})->with([
    ['production', 'live'],
    ['prod', 'live'],
    ['producao', 'live'],
    ['LIVE', 'live'],
    ['dev', 'test'],
    ['sandbox', 'test'],
    ['teste', 'test'],
]);

it('recusa um modo desconhecido em vez de adivinhar', function () {
    // Silenciar seria pior: 'staging' cairia num padrão qualquer, e metade das
    // chances é cobrar de verdade.
    stripeMode(['services.stripe.mode' => 'staging'])->current();
})->throws(RuntimeException::class, 'STRIPE_MODE inválido');

it('recusa a chave de produção declarada como teste', function () {
    stripeMode(['services.stripe.test.secret' => 'sk_live_vazada'])->secretKey();
})->throws(RuntimeException::class, 'Credencial do ambiente errado.');

it('recusa a chave de teste declarada como produção', function () {
    stripeMode([
        'services.stripe.mode' => 'live',
        'services.stripe.live.secret' => 'sk_test_abc',
    ])->secretKey();
})->throws(RuntimeException::class, 'Credencial do ambiente errado.');

it('não expõe a chave inteira na mensagem de erro', function () {
    // A mensagem vai para log e para tela de erro; vazar o secret ali seria
    // trocar um erro de configuração por um incidente de credencial.
    $secret = 'sk_live_segredo_que_nao_pode_vazar';

    try {
        stripeMode(['services.stripe.test.secret' => $secret])->secretKey();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('segredo_que_nao_pode_vazar');

        return;
    }

    $this->fail('Esperava RuntimeException.');
});

it('avisa qual variável falta quando a chave está vazia', function () {
    stripeMode(['services.stripe.test.secret' => ''])->secretKey();
})->throws(RuntimeException::class, 'STRIPE_TEST_SECRET não está definida');

it('reporta que não está configurado quando falta o secret do modo ativo', function () {
    expect(stripeMode(['services.stripe.test.secret' => ''])->isConfigured())->toBeFalse()
        ->and(stripeMode()->isConfigured())->toBeTrue();
});

it('ignora as chaves do outro modo ao checar configuração', function () {
    // Regressão do modelo antigo: com um só conjunto de chaves, ter as de teste
    // preenchidas fazia o modo live parecer pronto.
    $mode = stripeMode([
        'services.stripe.mode' => 'live',
        'services.stripe.live.secret' => '',
    ]);

    expect($mode->isConfigured())->toBeFalse();
});
