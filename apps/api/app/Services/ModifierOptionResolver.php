<?php

namespace App\Services;

use App\Models\ModifierGroup;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Reduz um grupo de opções — de lista ou composto — a um formato único.
 *
 * O cardápio e a criação de pedido precisam concordar sobre o que é uma opção
 * válida e quanto ela custa. Se cada um resolvesse isso por conta, um sabor
 * fora de estoque poderia sumir da vitrine e continuar aceito no POST, ou o
 * preço exibido divergir do cobrado. Ambos passam por aqui.
 *
 * A "opção" resultante tem sempre id, nome e centavos, seja ela um modifier
 * (onde os centavos são um delta sobre o preço do produto) ou um produto-sabor
 * (onde os centavos são o preço cheio daquele sabor).
 */
class ModifierOptionResolver
{
    /**
     * Opções disponíveis de um grupo, na ordem de exibição.
     *
     * @return Collection<int,array{id:int,name:string,priceCents:int,imageUrl:?string,description:?string}>
     */
    public function options(ModifierGroup $group): Collection
    {
        return $group->isComposed()
            ? $this->fromProducts($group)
            : $this->fromModifiers($group);
    }

    /**
     * Grupo de lista: as opções são os modifiers cadastrados nele.
     *
     * `priceCents` aqui é o delta — pode ser negativo (desconto por remover
     * ingrediente), e é por isso que o campo não é unsigned em lugar nenhum.
     */
    private function fromModifiers(ModifierGroup $group): Collection
    {
        return $group->modifiers
            ->where('is_available', true)
            ->values()
            ->map(fn ($modifier) => [
                'id' => $modifier->id,
                'name' => $modifier->name,
                'priceCents' => $modifier->price_delta_cents,
                'imageUrl' => null,
                'description' => null,
            ]);
    }

    /**
     * Grupo composto: as opções são os produtos vinculados.
     *
     * O preço sai do override da tabela pivot quando existe; senão, do preço
     * efetivo do próprio produto — assim uma promoção no sabor avulso vale
     * também quando ele é escolhido como metade da pizza.
     *
     * Produto indisponível não vira opção: é o mesmo estoque, e ofertar um
     * sabor que a cozinha não tem gera cancelamento.
     */
    private function fromProducts(ModifierGroup $group): Collection
    {
        return $group->optionProducts
            ->where('is_available', true)
            ->values()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'priceCents' => $product->pivot->price_cents ?? $product->effectivePriceCents(),
                'imageUrl' => $product->image_url,
                'description' => $product->description,
            ]);
    }
}
