# Deploy — to-menu.com

Provisionamento de produção num droplet da DigitalOcean, com Cloudflare Tunnel
terminando o TLS.

Arquitetura resultante:

```
                     ┌─ to-menu.com      ─┐
navegador ──TLS──▶ Cloudflare ─túnel──▶ ├─ *.to-menu.com   ─┤──▶ storefront:3000
                     └─ api.to-menu.com ──┘──▶ nginx:80 ──▶ php-fpm

storefront ──rede interna──▶ nginx:80        (render server-side, ~1ms)
```

Nenhuma porta fica aberta à internet: o `cloudflared` disca de dentro para fora.
O firewall libera apenas SSH.

---

## 1. Droplet

- **Imagem**: Ubuntu 24.04 LTS
- **Região**: NYC1 ou NYC3 (a DigitalOcean não tem região no Brasil; ~120ms,
  irrelevante para o cardápio, que é servido do edge — ver passo 8)
- **Tamanho**: 2 GB / 1 vCPU no mínimo; 4 GB se quiser folga no build do Next
- **Autenticação**: chave SSH (o cloud-init desativa senha; criar com senha
  deixaria você sem acesso)
- **Backups**: ativar, diário
- **User data**: cole o conteúdo de `docker/droplet/cloud-init.yaml`

O cloud-init instala Docker, cria 2 GB de swap, fecha o firewall exceto SSH,
endurece o SSH e liga `unattended-upgrades`. Leva 2–4 min após o droplet
aparecer como ativo:

```bash
ssh root@SEU_IP
cloud-init status --wait
```

> Não coloque segredo no user-data: ele é legível para sempre pelo metadata
> service e pelo painel.

---

## 2. Repositório

Deploy key **read-only** — a chave pessoal daria acesso de escrita a todos os
seus repositórios se o droplet for comprometido.

```bash
ssh-keygen -t ed25519 -C "droplet-tomenu" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Cole em **github.com/Ragnavald/toMenu → Settings → Deploy keys → Add deploy
key**, sem marcar "Allow write access". Então:

```bash
ssh -T git@github.com   # "Hi Ragnavald/toMenu!" = sucesso
git clone git@github.com:Ragnavald/toMenu.git /opt/tomenu
cd /opt/tomenu
```

---

## 3. Postgres no host

O `docker-compose.prod.yml` **não** inclui Postgres: banco de produção como
container efêmero ao lado da aplicação convida à perda de dados num
`docker compose down -v`.

```bash
apt-get install -y postgresql-16
systemctl enable --now postgresql
```

Crie o banco e o papel de migração:

```bash
sudo -u postgres psql <<'SQL'
CREATE DATABASE tomenu;
CREATE ROLE tomenu_owner WITH LOGIN PASSWORD 'TROQUE_ESTA_SENHA' SUPERUSER;
ALTER DATABASE tomenu OWNER TO tomenu_owner;
SQL
```

Agora o papel da aplicação. **Este passo não é opcional**: o
`docker/postgres/init/01-app-role.sql` cria `tomenu_app` sem `BYPASSRLS`, e é o
que sustenta a terceira camada de isolamento entre tenants. Se a aplicação
conectar como owner/superuser, o Postgres **ignora as políticas de RLS em
silêncio** — sem erro, sem log, com os dados de todas as lojas visíveis entre si.

Edite a senha no script antes de aplicar:

```bash
nano docker/postgres/init/01-app-role.sql   # troque 'secret'
sudo -u postgres psql -d tomenu -f docker/postgres/init/01-app-role.sql
```

Permita que os containers alcancem o Postgres do host:

```bash
# postgresql.conf: escutar em todas as interfaces. O ufw só libera a 22, então
# a 5432 continua inacessível pela internet — o alcance real é a rede do Docker.
sed -i "s/^#*listen_addresses.*/listen_addresses = '*'/" \
    /etc/postgresql/16/main/postgresql.conf

# pg_hba.conf: aceitar a faixa privada do Docker, com senha.
echo "host tomenu tomenu_app 172.16.0.0/12 scram-sha-256" \
    >> /etc/postgresql/16/main/pg_hba.conf
echo "host tomenu tomenu_owner 172.16.0.0/12 scram-sha-256" \
    >> /etc/postgresql/16/main/pg_hba.conf

systemctl restart postgresql
```

> `listen_addresses = '*'` em vez de um IP fixo porque o compose cria uma rede
> própria (`172.20.0.1` aqui, mas o número varia por projeto e por máquina) —
> fixar o gateway do bridge padrão, `172.17.0.1`, faria as migrations falharem
> com "connection refused".

> O firewall (ufw) só libera SSH, então a porta 5432 continua inacessível pela
> internet — as regras acima valem apenas para a rede interna do Docker.

---

## 4. Cloudflare: zona e túnel

O domínio precisa estar com os **nameservers do Cloudflare**; sem a zona ativa
lá, o túnel não cria os CNAMEs.

Em **Zero Trust → Networks → Tunnels → Create a tunnel** (tipo `cloudflared`),
copie o token e cadastre três *public hostnames*:

| Hostname | Serviço |
|---|---|
| `to-menu.com` | `http://storefront:3000` |
| `*.to-menu.com` | `http://storefront:3000` |
| `api.to-menu.com` | `http://nginx:80` |

O apex não cobre subdomínio: `*` precisa ser entrada própria. `api` já está na
lista `RESERVED` de `IdentifyTenant` e do `proxy.ts`, então não colide com slug
de loja.

Em **SSL/TLS → Overview**, use **Full**. "Full (strict)" exigiria um Origin
Certificate instalado no nginx, que hoje responde só HTTP interno.

### www → apex

Em **Rules → Redirect Rules**, crie:

- Se `hostname eq "www.to-menu.com"`
- Então redirect 301 para `concat("https://to-menu.com", http.request.uri.path)`

Sem isso a landing responde em duas URLs, o que divide ranking de SEO. `www` já
é reservado no código, então nunca vira loja.

---

## 5. `.env.prod`

```bash
cd /opt/tomenu
cp apps/api/.env.example .env.prod
chmod 600 .env.prod
nano .env.prod
```

Valores que **não podem ficar no padrão**:

```ini
APP_ENV=production
APP_DEBUG=false                      # true expõe stack trace com credenciais
APP_URL=https://api.to-menu.com

# Uma loja é sempre subdomínio de UM nível. Pôr "www.to-menu.com" aqui faria as
# lojas virarem pizzaria.www.to-menu.com — fora do wildcard, sem certificado.
TENANCY_ROOT_DOMAIN=to-menu.com
TENANCY_STOREFRONT_SCHEME=https
TENANCY_STOREFRONT_PORT=

# X-Tenant é escolhido pelo cliente: em true, qualquer visitante abre a loja que
# quiser. O .env.example traz true por causa do desenvolvimento sem wildcard DNS.
TENANCY_TRUST_HEADER=false

DB_CONNECTION=pgsql
DB_HOST=host.docker.internal          # o host do droplet, resolvido pelo Docker
DB_PORT=5432
DB_DATABASE=tomenu
DB_USERNAME=tomenu_app               # NÃO o owner: RLS depende disso
DB_PASSWORD=a_senha_do_01-app-role

REDIS_HOST=redis                     # nome do serviço no compose
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

# Congeladas no bundle durante o build da imagem: alterá-las exige rebuild do
# storefront, não apenas restart.
NEXT_PUBLIC_API_URL=https://api.to-menu.com
NEXT_PUBLIC_ROOT_DOMAIN=to-menu.com
NEXT_PUBLIC_ADMIN_URL=https://app.to-menu.com

CLOUDFLARE_TUNNEL_TOKEN=o_token_do_passo_4
```

Gere a `APP_KEY` (sem ela a criptografia do `whatsapp_token` não funciona):

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm api php artisan key:generate --show
```

Cole o valor em `APP_KEY=` no `.env.prod`.

Preencha também `CLOUDFLARE_R2_*` (imagens de produto e backups) e as chaves do
Stripe, se já tiver.

---

## 6. Subir

```bash
cd /opt/tomenu
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
```

Em droplet de 2 GB, o `next build` é o pico de memória. Se falhar por OOM:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod stop api worker scheduler
# rode o build de novo, depois `up -d`
```

Migrations e plano inicial:

```bash
C="docker compose -f docker-compose.prod.yml --env-file .env.prod"
$C run --rm api php artisan migrate --force
$C run --rm api php artisan db:seed --class=PlanSeeder --force
$C run --rm api php artisan config:cache
$C run --rm api php artisan route:cache
```

Verifique:

```bash
$C ps                    # todos os serviços up
$C logs cloudflared      # "Registered tunnel connection"
curl -s https://api.to-menu.com/up
```

---

## 7. Admin (Vite)

O admin é build estático e **não** está no compose. Publique como Static Site no
App Platform, Cloudflare Pages ou similar, em `app.to-menu.com`:

- Build: `npm ci && npm run build` em `apps/admin`, saída `dist`
- **Rewrite catch-all para `/index.html`** — sem isso o React Router dá 404 no
  refresh de qualquer rota
- A API precisa aceitar CORS da origem do admin (o proxy `/api` do Vite só
  existe em desenvolvimento)

---

## 8. Cache Rule do cardápio

**Sem este passo o storefront fica lento**: o Cloudflare não cacheia
`application/json` por padrão, então o `s-maxage=60` de `MenuController` é
ignorado e todo acesso atravessa até NYC.

Em **Rules → Cache Rules → Create rule**:

- Se `hostname` corresponde a `*.to-menu.com` **e** `URI Path` começa com `/api/`
- Então **Eligible for cache**, Edge TTL = **Use cache-control header**

Com a regra, uma loja com 500 acessos/min gera ~1 request por minuto à origem, e
o visitante lê do edge de São Paulo.

---

## 9. Backup do banco

O backup do droplet é snapshot de disco tirado com o Postgres rodando — restaura
a máquina, mas não garante consistência transacional. O `pg_dump` complementa e
guarda a cópia fora da DigitalOcean.

```bash
apt-get install -y awscli
cp /opt/tomenu/docker/droplet/backup-db.sh /usr/local/bin/tomenu-backup
chmod +x /usr/local/bin/tomenu-backup

/usr/local/bin/tomenu-backup          # teste antes de agendar

crontab -e
# 15 4 * * *  /usr/local/bin/tomenu-backup >> /var/log/tomenu-backup.log 2>&1
```

Retenção padrão de 30 dias (`RETENTION_DAYS`). Restauração:

```bash
pg_restore --host=127.0.0.1 --username=tomenu_owner --dbname=tomenu \
    --clean --no-owner tomenu-YYYYMMDDTHHMMSSZ.dump
```

> Teste a restauração pelo menos uma vez. Backup nunca verificado é backup que
> você descobre estar quebrado no pior momento possível.

---

## 10. Atualizações

```bash
cd /opt/tomenu
git pull
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build
docker compose -f docker-compose.prod.yml --env-file .env.prod run --rm api php artisan migrate --force
```

Mudou alguma `NEXT_PUBLIC_*`? O `--build` é obrigatório: elas entram no bundle
em tempo de build.

---

## Checklist final

- [ ] `TENANCY_TRUST_HEADER=false`
- [ ] `APP_DEBUG=false`
- [ ] `DB_USERNAME=tomenu_app` (não o owner) — RLS depende disso
- [ ] `01-app-role.sql` aplicado, com senha trocada
- [ ] Três hostnames no túnel; SSL/TLS em Full
- [ ] Redirect `www` → apex
- [ ] Cache Rule de `/api/*`
- [ ] `ufw status` mostra só a 22
- [ ] `chmod 600 .env.prod`
- [ ] Backup testado com `pg_restore`
