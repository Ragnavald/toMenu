<?php

namespace App\Services;

use RuntimeException;

/**
 * Resolve qual conjunto de credenciais do Stripe está ativo.
 *
 * Ponto único de leitura das chaves: nada mais no código chama
 * `config('services.stripe.secret')`, porque essa chave não existe mais — há
 * `services.stripe.test.*` e `services.stripe.live.*`, e escolher entre os dois
 * espalhado por vários arquivos é como um ambiente acaba meio em teste e meio
 * em produção.
 *
 * A validação de prefixo é a defesa principal. O acidente que ela previne é
 * concreto: colar a chave de produção no bloco de teste (ou o inverso) não
 * quebra nada visível — o Stripe aceita a chamada — e a loja passa a cobrar
 * cartão de verdade num ambiente de homologação, ou a falhar silenciosamente
 * em produção porque o cartão de teste não é aceito. Um prefixo conferido no
 * boot transforma isso num erro imediato e legível.
 */
class StripeMode
{
    public const TEST = 'test';

    public const LIVE = 'live';

    /** Modo ativo, normalizado. */
    public function current(): string
    {
        $mode = strtolower(trim((string) config('services.stripe.mode', self::TEST)));

        // Alias comuns: quem escreve o .env pensa em "produção", não em "live".
        $mode = match ($mode) {
            'prod', 'production', 'producao', 'produção' => self::LIVE,
            'dev', 'development', 'sandbox', 'teste' => self::TEST,
            default => $mode,
        };

        if (! in_array($mode, [self::TEST, self::LIVE], true)) {
            throw new RuntimeException(
                "STRIPE_MODE inválido: '{$mode}'. Use 'test' ou 'live'."
            );
        }

        return $mode;
    }

    public function isLive(): bool
    {
        return $this->current() === self::LIVE;
    }

    /** Chave publicável — pública por definição, vai para o browser. */
    public function publishableKey(): string
    {
        return $this->credential('key', 'pk_');
    }

    /** Secret key — nunca sai do servidor. */
    public function secretKey(): string
    {
        return $this->credential('secret', 'sk_');
    }

    /**
     * Signing secret do webhook.
     *
     * Não valida prefixo de modo: o `whsec_` é o mesmo em test e live, porque
     * o segredo pertence ao endpoint e não ao par de chaves. O que protege
     * contra usar o secret errado é a própria verificação HMAC, que falha.
     */
    public function webhookSecret(): string
    {
        $secret = (string) config("services.stripe.{$this->current()}.webhook_secret");

        if ($secret === '') {
            throw new RuntimeException($this->missingMessage('webhook_secret'));
        }

        return $secret;
    }

    /** Há credenciais suficientes para falar com o Stripe? */
    public function isConfigured(): bool
    {
        return (string) config("services.stripe.{$this->current()}.secret") !== '';
    }

    /**
     * Lê a credencial e confere que ela pertence ao modo declarado.
     *
     * O Stripe carimba o ambiente no próprio prefixo (`sk_test_`/`sk_live_`),
     * então o descasamento é detectável sem nenhuma chamada de rede.
     */
    private function credential(string $name, string $prefix): string
    {
        $mode = $this->current();
        $value = (string) config("services.stripe.{$mode}.{$name}");

        if ($value === '') {
            throw new RuntimeException($this->missingMessage($name));
        }

        $expected = $prefix.$mode.'_';

        if (! str_starts_with($value, $expected)) {
            // A chave em si nunca entra na mensagem: ela vai para log e para
            // tela de erro. Só o prefixo, que basta para o diagnóstico.
            $found = substr($value, 0, strlen($expected));

            throw new RuntimeException(
                "STRIPE_MODE={$mode} mas a chave em services.stripe.{$mode}.{$name} ".
                "começa com '{$found}' e deveria começar com '{$expected}'. ".
                'Credencial do ambiente errado.'
            );
        }

        return $value;
    }

    private function missingMessage(string $name): string
    {
        $env = 'STRIPE_'.strtoupper($this->current()).'_'.strtoupper($name);

        return "Stripe em modo {$this->current()}: {$env} não está definida.";
    }
}
