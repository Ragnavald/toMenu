<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Código de cupom inexistente, expirado ou esgotado.
 *
 * Uma exceção própria, e não um retorno nulo, porque os dois pontos que
 * validam cupom — a prévia na tela e a criação da sessão — precisam distinguir
 * "cupom recusado" (culpa do código digitado, 422) de "o Stripe falhou"
 * (problema nosso, 502). Colapsar os dois faria um erro de infraestrutura ser
 * exibido ao lojista como "cupom inválido", e ele ficaria tentando outros
 * códigos sem nunca conseguir.
 *
 * A mensagem não diz QUAL condição falhou de propósito: distinguir "não existe"
 * de "esgotado" transforma o campo num oráculo para descobrir códigos válidos
 * por tentativa.
 */
class InvalidCouponException extends RuntimeException
{
    public function __construct(string $message = 'Cupom inválido ou expirado.')
    {
        parent::__construct($message);
    }
}
