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
cloud-init status --long
```

**Confira o `extended_status`, não só o `status`.** O user-data passa por um
textarea no painel da DO, onde a codificação não é garantida: se um acento ou
travessão dos comentários chegar corrompido, o parse do YAML falha e o
cloud-init descarta a configuração inteira — sem instalar nada. O droplet sobe
normalmente e o `status` diz `done`, então a falha só aparece muito depois, no
passo 5, como `Command 'docker' not found`.

O sintoma é este:

```
status: done
extended_status: degraded done
recoverable_errors:
    WARNING:
        - Failed loading yaml blob. unacceptable character #x0080: ...
        - Failed at merging in cloud config part from part-001: empty cloud config
```

`degraded done` com `empty cloud config` significa que **nada** rodou: nem
Docker, nem swap, nem firewall, nem hardening do SSH. Não dá para reexecutar o
user-data numa máquina já iniciada (`cloud-init single` lê o YAML corrompido do
datasource, não um arquivo local). Rode o script equivalente à mão:

```bash
# da sua máquina, com o repositório clonado:
ssh root@SEU_IP 'bash -s' < docker/droplet/provision.sh
```

O `provision.sh` é a cópia executável do `cloud-init.yaml` — mesmos passos, na
mesma ordem, idempotente. Mantenha os dois em sincronia ao mudar qualquer um.

Se o `extended_status` disser apenas `done`, o user-data foi aplicado e você
pode seguir direto para o passo 2.

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

Crie o banco e o papel dono:

```bash
sudo -u postgres psql <<'SQL'
CREATE DATABASE tomenu;
CREATE ROLE tomenu_owner WITH LOGIN PASSWORD 'TROQUE_ESTA_SENHA';
ALTER DATABASE tomenu OWNER TO tomenu_owner;
SQL
```

> **Sem `SUPERUSER`.** Nada no deploy precisa: as migrations rodam como
> `tomenu_app` e não criam extensões (e `pgcrypto`, se vier a ser preciso, é
> *trusted* no PG16 — dispensa superuser). Um papel superuser aqui ignora toda
> a RLS se alguém conectar com ele por engano, que é exatamente o modo de falha
> que a próxima seção existe para evitar.

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

Confira antes de seguir — nenhum papel pode ter `super` ou `bypassrls`, e o
schema `public` precisa pertencer a `tomenu_app`, senão o `migrate` do passo 7
falha com `permission denied for schema public`:

```bash
sudo -u postgres psql -d tomenu <<'SQL'
SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname LIKE 'tomenu%';
SELECT nspname, nspowner::regrole FROM pg_namespace WHERE nspname = 'public';
SQL
```

Permita que os containers alcancem o Postgres do host:

```bash
# postgresql.conf: escutar em todas as interfaces.
sed -i "s/^#*listen_addresses.*/listen_addresses = '*'/" \
    /etc/postgresql/16/main/postgresql.conf

# pg_hba.conf: aceitar a faixa privada do Docker, com senha.
echo "host tomenu tomenu_app 172.16.0.0/12 scram-sha-256" \
    >> /etc/postgresql/16/main/pg_hba.conf
echo "host tomenu tomenu_owner 172.16.0.0/12 scram-sha-256" \
    >> /etc/postgresql/16/main/pg_hba.conf

systemctl restart postgresql

# O ufw nega tudo que não casa com uma regra: sem esta, o container não alcança
# o Postgres do host e o `migrate` do passo 7 morre com "timeout expired". Só a
# faixa privada do Docker — a 5432 continua fechada para a internet.
ufw allow from 172.16.0.0/12 to any port 5432 proto tcp
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

# Alerta de pedido novo no painel. Com `log` (o padrão do Laravel) o evento é
# escrito no arquivo de log e a cozinha nunca é avisada.
#
# ATENÇÃO: este bloco (BROADCAST_CONNECTION + REVERB_*) precisa ser repetido em
# `apps/api/.env`. É de lá que o compose injeta o ambiente dos containers
# (`env_file` do âncora x-api); o `.env.prod` serve para interpolar ${...} no
# YAML e para o build do admin. Só aqui, o Reverb sobe sem credencial e
# reinicia em ciclo. VITE_REVERB_HOST, abaixo, é o oposto: só faz sentido aqui.
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=tomenu
REVERB_APP_KEY=                      # gere: openssl rand -hex 16
REVERB_APP_SECRET=                   # gere: openssl rand -hex 16
# Três endereços distintos. Confundi-los é a falha mais comum aqui, e ela é
# silenciosa: o job roda, o evento "sai", e nada chega ao painel.
REVERB_SERVER_HOST=0.0.0.0           # o que o processo ESCUTA no container
REVERB_SERVER_PORT=8080
REVERB_HOST=reverb                   # para onde o worker CONECTA (nome do serviço)
REVERB_PORT=8080
REVERB_SCHEME=http                   # interno ao compose; o TLS termina no nginx

# Host público do WebSocket, congelado no bundle do admin durante o build.
# REVERB_APP_KEY acima é reaproveitada como VITE_REVERB_APP_KEY pelo compose.
VITE_REVERB_HOST=ws.to-menu.com

# Congeladas no bundle durante o build da imagem: alterá-las exige rebuild do
# storefront, não apenas restart.
NEXT_PUBLIC_API_URL=https://api.to-menu.com
NEXT_PUBLIC_ROOT_DOMAIN=to-menu.com
NEXT_PUBLIC_ADMIN_URL=https://app.to-menu.com

# Sitekey do Turnstile (pública). Uma só cobre o cadastro no storefront e o
# login no admin; o build de cada frontend a consome. Ver passo 5.1.
TURNSTILE_SITE_KEY=0x4AAAAAAA...
```

Gere a `APP_KEY` (sem ela a criptografia de sessão e cookies não funciona):

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm api php artisan key:generate --show
```

Cole o valor em `APP_KEY=` no `.env.prod`.

Preencha também `CLOUDFLARE_R2_*` (imagens de produto e backups) e as chaves do
Stripe, se já tiver — ver passo 10 para o R2.

### 5.2 E-mail transacional (SMTP do Titan)

O app envia e-mail para a recuperação de senha do painel. O padrão do Laravel é
`MAIL_MAILER=log`, que **escreve a mensagem no arquivo de log em vez de
entregá-la** — o lojista pede a redefinição, a API responde 200, e o e-mail
nunca chega. É uma falha silenciosa; não há erro em lugar nenhum.

A caixa é Titan (contratada via HostGator), a mesma dos registros MX do passo
4.1. Em **`apps/api/.env`** (não no `.env.prod` — o Laravel lê estas em runtime,
ver o aviso acima):

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.titan.email
MAIL_PORT=465
# TLS implícito — a conexão já nasce criptografada, sem STARTTLS.
#
# Explícito por clareza, não por necessidade: o Laravel já assume `smtps`
# quando a porta é 465 (MailManager::createSmtpTransport). Deixar registrado
# evita que uma troca de porta para 587 mantenha silenciosamente um esquema
# que não corresponde mais.
MAIL_SCHEME=smtps
# Autenticação e remetente são coisas SEPARADAS, e aqui divergem de propósito.
#
# O login é sempre a caixa real — a que tem senha no painel do Titan. Os demais
# endereços do domínio (nao-responda@, contato@, privacidade@...) são aliases de
# redirecionamento: entregam o que chega, mas não têm senha e portanto não
# autenticam. Usar um deles em MAIL_USERNAME devolve `535 Authentication
# failed`, e a mensagem não sugere em nada que a causa é essa.
#
# Usuário é o endereço completo, não só a parte antes do @.
MAIL_USERNAME=suporte@to-menu.com
MAIL_PASSWORD=a_senha_da_caixa_suporte

# Já o From pode ser qualquer alias do MESMO domínio — o Titan aceita, e é o
# que o lojista enxerga. `nao-responda@` sinaliza que a mensagem é automática,
# sem perder resposta nenhuma: o alias redireciona de volta para suporte@.
MAIL_FROM_ADDRESS=nao-responda@to-menu.com
MAIL_FROM_NAME=ToMenu
```

> **A senha é a da caixa de e-mail**, criada no painel do Titan — não a senha da
> conta HostGator, e não a do painel administrativo. São credenciais distintas e
> a confusão entre elas é o segundo motivo mais comum de `535 Authentication
> failed`; o primeiro é usar um alias no `MAIL_USERNAME`, como explicado acima.

Alternativa: a porta **587** com `MAIL_SCHEME=tls` (STARTTLS) funciona igual e é
o caminho a tentar se a 465 estiver bloqueada na saída do droplet.

#### Os aliases do domínio

A conta tem uma caixa real, `suporte@`, e os demais endereços redirecionam para
ela:

| Endereço | Papel |
|---|---|
| `suporte@` | **Caixa real.** Autentica o SMTP e recebe tudo |
| `nao-responda@` | Remetente dos e-mails automáticos |
| `contato@`, `atendimento@` | Contato público (landing, rodapé) |
| `privacidade@` | Canal do titular de dados exigido pela LGPD |
| `financeiro@` | Cadastro em Stripe, DigitalOcean, Cloudflare |
| `abuse@` | Convenção RFC 2142 — provedores reportam problemas por aqui |

Como todos caem em `suporte@`, um endereço só precisa ser lido. O ganho é poder
separar depois — quando `suporte@` acumular volume demais, basta transformar um
alias em caixa própria, sem trocar nada que já foi publicado ou cadastrado.

#### Verificação

Não confie no `.env`: confirme que o **container** enxerga a variável e que a
entrega acontece de ponta a ponta.

```bash
cd /opt/tomenu

# 1. O container vê a configuração?
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    exec -T api printenv MAIL_MAILER MAIL_HOST MAIL_SCHEME

# 2. A porta está alcançável a partir do container? (bloqueio de saída aparece
#    aqui, antes de virar timeout confuso no envio)
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    exec -T api bash -c 'timeout 5 bash -c "</dev/tcp/smtp.titan.email/465" && echo ok'

# 3. Envio real. Troque o destinatário por um endereço que você leia.
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    exec -T api php artisan tinker --execute \
    'Mail::raw("Teste de SMTP do ToMenu.", fn($m) => $m->to("voce@gmail.com")->subject("Teste ToMenu"));'
```

O passo 3 deve chegar na **caixa de entrada**, não no spam. Se cair em spam,
o problema é DNS, não SMTP: confira SPF, DKIM e DMARC no passo 4.1 — domínio
sem DKIM é o motivo mais comum.

```bash
dig +short TXT to-menu.com | grep spf     # SPF
dig +short TXT _dmarc.to-menu.com         # DMARC
```

> Se o envio travar por ~30s e falhar com timeout, a saída na 465 está
> bloqueada: troque para a 587 com `MAIL_SCHEME=tls`. Provedores de VPS
> costumam restringir portas de e-mail para conter spam.

> **Dois arquivos de env, e a distinção importa.** O `--env-file .env.prod` dos
> comandos do compose alimenta apenas a *interpolação* do
> `docker-compose.prod.yml` (as `NEXT_PUBLIC_*` do build e o `APP_ENV_FILE`).
> Os containers em si leem o que estiver em `env_file:`, que por padrão é
> **`apps/api/.env`**.
>
> Na prática: variáveis lidas pelo Laravel em runtime (`DB_*`, `REDIS_*`,
> `CLOUDFLARE_R2_*`, `APP_KEY`) precisam estar em `apps/api/.env`; o script de
> backup e o build do storefront leem `.env.prod`. Manter os dois iguais é o
> caminho mais simples — editar só um e recriar o container leva a sintomas
> confusos, como upload indo para o lugar errado sem erro nenhum.
>
> ```bash
> docker compose -f docker-compose.prod.yml --env-file .env.prod \
>     exec -T api printenv CLOUDFLARE_R2_ENDPOINT   # o que o container VÊ
> ```

### 5.1 Turnstile: verificação de robô no login e no cadastro

Protege as duas rotas públicas onde um bot causa dano real: brute force de
senha em `/api/auth/login` e criação de lojas em massa em `/api/register`. O
rate limit por IP continua valendo em cima disto — trocar de IP é barato para
quem automatiza, então as duas defesas se somam.

No painel da Cloudflare, **Turnstile → Add widget**:

| Campo | Valor |
|---|---|
| Domains | `to-menu.com`, `app.to-menu.com` |
| Widget Mode | Managed |

Um widget só atende os dois frontends — daí uma única `TURNSTILE_SITE_KEY`.
Incluir os dois domínios é obrigatório: o widget recusa carregar em host fora
da lista, e o sintoma é o botão de entrar permanentemente desabilitado.

As duas chaves vão em arquivos diferentes, pelo motivo do quadro acima:

| Chave | Arquivo | Por quê |
|---|---|---|
| `TURNSTILE_SITE_KEY` (pública) | `.env.prod` | inlined no build dos frontends |
| `TURNSTILE_SECRET` | `apps/api/.env` | lida pelo Laravel em runtime |

> **Sem `TURNSTILE_SECRET` a API não exige o token.** É o que permite rodar em
> desenvolvimento e na suíte de testes sem chaves da Cloudflare, mas em
> produção significa login e cadastro sem verificação nenhuma — e sem erro
> visível, porque tudo continua funcionando. Confira depois do deploy:
>
> ```bash
> docker compose -f docker-compose.prod.yml --env-file .env.prod \
>     exec -T api php artisan tinker --execute \
>     "echo config('services.turnstile.secret') ? 'ligado' : 'DESLIGADO';"
> ```

Trocar a sitekey exige **rebuild** dos dois frontends, não só restart. A
verificação falha aberta se o siteverify da Cloudflare estiver inacessível: uma
indisponibilidade lá não pode derrubar o login da plataforma inteira.

---

## 6. Firewall: só o Cloudflare alcança a origem

O `cloud-init.yaml` já configura isto num droplet novo. Esta seção existe para
conferir o resultado, para aplicar à mão quando o cloud-init não rodou (passo 1)
e para reaplicar quando as faixas do Cloudflare mudarem.

Sem este passo o IP do droplet responde a qualquer um, e todo o valor do
Cloudflare à frente (WAF, cache, rate limit, ocultação da origem) é contornável
por quem descobrir o IP — o que é trivial via histórico de DNS.

**Confira antes do passo 7.** O `up -d` publica 80/443; a partir daí a origem
fica exposta até o filtro existir. O `run --rm` do passo 5 não publica porta,
então aquele pode rodar antes.

**O `ufw` sozinho não basta.** O Docker insere as próprias regras de DNAT antes
da cadeia do ufw, então uma porta publicada por container fica aberta ao mundo
mesmo com `ufw deny`. O filtro precisa estar na cadeia `DOCKER-USER`, que é
avaliada antes:

```bash
ufw status                       # 22 aberta; 80/443 só para faixas do Cloudflare
iptables -L DOCKER-USER -n       # primeira regra salta para TOMENU-CF
iptables -L TOMENU-CF -n | tail  # DROP para 80,443 no final
```

Se sair `Status: inactive`, `DOCKER-USER` vazia ou `No chain/target/match by
that name` na `TOMENU-CF`, nada está protegido — é o estado de um droplet cujo
cloud-init falhou. O `provision.sh` do passo 1 resolve junto com o resto; para
aplicar **só** o firewall, no droplet:

```bash
curl -fsSL https://www.cloudflare.com/ips-v4 -o /etc/tomenu-cf-ips-v4
curl -fsSL https://www.cloudflare.com/ips-v6 -o /etc/tomenu-cf-ips-v6
# Uma lista vazia não adicionaria regra nenhuma, e o `ufw enable` seguinte daria
# falsa sensação de proteção.
[ -s /etc/tomenu-cf-ips-v4 ] || { echo "lista do Cloudflare vazia"; exit 1; }

# O `allow 22` vem antes do `enable`: sua sessão SSH não cai.
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
while read -r ip; do [ -n "$ip" ] && ufw allow from "$ip" to any port 80,443 proto tcp; done \
    < /etc/tomenu-cf-ips-v4
while read -r ip; do [ -n "$ip" ] && ufw allow from "$ip" to any port 80,443 proto tcp; done \
    < /etc/tomenu-cf-ips-v6
ufw --force enable

# A DOCKER-USER vê os DOIS sentidos do forwarding, então o DROP precisa do
# `-i eth0`: sem ele, um container abrindo conexão para qualquer HTTPS externo
# casa com `--dports 443` e é bloqueado.
iptables -N TOMENU-CF 2>/dev/null || iptables -F TOMENU-CF
while read -r ip; do [ -n "$ip" ] && iptables -A TOMENU-CF -s "$ip" -j RETURN; done \
    < /etc/tomenu-cf-ips-v4
iptables -A TOMENU-CF -i eth0 -p tcp -m multiport --dports 80,443 -j DROP
iptables -A TOMENU-CF -j RETURN
iptables -I DOCKER-USER 1 -j TOMENU-CF

ip6tables -N TOMENU-CF 2>/dev/null || ip6tables -F TOMENU-CF
while read -r ip; do [ -n "$ip" ] && ip6tables -A TOMENU-CF -s "$ip" -j RETURN; done \
    < /etc/tomenu-cf-ips-v6
ip6tables -A TOMENU-CF -i eth0 -p tcp -m multiport --dports 80,443 -j DROP
ip6tables -A TOMENU-CF -j RETURN
ip6tables -I DOCKER-USER 1 -j TOMENU-CF

```

A cadeia `DOCKER-USER` é criada pelo daemon do Docker: instale o Docker antes,
ou o `iptables -I DOCKER-USER` falha com `No chain/target/match by that name`.

As regras acima vivem só em memória e somem no reboot. Quem as recria é o
serviço `tomenu-cf-rules`, instalado pelo `cloud-init.yaml` / `provision.sh`:

```bash
systemctl is-enabled tomenu-cf-rules   # enabled
systemctl restart tomenu-cf-rules      # reaplica sem reboot
```

> **Não instale `iptables-persistent` para isso.** O pacote conflita com o
> `ufw` e o apt **remove o ufw** ao instalá-lo — o `INPUT` volta para a policy
> `ACCEPT` e todas as portas do host ficam abertas, sem nenhum aviso. O `ufw`
> persiste as regras dele sozinho; o serviço acima cuida só da cadeia dos
> containers, que o `ufw` não gerencia.

> **Sem o `-i eth0` no DROP, o build quebra.** A regra vale para os dois
> sentidos do forwarding, então o `npm ci` do storefront falha com `ETIMEDOUT`
> em todo pacote — e o erro que o npm imprime (`Exit handler never called!`)
> não sugere firewall em momento nenhum. Se um build começar a falhar por
> timeout de rede, confira o contador da regra: `iptables -L TOMENU-CF -n -v`.

Quando o Cloudflare mudar as faixas (raro, mas acontece), atualize a lista e
reinicie o serviço — ele relê o arquivo e reconstrói a cadeia:

```bash
curl -fsSL https://www.cloudflare.com/ips-v4 -o /etc/tomenu-cf-ips-v4
curl -fsSL https://www.cloudflare.com/ips-v6 -o /etc/tomenu-cf-ips-v6
systemctl restart tomenu-cf-rules
iptables -S TOMENU-CF | wc -l          # confira que a contagem bateu
```

O `ufw` tem a própria cópia das faixas, então atualize os dois lados:

```bash
ufw status numbered | grep "80,443"    # veja o que existe hoje
# remova as regras antigas e recrie com a lista nova (ver bloco acima)
```

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

O admin é um build estático (Vite + React) servido pelo **próprio nginx** do
droplet em `app.to-menu.com`. Não exige serviço externo, conta em CDN nem
registro DNS novo: o wildcard `*.to-menu.com` do passo 4 já resolve, e o Origin
Certificate já cobre o subdomínio.

Está tudo no compose — `docker compose up -d --build` do passo 7 já o publica.
As peças:

| Arquivo | Papel |
|---|---|
| `docker/admin/Dockerfile.prod` | builda o `dist/` num container Node |
| serviço `admin-build` | copia o `dist/` para o volume `admin` e encerra |
| bloco `app.to-menu.com` em `docker/nginx/default.conf` | serve o volume |

Para publicar só o admin, sem mexer no resto:

```bash
cd /opt/tomenu
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build admin-build
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d nginx
```

O `admin-build` roda uma vez e sai com `Exited (0)` — é o esperado, não é falha.
O nginx depende dele com `service_completed_successfully`, então um build que
falhar impede o nginx de subir com um `dist` vazio.

### Por que `/api` também é servido em `app.to-menu.com`

O bloco do admin encaminha `/api` para o mesmo php-fpm de `api.to-menu.com`.
Isso coloca admin e API na **mesma origem**, o que elimina duas coisas de uma
vez: o navegador não faz preflight (sem CORS a configurar) e o `VITE_API_URL`
pode ficar vazio, porque o caminho relativo `/api/...` já resolve.

Se um dia o admin sair do droplet (Cloudflare Pages, App Platform), aí sim:

- `VITE_API_URL=https://api.to-menu.com` obrigatório no build — sem ele o admin
  chamaria `/api` na própria origem, que não seria mais a API, e o login
  falharia na primeira tentativa;
- a API precisa aceitar CORS daquela origem;
- e `app.to-menu.com` precisa de um registro DNS próprio, senão continua caindo
  no droplet pelo wildcard.

Como toda `VITE_*`, a URL é inlined no bundle em tempo de build: trocá-la exige
rebuild, não basta reconfigurar o host.

### Verificação

```bash
curl -s https://app.to-menu.com/ | grep -o '<title>[^<]*</title>'   # <title>admin</title>
curl -s -o /dev/null -w '%{http_code}\n' https://app.to-menu.com/produtos   # 200, não 404
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
    -H 'Content-Type: application/json' -d '{}' \
    https://app.to-menu.com/api/auth/login                          # 422 (API respondeu)
```

O 200 em `/produtos` confirma o rewrite catch-all: sem ele o React Router dá 404
em qualquer F5 fora da raiz. Se o `<title>` vier `ToMenu — Seu cardápio
digital`, o nginx está servindo o storefront — o bloco `app.to-menu.com` não foi
carregado.

---

## 8.1. Painel da plataforma (`admin.to-menu.com`)

Onde a equipe gerencia as lojas já criadas: acessar como admin, suspender,
reativar e excluir permanentemente.

É o **mesmo build** do admin — nenhum serviço, imagem ou passo de deploy novo.
O `App.tsx` escolhe entre o painel do lojista e o da plataforma pelo prefixo
`admin.` do hostname, e o `docker/nginx/default.conf` tem o server block que faz
esse host existir. Sem DNS novo: o wildcard `*.to-menu.com` do passo 4 já
resolve, e o Origin Certificate já cobre.

> `admin` é subdomínio reservado em `IdentifyTenant::RESERVED` e em
> `TenantRegistrar::RESERVED_SLUGS`, então nenhuma loja pode registrá-lo — o
> host não colide com um slug legítimo.

### Criar a conta de acesso

Não há cadastro público, e isso é deliberado: a conta administra todas as lojas.
O caminho é sempre o console do servidor, que exige acesso ao droplet.

```bash
cd /opt/tomenu
make platform-admin EMAIL=voce@to-menu.com NAME="Seu Nome"
```

A senha é pedida sem eco (mínimo de 12 caracteres); `--password` existe para
automação, mas deixa a senha no histórico do shell. Rodar de novo com o mesmo
e-mail troca a senha.

O e-mail **não pode** ser o de um usuário de loja: uma constraint `CHECK` no
banco impede que a mesma conta seja lojista e staff da plataforma.

### O que cada ação faz

| Ação | Efeito | Reversível |
|---|---|---|
| Acessar como admin | Token de 30 min do dono da loja, aberto em outra aba | expira sozinho |
| Suspender | Storefront responde 403; nada é apagado | sim, pelo botão Reativar |
| Excluir permanentemente | `DELETE` no tenant; cascata leva pedidos, produtos, usuários e pagamentos; imagens do R2 e cache também | **não** — só restore de backup |

A exclusão permanente exige a senha do staff **e** o slug digitado, e apaga o
histórico fiscal de pedidos junto. Quando o histórico precisar ser preservado,
suspenda em vez de excluir.

### Auditoria

Toda ação vai para `platform_audit_logs`, que sobrevive à purga da loja — é o
único registro que resta do que existia ali. A tabela é append-only no banco
(RLS com `FORCE` e sem policy de `UPDATE`/`DELETE`), então nem a aplicação
reescreve a trilha.

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm api php artisan tinker --execute='
        App\Models\PlatformAuditLog::latest()->limit(20)->get(
            ["created_at","action","actor_email","tenant_slug"]
        )->each(fn($l) => print("$l->created_at  $l->action  $l->actor_email  $l->tenant_slug\n"));'
```

### Verificação

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://admin.to-menu.com/           # 200
curl -s -o /dev/null -w '%{http_code}\n' https://admin.to-menu.com/api/platform/stores
# 401 — a rota existe e exige autenticação. Um 404 aqui significa que o grupo
# de rotas foi registrado DEPOIS do prefixo curinga {tenant} em routes/api.php,
# e "platform" está sendo lido como slug de loja.
```

---

## 9. Cache Rule do cardápio

**Sem este passo o storefront fica lento**: o Cloudflare não cacheia HTML por
padrão, então todo acesso ao cardápio atravessa até NYC.

O que se cacheia aqui é o **HTML da página da loja**, não o JSON da API. O
`/api/menu` é chamado apenas pelo render server-side, pela rede interna do
Docker (`INTERNAL_API_URL`) — nunca passa pelo Cloudflare, e uma regra sobre
`/api/*` não teria efeito nenhum sobre o cardápio.

Em **Rules → Cache Rules → Create rule**:

- **Custom filter expression** (não "All incoming requests")
- Em **Edit expression**, cole:

  ```
  (http.host wildcard "*.to-menu.com"
   and not http.host in {"www.to-menu.com" "app.to-menu.com" "api.to-menu.com" "ws.to-menu.com"})
  ```

  `ws.to-menu.com` precisa estar na exclusão: marcar o WebSocket como
  "eligible for cache" faz o Cloudflare tratar o handshake como resposta
  cacheável, e a conexão do painel passa a falhar de forma intermitente —
  sintoma difícil de rastrear, porque depende de qual edge atendeu.

- **Cache eligibility**: `Eligible for cache`
- **Edge TTL**: `Use cache-control header` — a origem já envia
  `s-maxage=30, stale-while-revalidate=300`; fixar um TTL aqui desalinharia as
  camadas e, pior, tornaria o purge automático do passo 9.1 menos eficaz.
- **Browser TTL**: escolha `Respect origin TTL`. **Não deixe em branco** — o
  padrão do Cloudflare é 4 horas, e ele sobrescreve o `max-age=0` que a origem
  envia. O resultado é o navegador do cliente guardando o cardápio por 4h, e o
  *Purge Cache* **não alcança cache de navegador**: um preço corrigido
  continuaria errado para quem já visitou. Pior no 404 — quem tentar o
  subdomínio de uma loja antes dela ser cadastrada veria "Loja não encontrada"
  por 4h.

  O `max-age=0` da origem é deliberado: o navegador revalida sempre, e quem
  absorve a carga é o edge, onde o purge funciona.

O apex fica de fora da expressão porque o `wildcard` não casa `to-menu.com` sem
subdomínio; `www`, `app` e `api` precisam ser excluídos explicitamente — a
landing tem formulário de cadastro e o admin é sessão pura.

Com a regra, uma loja com 500 acessos/min gera ~1 request por minuto à origem, e
o visitante lê do edge de São Paulo.

### As três camadas

Cacheamento do cardápio acontece em três lugares:

| Camada | Onde | Efeito |
|---|---|---|
| ISR do Next | `revalidate` em `app/[tenant]/page.tsx` | o servidor não refaz o render |
| `s-maxage` | `MenuController` (JSON) e o `map` do nginx (HTML) | o edge e o Data Cache não refazem o fetch |
| Edge TTL | esta Cache Rule | o visitante não atravessa até NYC |

O nginx sobrescreve o `Cache-Control` da resposta HTML (`map
$storefront_cacheable` em `docker/nginx/default.conf`). É necessário porque o
Next marca a página como `no-store`: o `proxy.ts` reescreve por request, então
do ponto de vista dele a rota é dinâmica — mesmo o conteúdo sendo idêntico para
todo visitante da loja.

**Os TTLs não são mais o que controla a atualização do cardápio.** Eles eram, e
o efeito era ruim: 60s de ISR e 60s de edge são camadas independentes que se
somam no pior caso, então o lojista salvava um preço e podia esperar ~2min para
vê-lo no site — tempo suficiente para concluir que o sistema tinha perdido a
edição. Hoje quem invalida é o `PurgeMenuCache` (passo 9.1), no instante da
edição, e os TTLs ficaram curtos apenas como rede de proteção para o caso de um
purge se perder.

### 9.1 Purge automático do cardápio

Quando algo do cardápio muda, o observer `InvalidatesMenuCache` enfileira o job
`PurgeMenuCache`, que faz duas chamadas independentes:

1. `POST /api/revalidate` no storefront, pela rede interna, expirando a tag
   `menu:{slug}` no Data Cache do Next;
2. `POST .../purge_cache` na API do Cloudflare, purgando o **host** da loja.

O purge é por host, e não `purge_everything`, porque a zona é compartilhada por
todas as lojas: esvaziá-la inteira a cada edição penalizaria todo mundo.

Para ligar, no `.env.prod`:

```bash
STOREFRONT_INTERNAL_URL=http://storefront:3000
REVALIDATE_SECRET=$(openssl rand -hex 32)   # o mesmo valor vai ao storefront
CLOUDFLARE_ZONE_ID=...                      # Overview da zona, coluna direita
CLOUDFLARE_API_TOKEN=...                    # permissão: Zone → Cache Purge
```

O `REVALIDATE_SECRET` precisa ser idêntico nos dois serviços — é o que autentica
a chamada. Sem ele a rota responde 503; com valor errado, 401. O compose já
repassa a variável ao storefront.

Tudo é opcional e degrada em silêncio: sem as variáveis do Cloudflare só o Next
é invalidado, e o visitante ainda espera o `s-maxage`. Sem nenhuma delas, o
sistema volta ao comportamento antigo, por TTL. Falha de purge vira `warning` no
log, nunca erro para o lojista que salvou.

**Como conferir que está funcionando** (troque o slug):

```bash
docker compose -f docker-compose.prod.yml exec api \
  php artisan tinker --execute="app(\App\Services\MenuCachePurger::class)->purge(
    \App\Models\Tenant::where('slug','SUA-LOJA')->first());"

docker compose -f docker-compose.prod.yml logs api --tail=20 | grep -i purge
```

Sem linhas de `warning`, os dois purges passaram. Na prática: edite um preço no
admin e recarregue a loja — a alteração aparece em poucos segundos.

### Verificação

```bash
# Loja: cacheável
curl -sI https://SUA-LOJA.to-menu.com/ | grep -i 'cache-control\|cf-cache-status'
# esperado: public, max-age=0, s-maxage=30, ... e HIT no segundo acesso

# Landing e admin: precisam continuar DYNAMIC
curl -sI https://to-menu.com/     | grep -i cf-cache-status
curl -sI https://app.to-menu.com/ | grep -i cf-cache-status
```

O primeiro acesso após a regra vem `MISS`; o segundo precisa vir `HIT`. Se
continuar `DYNAMIC`, a expressão não está casando o host.

Confira o `max-age` na resposta: se vier `max-age=14400`, o Browser TTL ficou no
padrão de 4h em vez de `Respect origin TTL` — corrija na regra, senão o cache do
navegador fica fora do alcance do purge.

---

## 10. Backup do banco

O backup do droplet é snapshot de disco tirado com o Postgres rodando — restaura
a máquina, mas não garante consistência transacional. O `pg_dump` complementa e
guarda a cópia fora da DigitalOcean.

O `awscli` **não** está mais no repositório do Ubuntu 24.04 (`Package 'awscli'
has no installation candidate`). Use o instalador oficial:

```bash
apt-get install -y unzip
curl -fsSL "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o /tmp/awscliv2.zip
unzip -q /tmp/awscliv2.zip -d /tmp && /tmp/aws/install
rm -rf /tmp/aws /tmp/awscliv2.zip
aws --version
```

Depois instale o script e agende:

```bash
cp /opt/tomenu/docker/droplet/backup-db.sh /usr/local/bin/tomenu-backup
chmod +x /usr/local/bin/tomenu-backup

/usr/local/bin/tomenu-backup          # teste antes de agendar

crontab -e
# 15 4 * * *  /usr/local/bin/tomenu-backup >> /var/log/tomenu-backup.log 2>&1
```

### Credenciais do R2

No painel do Cloudflare, **R2 Object Storage**:

1. **Create bucket** — o nome vai em `CLOUDFLARE_R2_BUCKET`.
2. **Manage R2 API Tokens → Create API Token**, permissão `Object Read & Write`
   escopada ao bucket. O *Secret Access Key* aparece **uma única vez**.
3. **Settings → Public access → Connect Domain**: use um domínio próprio
   (`cdn.to-menu.com`), **não** a *Public Development URL* — aquela tem rate
   limit e não passa pelo cache do CDN. O valor vai em `CLOUDFLARE_R2_URL`.

Preencha nos **dois** arquivos (`.env.prod` e `apps/api/.env` — ver a nota do
passo 5); sem as credenciais o script aborta com `preencha as credenciais do R2
no .env.prod`:

```ini
CLOUDFLARE_R2_BUCKET=to-menu
CLOUDFLARE_R2_ENDPOINT=https://SEU_ACCOUNT_ID.r2.cloudflarestorage.com
CLOUDFLARE_R2_ACCESS_KEY_ID=...
CLOUDFLARE_R2_SECRET_ACCESS_KEY=...
CLOUDFLARE_R2_URL=https://cdn.to-menu.com
```

> **O endpoint não leva o nome do bucket.** O painel mostra
> `https://<account>.r2.cloudflarestorage.com/to-menu`, mas tanto o SDK do
> Laravel quanto o `aws s3` concatenam o bucket sozinhos — deixar o sufixo faz
> os arquivos irem para `to-menu/to-menu/...` e as imagens dão 404 no CDN, sem
> erro nenhum no upload.

Depois de preencher, recrie os containers (as variáveis são lidas na subida) e
confira o upload:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    up -d --force-recreate api worker scheduler
```

O `ImageStorage` passa a usar o R2 automaticamente assim que a chave existe —
sem ela, as imagens ficam no disco do container e **somem no próximo deploy**.

> **O dump roda como `postgres`, não como `DB_USERNAME`.** As tabelas pertencem
> a `tomenu_app`, que tem `FORCE ROW LEVEL SECURITY`: um `pg_dump` com esse
> papel falha com `query would be affected by row-level security policy` — e,
> num contexto onde `app.tenant_id` estivesse definido, geraria um dump
> silenciosamente incompleto, com as linhas de um único tenant. Superuser
> ignora RLS, que é o comportamento que um backup precisa.
>
> O script também verifica a contagem de tabelas antes de enviar: um dump vazio
> só seria descoberto na hora do restore.

Retenção padrão de 30 dias (`RETENTION_DAYS`).

### Restauração

```bash
# Num banco novo, para conferir sem tocar em produção:
sudo -u postgres psql -c 'CREATE DATABASE restore_test OWNER tomenu_app;'
sudo -u postgres pg_restore --dbname=restore_test --no-owner tomenu-YYYYMMDDTHHMMSSZ.dump

# Sobre o banco de produção (destrutivo):
sudo -u postgres pg_restore --dbname=tomenu --clean --no-owner tomenu-....dump
```

Depois de restaurar, **confirme que a RLS voltou** — um restore que a perdesse
reabriria o isolamento entre lojas sem nenhum erro visível:

```bash
sudo -u postgres psql -d restore_test -c \
  "SELECT relname, relrowsecurity, relforcerowsecurity FROM pg_class
   WHERE relname IN ('products','orders');"
# esperado: t | t nas duas
```

> Teste a restauração pelo menos uma vez. Backup nunca verificado é backup que
> você descobre estar quebrado no pior momento possível.

---

## 11. Atualizações

```bash
cd /opt/tomenu
git pull
make deploy
```

> **Droplet provisionado antes de agosto/2026:** `make` passou a ser instalado
> pelo provisionamento só depois, e a imagem Ubuntu Server não o traz por
> padrão. Uma vez, no servidor: `apt-get install -y make`. Sem isso o comando
> acima falha com `make: command not found` — os `docker compose` explícitos
> abaixo continuam válidos.

O `make deploy` encadeia os quatro passos abaixo na ordem correta — é o caminho
recomendado, porque a ordem entre eles não é intercambiável (ver adiante):

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod run --rm api php artisan migrate --force
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build

# NÃO OPCIONAL: ver abaixo.
docker compose -f docker-compose.prod.yml --env-file .env.prod restart nginx

# Limpa qualquer resposta ruim que o edge tenha guardado durante a janela.
/usr/local/bin/tomenu-purge
```

O `migrate` vem **antes** do `up --build` quando o deploy traz migration nova: o
código que sobe consulta colunas que ainda não existiriam, e a janela entre um
passo e outro seria de 500 em toda requisição que tocasse o schema novo.

`git pull` fica fora do `make` de propósito — qual commit vai para produção é
decisão de quem faz o deploy, não do alvo.

Outros atalhos, todos rodando de `/opt/tomenu`:

| Comando | O quê |
|---|---|
| `make deploy` | deploy completo (migrate → build → nginx → purge) |
| `make deploy-migrate` | só as migrations |
| `make deploy-admin` | republica só os painéis do Vite |
| `make platform-admin EMAIL=… NAME="…"` | cria/atualiza conta de staff |
| `make prod-ps` / `make prod-logs` | estado e logs da stack |

Mudou alguma `NEXT_PUBLIC_*`? O `--build` é obrigatório: elas entram no bundle
em tempo de build.

O `up -d --build` cria serviços novos do compose sozinho — é assim que o
`reports-worker` (fila de PDFs do financeiro) sobe na primeira atualização
depois que ele foi adicionado. Já uma extensão nova do PHP, como a `gd` que o
dompdf exige, só entra rebuildando a imagem; se o deploy reaproveitar camada
antiga em cache, force com:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod build --no-cache api
```

> **O `restart nginx` no fim não é zelo — sem ele o site cai.** O nginx resolve
> `api` e `storefront` para IPs uma única vez, na inicialização, e mantém o
> cache. O `up -d --build` recria os containers com IPs novos, e o nginx segue
> tentando os antigos:
>
> ```
> connect() failed (113: Host is unreachable) ... upstream: "fastcgi://172.18.0.8:9000"
> connect() failed (111: Connection refused) ... upstream: "http://172.18.0.3:3000/"
> ```
>
> O sintoma é 502 em tudo, enquanto `docker compose ps` mostra todos os
> serviços `Up` e saudáveis — o que faz procurar o problema no lugar errado.

### Erro de deploy não fica preso no edge

O nginx envia `no-store` quando a resposta é 500/502/503/504 (o `map $status`
em `docker/nginx/default.conf`), então o Cloudflare não guarda página de erro:
assim que a origem volta, o próximo acesso já é 200, sem purge manual.

Sem esse tratamento, um 502 de poucos segundos durante o deploy ficaria servido
pelo edge por todo o `s-maxage` — visitantes veriam erro com o site já no ar.

O 404 continua cacheável de propósito: é resposta legítima de loja inexistente,
e cacheá-la protege a origem de quem enumera slugs. A contrapartida é que uma
loja recém-criada pode levar até 30s para aparecer; se precisar antes, use
**Purge Cache** no painel. (O purge automático do passo 9.1 cobre edições de
cardápio, não o 404 de um subdomínio que ainda não existia.)

Para distinguir cache do edge de problema na origem:

```bash
curl -s -o /dev/null -w 'normal: %{http_code}\n' https://SUA-LOJA.to-menu.com/
curl -s -o /dev/null -w 'bypass: %{http_code}\n' "https://SUA-LOJA.to-menu.com/?cb=$(date +%s)"
```

Divergência entre os dois significa cache; iguais, o problema é na origem.

### Purge automático

Duas camadas, além do `no-store` do nginx:

| Quando | O quê |
|---|---|
| Fim de todo deploy | `tomenu-purge` limpa a zona inteira |
| A cada 2 min | `tomenu-watch` compara edge × origem e purga se divergirem |

O `tomenu-watch` só purga quando o edge serve erro **e** a origem está
saudável. Com a origem fora ele apenas registra no log: purgar durante um
incidente não corrige nada e ainda joga todo o tráfego na origem já em
dificuldade. Há um cooldown de 10 min para que uma origem intermitente não vire
um laço de purge que destrói o cache inteiro.

Ambos precisam de credenciais no `.env.prod` — sem elas o purge é pulado com
aviso, sem quebrar o deploy:

```ini
CLOUDFLARE_ZONE_ID=...      # Overview da zona, coluna da direita
CLOUDFLARE_API_TOKEN=...    # token com permissão Zone > Cache Purge
```

Instalação:

```bash
cp /opt/tomenu/docker/droplet/purge-cache.sh  /usr/local/bin/tomenu-purge
cp /opt/tomenu/docker/droplet/watch-errors.sh /usr/local/bin/tomenu-watch
chmod +x /usr/local/bin/tomenu-purge /usr/local/bin/tomenu-watch

crontab -e
# */2 * * * *  /usr/local/bin/tomenu-watch >> /var/log/tomenu-watch.log 2>&1
```

Purge de URLs específicas, quando souber o que está ruim (preserva o resto do
cache):

```bash
tomenu-purge https://forno-di-napoli.to-menu.com/
```

### Verificação pós-deploy

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod ps
curl -s -o /dev/null -w 'loja:  %{http_code}\n' https://SUA-LOJA.to-menu.com/
curl -s -o /dev/null -w 'apex:  %{http_code}\n' https://to-menu.com/
curl -s -o /dev/null -w 'admin: %{http_code}\n' https://app.to-menu.com/
curl -s -o /dev/null -w 'api:   %{http_code}\n' https://api.to-menu.com/api/admin/products
```

Esperado: `200`, `200`, `200`, `401` — o 401 da API confirma que ela responde e
exige autenticação.

O `ps` deve listar **dois** workers: `worker` (fila `default`) e
`reports-worker` (fila `reports`). Se o segundo não aparecer, o compose do
droplet está desatualizado — os PDFs do financeiro ficam presos na fila sem
erro visível, porque ninguém consome aquela fila.

```bash
# A extensão gd precisa existir na imagem, senão o logo some do PDF.
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm api php -m | grep -x gd
```

---

## Problemas conhecidos

Erros cujo sintoma não aponta para a causa. Todos já aconteceram num deploy
real deste projeto.

### `Command 'docker' not found` num droplet recém-criado

O cloud-init não rodou. Confirme com `cloud-init status --long`: se o
`extended_status` for `degraded done` com `Failed loading yaml blob`, o
user-data chegou corrompido pelo painel e **nada** foi provisionado — nem
Docker, nem swap, nem firewall, nem hardening do SSH. Ver passo 1.

Não adianta `apt install docker.io`: a versão do Ubuntu não traz o plugin
`docker compose` (v2), e todo comando `docker compose` deste guia falharia.

### `npm ci` falha com `Exit handler never called!`

Firewall, não npm. Rode com log completo para ver o erro real:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    build --progress=plain storefront 2>&1 | tail -60
```

Se aparecer `ETIMEDOUT` em todo pacote, o DROP da `TOMENU-CF` está sem
`-i eth0` e bloqueia a saída dos containers — a `DOCKER-USER` é avaliada nos
dois sentidos do forwarding. Confirme pelo contador:

```bash
iptables -L TOMENU-CF -n -v | grep DROP    # pkts > 0 e subindo
iptables -S TOMENU-CF | grep DROP          # precisa ter -i eth0
```

Correção no passo 6. O erro do npm não menciona rede em nenhum momento, e o
build morre com exit code 1 — não 137 —, então **não é OOM**.

### `ufw: command not found` depois de configurar o firewall

O `iptables-persistent` foi instalado e o apt removeu o `ufw` (os dois
conflitam). O resultado é pior que o estado inicial: `INPUT` volta para a
policy `ACCEPT` e todas as portas do host ficam abertas, sem aviso.

```bash
iptables -S INPUT | head -1     # -P INPUT ACCEPT = host desprotegido
apt-get install -y ufw          # reinstala; iptables-persistent sai
```

Depois reaplique as regras do passo 6 e confirme que a `TOMENU-CF` sobreviveu
(`iptables -S DOCKER-USER`). A persistência correta é o serviço
`tomenu-cf-rules`, não o `iptables-persistent`.

### Build do storefront morre sem mensagem (exit 137)

Aí sim é OOM. `swapon --show` vazio num droplet de 2 GB:

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

### `migrate` falha com `timeout expired` em `host.docker.internal`

```
SQLSTATE[08006] [7] connection to server at "host.docker.internal"
(172.17.0.1), port 5432 failed: timeout expired
```

O `ufw` está bloqueando. O Postgres pode estar perfeitamente configurado
(`listen_addresses = '*'`, `pg_hba.conf` com a faixa do Docker) e ainda assim
o container não passa, porque o firewall nega antes:

```bash
ufw status | grep 5432       # nenhuma linha = é isso
ufw allow from 172.16.0.0/12 to any port 5432 proto tcp
```

Note que o container resolve `host.docker.internal` para `172.17.0.1` (bridge
padrão) mesmo estando numa rede própria do compose (`172.18.x`) — por isso a
regra cobre `172.16.0.0/12` inteira, e não um gateway específico.

Se a regra existir e ainda falhar, aí sim confira o Postgres:

```bash
ss -lntp | grep 5432                                   # 0.0.0.0:5432
grep tomenu /etc/postgresql/16/main/pg_hba.conf        # faixa 172.16.0.0/12
```

### `migrate` falha com `permission denied for schema public`

Desde o PG15 o schema `public` não concede `CREATE` a todos, e seu dono é o
dono do banco. Como as migrations rodam como `tomenu_app` (o `DB_USERNAME` do
`.env.prod`), ele precisa ser dono do schema:

```bash
sudo -u postgres psql -d tomenu -c 'ALTER SCHEMA public OWNER TO tomenu_app;'
sudo -u postgres psql -d tomenu -c 'GRANT ALL ON SCHEMA public TO tomenu_app;'
```

Ser dono das tabelas não enfraquece o isolamento: é justamente o que o
`CREATE POLICY` da migration 000400 exige, e o `FORCE ROW LEVEL SECURITY` da
mesma migration impede que a dona escape das policies.

### Tudo responde 502, mas `docker compose ps` mostra todos `Up`

O nginx está com IPs de container antigos em cache. Acontece sempre que
`api` ou `storefront` são recriados sem reiniciar o nginx:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod logs nginx --tail=10
# "Host is unreachable" / "Connection refused" apontando para um IP 172.18.x
docker compose -f docker-compose.prod.yml --env-file .env.prod restart nginx
```

Ver passo 11 — o `restart nginx` faz parte do procedimento de atualização.

### `worker` aparece `unhealthy` mas processa a fila

O healthcheck usava `pgrep`, que não existe na imagem PHP (`procps` não está
instalado), então falhava sempre com `pgrep: not found`:

```bash
docker inspect tomenu-prod-worker-1 --format '{{json .State.Health}}' | tail -5
```

Já corrigido no `docker-compose.prod.yml` — o check agora lê `/proc/1/cmdline`,
que não depende de pacote nenhum. Se reaparecer, confirme que o worker está
vivo pelo PID 1:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    exec -T worker cat /proc/1/cmdline | tr '\0' ' '
```

### `required variable CLOUDFLARE_TUNNEL_TOKEN is missing`

O droplet está num commit anterior ao que removeu o Cloudflare Tunnel. Faça
`git pull` em `/opt/tomenu` — o `.env.prod` não é afetado, está no
`.gitignore`. Se houver edição local no `01-app-role.sql` (a senha do passo 3),
use `git stash` antes.

---

## Checklist final

- [ ] `cloud-init status --long`: `extended_status: done`, não `degraded done`
      (se degradou, nada do user-data rodou — ver passo 1)
- [ ] `swapon --show`: 2 GB (sem swap, o build da imagem PHP morre por OOM)
- [ ] `TENANCY_TRUST_HEADER=false`
- [ ] `APP_DEBUG=false`
- [ ] `DB_USERNAME=tomenu_app` (não o owner) — RLS depende disso
- [ ] `01-app-role.sql` aplicado, com senha trocada
- [ ] Nenhum papel `tomenu*` com `rolsuper` ou `rolbypassrls` — um superuser
      ignora a RLS em silêncio (`SELECT rolname, rolsuper, rolbypassrls FROM
      pg_roles WHERE rolname LIKE 'tomenu%';`)
- [ ] Schema `public` pertence a `tomenu_app`, senão o `migrate` não roda
- [ ] Registros `A` de `@` e `*` proxied (nuvem laranja), para o Reserved IP
- [ ] Origin Certificate em `/etc/tomenu/certs`, chave em `chmod 600`
- [ ] SSL/TLS em **Full (strict)** + Always Use HTTPS
- [ ] Redirect `www` → apex
- [ ] Cache Rule de `/api/*`
- [ ] `ufw status`: **active**, 22 aberta, 80/443 só para faixas do Cloudflare
      (se `command not found`, o `iptables-persistent` o removeu — ver passo 6)
- [ ] `ufw status | grep 5432`: liberada para `172.16.0.0/12` — sem isso o
      `migrate` não alcança o Postgres do host
- [ ] `iptables -L DOCKER-USER -n` salta para `TOMENU-CF` (o ufw sozinho não
      cobre porta publicada por container)
- [ ] O DROP da `TOMENU-CF` tem `-i eth0` — sem isso o `npm ci` do build falha
      com `ETIMEDOUT`: `iptables -S TOMENU-CF | grep DROP`
- [ ] `systemctl is-enabled tomenu-cf-rules`: **enabled** (sem ele a cadeia
      some no reboot e a origem fica exposta)
- [ ] Um container alcança a internet:
      `docker run --rm alpine sh -c "apk add -q curl && curl -sI https://registry.npmjs.org"`
- [ ] Cardápio de uma loja responde 200 com `s-maxage=30`; `cf-cache-status`
      vira `HIT` no segundo acesso (ver passo 9)
- [ ] Editar um preço no admin reflete no site em segundos — se demorar ~30s,
      o purge automático não está configurado (ver passo 9.1)
- [ ] Landing e admin **sem** `s-maxage` — cachear qualquer um dos dois serviria
      conteúdo de um visitante para outro
- [ ] `app.to-menu.com` serve o admin (`<title>admin</title>`), não a landing
- [ ] `app.to-menu.com/produtos` responde 200 — rewrite catch-all funcionando
- [ ] `REVERB_APP_KEY`/`REVERB_APP_SECRET` preenchidos e `BROADCAST_CONNECTION=reverb`
      — com `log` o pedido novo nunca chega ao painel, sem erro em lugar nenhum
- [ ] As variáveis do Reverb estão em **`apps/api/.env`**, não só no
      `.env.prod`. É esse o arquivo que o compose injeta nos containers
      (`env_file` do âncora `x-api`); o `--env-file .env.prod` só resolve
      `${...}` no YAML e alimenta o build do admin. Confira o que de fato
      chegou ao container — não o que está no arquivo:
      ```
      docker inspect tomenu-prod-reverb-1 \
        --format '{{range .Config.Env}}{{println .}}{{end}}' | grep -E 'REVERB|BROADCAST'
      ```
      `BROADCAST_CONNECTION=log` ou ausência de `REVERB_APP_KEY` aqui significa
      que o Reverb sobe e morre em ciclo, e todo evento é descartado
- [ ] `reverb` e `worker` **healthy** (`docker compose ps`) e `reverb` **não**
      reiniciando em ciclo. `Up` sozinho não basta: um restart a cada 60s também
      mostra `Up` e derruba toda conexão do painel. Compare o `StartedAt` com
      ~2min entre as leituras:
      `docker inspect tomenu-prod-reverb-1 --format '{{.State.StartedAt}} {{.RestartCount}}'`
- [ ] WebSocket sobe de verdade — o handshake responde 101, não 200/502. Force
      **HTTP/1.1**: sob HTTP/2 o mesmo endpoint devolve 500, porque o upgrade é
      mecanismo do 1.1 — não é defeito de configuração, e navegadores sempre
      usam 1.1 para WebSocket. O `--max-time` é necessário porque a conexão fica
      aberta em caso de sucesso; `curl` sai com código 28 e o 101 é o que
      importa. Não use `-I`: o HEAD devolve 405 mesmo com tudo funcionando.
      ```
      curl -s -o /dev/null -w '%{http_code}\n' --max-time 5 --http1.1 \
        -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
        -H 'Sec-WebSocket-Version: 13' \
        -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
        'https://ws.to-menu.com/app/SUA_REVERB_APP_KEY?protocol=7&client=js&version=8.4.0'
      ```
- [ ] Se o handshake responder **502**, reinicie o nginx antes de investigar
      qualquer outra coisa: ele resolve o nome `reverb` uma vez, na
      inicialização, e guarda o IP. Recriar só o container do Reverb muda o IP e
      deixa o nginx apontando para o endereço morto — `connect() failed
      (111: Connection refused)` no log, com o Reverb perfeitamente saudável.
      O `make deploy` já reinicia o nginx por último; o problema aparece quando
      se recria o `reverb` isoladamente
- [ ] Um pedido de teste faz o painel tocar e mostrar a faixa verde. É o único
      teste que cobre a corrente inteira (fila → Reverb → nginx → navegador)
- [ ] `curl -k https://SEU_IP` **não** responde — origem fora do alcance direto
- [ ] `TURNSTILE_SECRET` preenchido em `apps/api/.env` e `TURNSTILE_SITE_KEY`
      em `.env.prod` — sem o secret, login e cadastro ficam **sem** verificação
      de robô e nada indica isso (ver passo 5.1)
- [ ] O widget aparece no login em `app.to-menu.com` e no cadastro da landing —
      se o botão fica desabilitado, o domínio não está na lista do widget
- [ ] `chmod 600 .env.prod`
- [ ] Credenciais do `CLOUDFLARE_R2_*` preenchidas no `.env.prod` (sem elas o
      backup aborta)
- [ ] Backup testado com `pg_restore` **num banco separado**, conferindo que a
      RLS voltou (`relforcerowsecurity = t`)
