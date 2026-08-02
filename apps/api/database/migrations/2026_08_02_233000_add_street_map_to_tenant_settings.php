<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traçado das ruas ao redor da loja, já projetado e pronto para desenhar.
 *
 * O storefront desenha o mapa do perfil da loja a partir de dados do
 * OpenStreetMap, obtidos no Overpass — um serviço público gratuito que, medido
 * daqui, respondeu entre 3s e 10s e devolveu 429 ou 504 em cerca de metade das
 * tentativas. Consultá-lo no render deixaria o mapa piscando entre existir e
 * não existir conforme a sorte da requisição, além de ser abuso de um recurso
 * comunitário: cada visita ao perfil de uma loja movimentada seria uma consulta
 * nova.
 *
 * Guardado aqui, o Overpass é chamado uma vez por endereço, em background, e a
 * página só lê do banco.
 *
 * O conteúdo é o resultado final — uma lista de `{d, width}` em coordenadas de
 * viewBox, não lat/lng crus. A projeção é determinística e depende apenas do
 * centro e do enquadramento, ambos fixos: fazê-la uma vez na escrita evita
 * repeti-la a cada render e mantém o payload do cardápio menor.
 *
 * `null` é um estado legítimo e esperado: endereço não geocodificável, região
 * sem vias mapeadas ou Overpass fora do ar. O perfil simplesmente mostra o
 * cartão de endereço sem mapa, que é a informação de que o cliente precisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->jsonb('street_map')->nullable()->after('cover_url');

            // Endereço a partir do qual o traçado foi gerado. É o que permite
            // detectar que o lojista mudou de endereço e o desenho guardado
            // ficou obsoleto — sem isto, um mapa do endereço antigo continuaria
            // apontando para o lugar errado indefinidamente.
            $table->string('street_map_address', 300)->nullable()->after('street_map');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['street_map', 'street_map_address']);
        });
    }
};
