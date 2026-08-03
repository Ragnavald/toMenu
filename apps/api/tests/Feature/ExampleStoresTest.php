<?php

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ExampleStoresSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * As duas lojas de exemplo, que rodam contra o banco de PRODUÇÃO a cada deploy.
 *
 * O que este arquivo protege não é a existência das lojas — é o conjunto de
 * garantias que torna seguro rodar o seeder repetidamente ao lado de clientes
 * pagantes: não duplicar, não sobrescrever edições do painel, e nunca gravar
 * uma senha que não veio do ambiente.
 */

function seedExampleStores(): void
{
    forgetTenant();

    (new ExampleStoresSeeder)->run();
}

it('cria as duas lojas com os planos opostos', function () {
    config(['app.example_store_password' => null]);

    seedExampleStores();

    $loja = Tenant::where('slug', 'to-menu-loja')->first();
    $cardapio = Tenant::where('slug', 'to-menu-cardapio')->first();

    expect($loja)->not->toBeNull()
        ->and($cardapio)->not->toBeNull();

    // O contraste entre os planos é o motivo de existirem duas: uma aceita
    // pedido, a outra é vitrine.
    expect($loja->plan->slug)->toBe(Plan::PRO)
        ->and($cardapio->plan->slug)->toBe(Plan::MENU_ONLY);
});

/*
 * Sem EXAMPLE_STORE_PASSWORD o seeder cria a loja e pula o usuário.
 *
 * Esta é a garantia que impede uma senha previsível de nascer em produção. Um
 * default no código — 'password', o nome da loja, qualquer coisa — seria
 * credencial pública para duas lojas que a landing linka de propósito.
 */
it('não cria usuário quando a senha não está no ambiente', function () {
    putenv('EXAMPLE_STORE_PASSWORD');

    seedExampleStores();

    $tenant = Tenant::where('slug', 'to-menu-loja')->firstOrFail();

    expect(User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('cria um owner verificado para cada loja quando a senha existe', function () {
    putenv('EXAMPLE_STORE_PASSWORD=segredo-de-teste');

    seedExampleStores();

    foreach (['to-menu-loja' => 'loja@to-menu.com', 'to-menu-cardapio' => 'cardapio@to-menu.com'] as $slug => $email) {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();

        $user = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->first();

        expect($user)->not->toBeNull("owner de {$slug}")
            ->and($user->role)->toBe('owner')
            // Sem isto o LoginController barra com 403 e a conta nasce
            // inutilizável, inclusive para a impersonação.
            ->and($user->hasVerifiedEmail())->toBeTrue()
            ->and(Hash::check('segredo-de-teste', $user->password))->toBeTrue();
    }

    putenv('EXAMPLE_STORE_PASSWORD');
});

/*
 * Idempotência, que é a razão de o seeder poder rodar a cada deploy.
 *
 * Duas execuções não podem produzir loja repetida nem usuário repetido — o
 * segundo estouraria no unique (tenant_id, email) e derrubaria o deploy inteiro
 * em `deploy-migrate`, antes de qualquer container novo subir.
 */
it('roda duas vezes sem duplicar loja nem usuário', function () {
    putenv('EXAMPLE_STORE_PASSWORD=segredo-de-teste');

    seedExampleStores();
    seedExampleStores();

    expect(Tenant::whereIn('slug', ExampleStoresSeeder::SLUGS)->count())->toBe(2);

    $tenant = Tenant::where('slug', 'to-menu-loja')->firstOrFail();

    expect(User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);

    putenv('EXAMPLE_STORE_PASSWORD');
});

/*
 * A senha só é gravada na criação.
 *
 * Se o seeder a reescrevesse a cada deploy, uma troca feita pelo painel seria
 * desfeita em silêncio: o login voltaria a aceitar a senha do ambiente e
 * ninguém descobriria que a nova parou de valer.
 */
it('não sobrescreve a senha de um usuário que já existe', function () {
    putenv('EXAMPLE_STORE_PASSWORD=senha-original');

    seedExampleStores();

    $tenant = Tenant::where('slug', 'to-menu-loja')->firstOrFail();

    $user = User::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->firstOrFail();

    // O lojista troca a senha pelo painel.
    $user->forceFill(['password' => Hash::make('trocada-no-painel')])->save();

    // Um deploy seguinte, com o ambiente ainda apontando para a senha antiga.
    seedExampleStores();

    expect(Hash::check('trocada-no-painel', $user->fresh()->password))->toBeTrue()
        ->and(Hash::check('senha-original', $user->fresh()->password))->toBeFalse();

    putenv('EXAMPLE_STORE_PASSWORD');
});

/*
 * O seeder não pode reescrever uma loja que alguém ajustou pelo painel.
 *
 * `createIfMissing` sai cedo quando o slug existe; este teste garante que a
 * saída cedo continue valendo para os dados, e não só para a linha do tenant.
 */
it('mantém as edições feitas na loja entre deploys', function () {
    seedExampleStores();

    $tenant = Tenant::where('slug', 'to-menu-cardapio')->firstOrFail();
    $tenant->update(['name' => 'Nome Ajustado no Painel']);

    seedExampleStores();

    expect($tenant->fresh()->name)->toBe('Nome Ajustado no Painel');
});
