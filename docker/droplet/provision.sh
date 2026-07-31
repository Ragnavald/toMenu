#!/usr/bin/env bash
# Aplica num droplet ja booted o que o cloud-init.yaml faria no boot.
#
# Existe porque o user-data atravessa um textarea no painel da DO, onde a
# codificacao nao e garantida: um em-dash que chega corrompido derruba o parse
# do YAML inteiro e o cloud-init termina "degraded done" sem rodar nada. O
# sintoma aparece tarde -- docker ausente no meio do deploy.
#
# Idempotente: pode rodar de novo com seguranca.
#
#   ssh root@IP 'bash -s' < docker/droplet/provision.sh
#
# Mantenha em sincronia com cloud-init.yaml: este script e a copia executavel
# daquele arquivo, nao uma variante.

set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "precisa ser root" >&2
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

echo "==> pacotes base"
apt-get update
apt-get install -y ca-certificates curl git ufw fail2ban postgresql-client-16

# --- Swap ---------------------------------------------------------------
# 2 GB: o `docker compose build` da imagem PHP e o pico de memoria do deploy e
# e o passo que morre por OOM num droplet de 2 GB sem swap.
echo "==> swap"
if ! swapon --show | grep -q '^/swapfile'; then
    if [ ! -f /swapfile ]; then
        fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048
        chmod 600 /swapfile
        mkswap /swapfile
    fi
    swapon /swapfile
fi
grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab

# swappiness baixo: o swap existe como rede de seguranca para o build, nao para
# uso rotineiro -- com o padrao 60 o kernel paginaria o Postgres em operacao
# normal e a latencia do banco pioraria.
cat > /etc/sysctl.d/99-tomenu.conf <<'EOF'
vm.swappiness=10
vm.overcommit_memory=1
EOF
sysctl --system >/dev/null

# --- Docker -------------------------------------------------------------
# Repositorio oficial; o do Ubuntu fica muito atras.
echo "==> docker"
install -m 0755 -d /etc/apt/keyrings
if [ ! -f /etc/apt/keyrings/docker.asc ]; then
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
fi
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
    > /etc/apt/sources.list.d/docker.list

# Sem limite, o journal e os logs de container enchem o disco do droplet -- que
# e pequeno e e o mesmo volume do Postgres. Escrito antes de subir o daemon
# para o limite valer desde o primeiro container.
install -d -m 0755 /etc/docker
cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
EOF

apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker

# --- Firewall -----------------------------------------------------------
# O nginx termina TLS e e a entrada publica, mas so deve receber trafego que
# passou pelo Cloudflare. Liberar 80/443 para 0.0.0.0/0 deixaria a origem
# acessivel pelo IP direto, contornando WAF, rate limit e cache.
echo "==> ufw"
curl -fsSL https://www.cloudflare.com/ips-v4 -o /etc/tomenu-cf-ips-v4
curl -fsSL https://www.cloudflare.com/ips-v6 -o /etc/tomenu-cf-ips-v6
# Uma lista vazia abriria tudo no passo seguinte; aborta antes disso.
[ -s /etc/tomenu-cf-ips-v4 ] || { echo "lista de IPs do Cloudflare vazia" >&2; exit 1; }

ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
while read -r ip; do [ -n "$ip" ] && ufw allow from "$ip" to any port 80,443 proto tcp; done < /etc/tomenu-cf-ips-v4
while read -r ip; do [ -n "$ip" ] && ufw allow from "$ip" to any port 80,443 proto tcp; done < /etc/tomenu-cf-ips-v6

# O Postgres roda no host, nao em container: sem esta regra o ufw nega a
# conexao vinda da rede do Docker e o `artisan migrate` morre com "timeout
# expired". So a faixa privada -- a 5432 continua fechada para a internet.
ufw allow from 172.16.0.0/12 to any port 5432 proto tcp

ufw --force enable

# O ufw sozinho NAO protege porta publicada por container: o Docker insere as
# proprias regras de DNAT antes da cadeia do ufw, e a 443 do nginx ficaria
# aberta ao mundo mesmo com as regras acima. DOCKER-USER e avaliada antes das
# regras do Docker e e o ponto de filtro suportado para isso. Depende do daemon
# ja instalado -- e por isso que este bloco vem depois do Docker.
echo "==> DOCKER-USER / TOMENU-CF"

# O `-i $EXT` no DROP nao e detalhe: a DOCKER-USER ve os DOIS sentidos do
# forwarding. Sem restringir a interface de entrada, um container abrindo
# conexao para qualquer HTTPS externo casa com `--dports 443` e leva DROP -- o
# `npm ci` do build do storefront morre com ETIMEDOUT em todo pacote, e o
# sintoma ("Exit handler never called!") nao lembra firewall em nada.
EXT=$(ip route show default | awk '{print $5; exit}')
[ -n "$EXT" ] || { echo "nao achei a interface externa" >&2; exit 1; }
echo "    interface externa: $EXT"

iptables -N TOMENU-CF 2>/dev/null || iptables -F TOMENU-CF
while read -r ip; do [ -n "$ip" ] && iptables -A TOMENU-CF -s "$ip" -j RETURN; done < /etc/tomenu-cf-ips-v4
iptables -A TOMENU-CF -i "$EXT" -p tcp -m multiport --dports 80,443 -j DROP
iptables -A TOMENU-CF -j RETURN
# Sem remover o salto antigo, cada execucao empilha um -I a mais na DOCKER-USER.
while iptables -C DOCKER-USER -j TOMENU-CF 2>/dev/null; do
    iptables -D DOCKER-USER -j TOMENU-CF
done
iptables -I DOCKER-USER 1 -j TOMENU-CF

ip6tables -N TOMENU-CF 2>/dev/null || ip6tables -F TOMENU-CF
while read -r ip; do [ -n "$ip" ] && ip6tables -A TOMENU-CF -s "$ip" -j RETURN; done < /etc/tomenu-cf-ips-v6
ip6tables -A TOMENU-CF -i "$EXT" -p tcp -m multiport --dports 80,443 -j DROP
ip6tables -A TOMENU-CF -j RETURN
while ip6tables -C DOCKER-USER -j TOMENU-CF 2>/dev/null; do
    ip6tables -D DOCKER-USER -j TOMENU-CF
done
ip6tables -I DOCKER-USER 1 -j TOMENU-CF

# Sem isto as regras acima somem no primeiro reboot e a origem fica exposta.
#
# NAO use iptables-persistent aqui: o pacote conflita com o ufw, e o apt REMOVE
# o ufw ao instala-lo -- deixando INPUT com policy ACCEPT e todas as portas do
# host abertas, sem aviso nenhum. O ufw persiste as regras dele sozinho; este
# servico cuida so da cadeia dos containers, que o ufw nao gerencia.
install -m 700 /dev/stdin /usr/local/sbin/tomenu-cf-rules <<'SCRIPT'
#!/bin/bash
set -e
EXT=$(ip route show default | awk '{print $5; exit}')
[ -n "$EXT" ] || exit 1
[ -s /etc/tomenu-cf-ips-v4 ] || exit 1

iptables -N TOMENU-CF 2>/dev/null || iptables -F TOMENU-CF
while read -r ip; do [ -n "$ip" ] && iptables -A TOMENU-CF -s "$ip" -j RETURN; done < /etc/tomenu-cf-ips-v4
iptables -A TOMENU-CF -i "$EXT" -p tcp -m multiport --dports 80,443 -j DROP
iptables -A TOMENU-CF -j RETURN
while iptables -C DOCKER-USER -j TOMENU-CF 2>/dev/null; do iptables -D DOCKER-USER -j TOMENU-CF; done
iptables -I DOCKER-USER 1 -j TOMENU-CF

if [ -s /etc/tomenu-cf-ips-v6 ]; then
    ip6tables -N TOMENU-CF 2>/dev/null || ip6tables -F TOMENU-CF
    while read -r ip; do [ -n "$ip" ] && ip6tables -A TOMENU-CF -s "$ip" -j RETURN; done < /etc/tomenu-cf-ips-v6
    ip6tables -A TOMENU-CF -i "$EXT" -p tcp -m multiport --dports 80,443 -j DROP
    ip6tables -A TOMENU-CF -j RETURN
    while ip6tables -C DOCKER-USER -j TOMENU-CF 2>/dev/null; do ip6tables -D DOCKER-USER -j TOMENU-CF; done
    ip6tables -I DOCKER-USER 1 -j TOMENU-CF
fi
SCRIPT

cat > /etc/systemd/system/tomenu-cf-rules.service <<'UNIT'
[Unit]
Description=Regras TOMENU-CF (restringe 80/443 da origem ao Cloudflare)
# A DOCKER-USER so existe depois que o daemon do Docker sobe.
After=docker.service network-online.target
Requires=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/sbin/tomenu-cf-rules

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now tomenu-cf-rules

# --- Certificados -------------------------------------------------------
# O conteudo vai depois, por SSH: a chave privada nunca entra no user-data,
# que e legivel para sempre pelo metadata service.
install -d -m 0700 /etc/tomenu/certs

# --- SSH: so chave ------------------------------------------------------
echo "==> ssh"
sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#*PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
systemctl restart ssh

systemctl enable --now fail2ban

# Um droplet de producao que ninguem atualiza vira o elo mais fraco em poucos
# meses.
apt-get install -y unattended-upgrades
dpkg-reconfigure -f noninteractive unattended-upgrades

echo
echo "==> pronto. confira:"
echo "    docker --version && docker compose version"
echo "    swapon --show"
echo "    ufw status"
echo "    iptables -L DOCKER-USER -n     # primeira regra salta para TOMENU-CF"
