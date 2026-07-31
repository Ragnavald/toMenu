# Deploy — to-menu.com

Provisionamento de produção num droplet com IPv4 fixo, atrás do Cloudflare no
plano **Free**. Não usa Cloudflare Tunnel nem Zero Trust: o droplet tem IP
público estável, então o caminho direto resolve e não custa nada.

Arquitetura resultante:

```
                                    ┌─ to-menu.com    ─┐
navegador ──TLS──▶ Cloudflare ──TLS──▶ nginx:443 ──┤─ *.to-menu.com  ─┤──▶ storefront:3000
                    (proxy)                         └─ api.to-menu.com ──▶ php-fpm

storefront ──rede interna──▶ nginx:80        (render server-side, ~1ms)
```

O nginx termina o TLS com um **Origin Certificate** do Cloudflare — wildcard,
válido por 15 anos, sem ACME nem renovação para manter funcionando.

As portas 80/443 ficam abertas **apenas às faixas de IP do Cloudflare**; o
resto da internet não alcança a origem. Isso é o que impede alguém de bater no
IP do droplet e contornar WAF, cache e rate limit.

> **Custo:** o único item pago é o domínio. Cloudflare Free cobre DNS, CDN, TLS
> e wildcard de um nível — que é exatamente o que o roteamento por subdomínio
> de loja precisa.

---

## 1. Droplet

- **Imagem**: Ubuntu 24.04 LTS
- **Região**: NYC1 ou NYC3 (a DigitalOcean não tem região no Brasil; ~120ms,
  irrelevante para o cardápio, que é servido do edge — ver passo 9)
- **Tamanho**: 2 GB / 1 vCPU no mínimo; 4 GB se quiser folga no build do Next
- **Autenticação**: chave SSH (o cloud-init desativa senha; criar com senha
  deixaria você sem acesso)
- **Backups**: ativar, diário
- **User data**: cole o conteúdo de `docker/droplet/cloud-init.yaml`

Depois de criado, atribua um **Reserved IP** ao droplet (Networking → Reserved
IPs). O IP efêmero do droplet muda se a máquina for destruída e recriada, e os
registros `A` do passo 4 apontariam para o vazio; com o reservado, você reaponta
para uma máquina nova sem tocar no DNS.

O cloud-init instala Docker, cria 2 GB de swap, configura o firewall (SSH aberto,
80/443 restritas às faixas do Cloudflare), endurece o SSH e liga
`unattended-upgrades`. Leva 2–4 min após o droplet aparecer como ativo:

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
# postgresql.conf: escutar em todas as interfaces. O ufw não libera a 5432, que
# portanto continua inacessível pela internet — o alcance real é a rede do Docker.
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

> O firewall abre apenas SSH e 80/443 (estas só para o Cloudflare), então a
> 5432 continua inacessível pela internet — as regras acima valem apenas para a
> rede interna do Docker. Para acessar o banco da sua máquina, use túnel SSH:
> `ssh -L 5432:localhost:5432 root@SEU_IP`.

---

## 4. Cloudflare: DNS e certificado

O domínio precisa estar com os **nameservers do Cloudflare** (plano Free serve).

### 4.1 Registros DNS

Em **DNS → Records**, dois registros `A` apontando para o IP fixo do droplet,
ambos com o **proxy ligado** (nuvem laranja):

| Tipo | Nome | Conteúdo | Proxy |
|---|---|---|---|
| A | `@` | `SEU_IP_FIXO` | Proxied |
| A | `*` | `SEU_IP_FIXO` | Proxied |

O apex não cobre subdomínio: o `*` precisa ser registro próprio. Ele atende
tanto as lojas quanto `api`, que já está na lista `RESERVED` de `IdentifyTenant`
e do `proxy.ts` e portanto nunca colide com slug de loja.

> O proxy ligado não é detalhe estético: com a nuvem cinza, o IP do droplet fica
> exposto no DNS público e o tráfego não passa por TLS de borda, cache nem WAF.

### 4.2 Origin Certificate

Em **SSL/TLS → Origin Server → Create Certificate**, aceite o padrão (RSA 2048,
15 anos) e confirme que a lista de hostnames traz `to-menu.com` **e**
`*.to-menu.com`. A tela mostra o certificado e a chave uma única vez.

No droplet:

```bash
install -d -m 0700 /etc/tomenu/certs
nano /etc/tomenu/certs/origin.pem   # cole o "Origin Certificate"
nano /etc/tomenu/certs/origin.key   # cole a "Private Key"
chmod 600 /etc/tomenu/certs/origin.key
```

Os caminhos são os que o `docker-compose.prod.yml` monta no nginx. A chave fica
fora do repositório de propósito — nunca entra no git nem na imagem.

### 4.3 Modo SSL

Em **SSL/TLS → Overview**, use **Full (strict)**. Com o Origin Certificate
instalado é o modo correto: o Cloudflare valida o certificado da origem, o que
"Full" não faz. Ligue também **Always Use HTTPS**.

> "Flexible" quebra a stack: o Cloudflare falaria HTTP com o nginx, que
> responde 301 para https, e o resultado é loop de redirecionamento.

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

## 6. Firewall: só o Cloudflare alcança a origem

O `cloud-init.yaml` já configura isto num droplet novo. Esta seção existe para
conferir o resultado e para reaplicar quando as faixas do Cloudflare mudarem.

Sem este passo o IP do droplet responde a qualquer um, e todo o valor do
Cloudflare à frente (WAF, cache, rate limit, ocultação da origem) é contornável
por quem descobrir o IP — o que é trivial via histórico de DNS.

**O `ufw` sozinho não basta.** O Docker insere as próprias regras de DNAT antes
da cadeia do ufw, então uma porta publicada por container fica aberta ao mundo
mesmo com `ufw deny`. O filtro precisa estar na cadeia `DOCKER-USER`, que é
avaliada antes:

```bash
ufw status                       # 22 aberta; 80/443 só para faixas do Cloudflare
iptables -L DOCKER-USER -n       # primeira regra salta para TOMENU-CF
iptables -L TOMENU-CF -n | tail  # DROP para 80,443 no final
```

Quando o Cloudflare mudar as faixas (raro, mas acontece), reaplique:

```bash
curl -fsSL https://www.cloudflare.com/ips-v4 -o /etc/tomenu-cf-ips-v4
iptables -F TOMENU-CF
while read -r ip; do [ -n "$ip" ] && iptables -A TOMENU-CF -s "$ip" -j RETURN; done \
    < /etc/tomenu-cf-ips-v4
iptables -A TOMENU-CF -p tcp -m multiport --dports 80,443 -j DROP
iptables -A TOMENU-CF -j RETURN
netfilter-persistent save
```

> O `netfilter-persistent save` no fim não é opcional: sem ele as regras somem
> no próximo reboot e a origem fica exposta sem aviso.

---

## 7. Subir

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
$C ps                              # todos os serviços up
$C logs nginx --tail=20            # sem erro de certificado
curl -s https://api.to-menu.com/up # {"status":"ok"}
curl -sI https://to-menu.com | head -1
```

E confirme que a origem **não** responde direto pelo IP — este é o teste que
prova que o firewall está fazendo o trabalho:

```bash
curl -sk --max-time 5 https://SEU_IP_FIXO/ ; echo "exit=$?"
# esperado: exit=28 (timeout). Se responder HTML, a origem está exposta:
# reveja o passo 6.
```

---

## 8. Admin (Vite)

O admin é build estático e **não** está no compose. Publique como Static Site no
App Platform, Cloudflare Pages ou similar, em `app.to-menu.com`:

- Build: `npm ci && npm run build` em `apps/admin`, saída `dist`
- **Rewrite catch-all para `/index.html`** — sem isso o React Router dá 404 no
  refresh de qualquer rota
- A API precisa aceitar CORS da origem do admin (o proxy `/api` do Vite só
  existe em desenvolvimento)

---

## 9. Cache Rule do cardápio

**Sem este passo o storefront fica lento**: o Cloudflare não cacheia
`application/json` por padrão, então o `s-maxage=60` de `MenuController` é
ignorado e todo acesso atravessa até NYC.

Em **Rules → Cache Rules → Create rule**:

- Se `hostname` corresponde a `*.to-menu.com` **e** `URI Path` começa com `/api/`
- Então **Eligible for cache**, Edge TTL = **Use cache-control header**

Com a regra, uma loja com 500 acessos/min gera ~1 request por minuto à origem, e
o visitante lê do edge de São Paulo.

---

## 10. Backup do banco

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

## 11. Atualizações

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
- [ ] Registros `A` de `@` e `*` proxied (nuvem laranja), para o Reserved IP
- [ ] Origin Certificate em `/etc/tomenu/certs`, chave em `chmod 600`
- [ ] SSL/TLS em **Full (strict)** + Always Use HTTPS
- [ ] Redirect `www` → apex
- [ ] Cache Rule de `/api/*`
- [ ] `ufw status`: 22 aberta, 80/443 só para faixas do Cloudflare
- [ ] `iptables -L DOCKER-USER -n` salta para `TOMENU-CF` (o ufw sozinho não
      cobre porta publicada por container)
- [ ] `curl -k https://SEU_IP` **não** responde — origem fora do alcance direto
- [ ] `chmod 600 .env.prod`
- [ ] Backup testado com `pg_restore`
