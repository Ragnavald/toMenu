# Conta de staff da plataforma

Acesso ao painel de `admin.to-menu.com` — a conta que administra **todas** as
lojas. Este documento cobre criar a conta, redefinir a senha e diagnosticar
quando o login não passa.

O painel do lojista (`<slug>.to-menu.com`) usa outra identidade e não é assunto
daqui: lá o usuário é único por `(tenant_id, email)` e o slug da loja faz parte
das credenciais. Ver `PlatformAuthController`.

---

## Antes de tudo: não existe senha padrão

Nenhuma conta de staff vem criada de fábrica, e não há seed com credencial
conhecida. A senha é gravada com hash e **não pode ser lida** — nem no banco,
nem em log, nem em variável de ambiente. Esqueceu? O caminho é redefinir, e o
procedimento é o mesmo da criação.

Também não existe cadastro público nem "esqueci minha senha" por e-mail. O único
caminho é o console do servidor, o que exige acesso SSH ao droplet — uma
barreira que nenhum endpoint HTTP oferece.

---

## Criar ou redefinir

Os dois casos são o mesmo comando. Rodar com um e-mail que já existe atualiza a
senha da conta; rodar com um e-mail novo cria a conta.

De `/opt/tomenu`, no droplet:

```bash
make platform-admin EMAIL=voce@to-menu.com NAME="Seu Nome"
```

A senha é pedida em seguida, sem eco no terminal. Mínimo de 12 caracteres.

> **Redefinindo uma conta existente:** passe o `NAME=` de novo. O alvo do
> Makefile sempre repassa `--name=`, então omiti-lo grava o nome como string
> vazia e o painel passa a exibir um nome em branco. Não quebra o login, mas
> deixa a trilha de auditoria pior de ler.

### Sem `make` no servidor

Droplets provisionados antes de agosto/2026 não têm `make`. Instale uma vez com
`apt-get install -y make`, ou use o comando completo:

```bash
cd /opt/tomenu
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm api php artisan platform:admin voce@to-menu.com --name="Seu Nome"
```

Os dois flags são obrigatórios. `docker compose ps` sem eles lê o
`docker-compose.yml` de desenvolvimento (projeto `tomenu`), não encontra nada e
devolve uma lista vazia — o que parece stack fora do ar, mas não é. Produção
roda sob o projeto `tomenu-prod`.

Se preferir fixar o prefixo na sessão:

```bash
C="docker compose -f docker-compose.prod.yml --env-file .env.prod"
$C run --rm api php artisan platform:admin voce@to-menu.com --name="Seu Nome"
$C ps
```

### Pelo container já em execução

Também funciona, e é o caminho quando você já está com um shell aberto:

```bash
docker exec -it tomenu-prod-api-1 php artisan platform:admin voce@to-menu.com --name="Seu Nome"
```

O `-it` não é opcional aqui: sem ele o prompt de senha (que não ecoa) não tem
terminal para ler e o comando falha.

Para abrir um shell no container, o comando é `bash` — sem hífen. `-bash` é
lido como nome de executável e devolve
`exec: "-bash": executable file not found in $PATH`.

```bash
docker exec -it tomenu-prod-api-1 bash
```

### Automação

`--password` existe para scripts, mas deixa a senha no histórico do shell e no
scrollback. Prefira o prompt interativo em uso manual.

```bash
docker exec tomenu-prod-api-1 \
    php artisan platform:admin voce@to-menu.com --name="Seu Nome" --password="…"
```

---

## Listar as contas existentes

Útil para descobrir com qual e-mail a conta foi criada antes de redefinir:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm api php artisan tinker --execute='
        App\Models\User::where("is_platform_admin", true)
            ->get(["email","name","created_at"])
            ->each(fn($u) => print("$u->email  $u->name  $u->created_at\n"));'
```

Não há como recuperar senha por aqui — a coluna guarda só o hash.

---

## Remover o acesso de alguém

Não há comando dedicado. Baixar a flag basta: o login exige `tenant_id IS NULL`
**e** `is_platform_admin = true`, então a conta deixa de autenticar no painel.

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm api php artisan tinker --execute='
        App\Models\User::whereNull("tenant_id")
            ->where("email", "ex-colega@to-menu.com")
            ->update(["is_platform_admin" => false]);'
```

Tokens já emitidos continuam válidos até serem apagados — revogue também:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm api php artisan tinker --execute='
        $u = App\Models\User::whereNull("tenant_id")->where("email","ex-colega@to-menu.com")->first();
        $u?->tokens()->delete();'
```

---

## Quando dá errado

**`A senha precisa ter ao menos 12 caracteres.`**
Validação do próprio comando. Nada foi gravado; rode de novo.

**`O e-mail X já pertence ao usuário de uma loja.`**
Uma constraint `CHECK` no banco (`users_platform_admin_has_no_tenant`) impede
que a mesma conta seja lojista e staff da plataforma — seria um usuário que é
dono de uma loja *e* administrador de todas. Use outro endereço.

**Credenciais inválidas no painel, com a senha certa.**
O login filtra por `is_platform_admin = true`. Se a linha foi criada por seeder
ou `INSERT` manual sem a flag, `tenant_id IS NULL` sozinho não basta. Rodar o
`platform:admin` com o mesmo e-mail promove a conta e corrige.

**`admin.to-menu.com` responde 404 em `/api/platform/*`.**
Não é conta: o grupo de rotas foi registrado depois do prefixo curinga
`{tenant}` em `routes/api.php`, e `platform` está sendo lido como slug de loja.
O esperado sem token é **401**:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://admin.to-menu.com/api/platform/stores   # 401
```

---

## Auditoria

Toda ação feita pelo painel vai para `platform_audit_logs`, incluindo as que
apagam uma loja. A tabela é append-only no banco (RLS com `FORCE`, sem policy de
`UPDATE`/`DELETE`), então nem a aplicação reescreve a trilha — e ela sobrevive à
purga da loja, sendo o único registro do que existia ali.

Criar ou redefinir uma conta pelo console **não** passa por essa trilha: é
operação de servidor, e o registro dela é o acesso SSH ao droplet.

Ver `DEPLOY.md` para a consulta aos logs e para o que cada ação do painel faz.
