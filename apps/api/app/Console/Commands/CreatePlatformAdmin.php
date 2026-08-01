<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Cria (ou promove) uma conta de staff da plataforma.
 *
 * Não há cadastro público para isto e nem deveria haver: a conta administra
 * todas as lojas. O caminho é sempre o console do servidor, o que exige acesso
 * ao droplet — uma barreira que nenhum endpoint HTTP oferece.
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:admin
        {email : E-mail de acesso}
        {--name= : Nome exibido no painel}
        {--password= : Senha; se omitida, é pedida sem eco no terminal}';

    protected $description = 'Cria uma conta de staff com acesso ao painel da plataforma';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));

        // `secret()` não ecoa a digitação: sem isso a senha fica no histórico do
        // shell e no scrollback do terminal.
        $password = $this->option('password') ?: $this->secret('Senha');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email'],
                'password' => ['required', 'string', 'min:12'],
            ],
            ['password.min' => 'A senha precisa ter ao menos 12 caracteres.'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        /*
         * Procura só entre as contas centrais.
         *
         * O e-mail é único por (tenant_id, email), então o mesmo endereço pode
         * existir como dono de uma loja. Promover essa linha criaria um usuário
         * que é lojista E administrador da plataforma — a constraint CHECK do
         * banco recusaria, mas com um erro de constraint em vez de explicação.
         */
        $existing = User::whereNull('tenant_id')->where('email', $email)->first();

        if ($existing) {
            $existing->update([
                'password' => $password,
                'is_platform_admin' => true,
                'name' => $this->option('name') ?: $existing->name,
            ]);

            $this->info("Conta de staff atualizada: {$email}");

            return self::SUCCESS;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("O e-mail {$email} já pertence ao usuário de uma loja.");
            $this->line('Use outro endereço: a mesma conta não pode ser lojista e staff da plataforma.');

            return self::FAILURE;
        }

        User::create([
            'tenant_id' => null,
            'name' => $this->option('name') ?: 'Equipe ToMenu',
            'email' => $email,
            'password' => $password,
            'role' => 'platform',
            'is_platform_admin' => true,
        ]);

        $this->info("Conta de staff criada: {$email}");
        $this->line('Acesse o painel em admin.'.config('tenancy.root_domain'));

        return self::SUCCESS;
    }
}
