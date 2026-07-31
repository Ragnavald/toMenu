#!/usr/bin/env bash
#
# Detecta erro servido pelo edge com a origem já saudável e purga o cache.
#
# O nginx marca 5xx como no-store, o que impede o edge de guardar erro NOVO.
# Este monitor cobre o que aquilo não alcança:
#
#   - resposta ruim cacheada antes daquela regra existir;
#   - erro vindo da aplicação (não do nginx) com header cacheável;
#   - qualquer divergência entre o que o edge serve e o que a origem responde.
#
# A lógica é comparar as duas coisas. Só purga quando o edge está ruim E a
# origem está boa — purgar com a origem fora não adianta nada e ainda joga
# tráfego nela durante um incidente.
#
# Instalação:
#   cp docker/droplet/watch-errors.sh /usr/local/bin/tomenu-watch
#   chmod +x /usr/local/bin/tomenu-watch
#   crontab -e  ->  */2 * * * * /usr/local/bin/tomenu-watch >> /var/log/tomenu-watch.log 2>&1
set -euo pipefail

ENV_FILE="${ENV_FILE:-/opt/tomenu/.env.prod}"
PURGE="${PURGE:-/usr/local/bin/tomenu-purge}"
STATE="/var/run/tomenu-watch.last-purge"
# Sem isto, uma origem intermitente vira um laço de purge a cada 2 min, o que
# derruba o cache inteiro e piora a latência justamente durante o incidente.
COOLDOWN_SECONDS="${COOLDOWN_SECONDS:-600}"

env_get() {
    sed -n "s/^$1=//p" "$ENV_FILE" | tail -1 | sed -e 's/^"//' -e 's/"$//'
}

ROOT_DOMAIN="$(env_get NEXT_PUBLIC_ROOT_DOMAIN)"
: "${ROOT_DOMAIN:?NEXT_PUBLIC_ROOT_DOMAIN ausente}"

# Hosts vigiados: os que o visitante realmente acessa. Um erro aqui é erro que
# o cliente vê.
HOSTS=("$ROOT_DOMAIN" "app.${ROOT_DOMAIN}")

# Lojas ativas entram na lista: são as URLs de maior tráfego (QR code na mesa).
if command -v psql >/dev/null 2>&1; then
    while read -r slug; do
        [ -n "$slug" ] && HOSTS+=("${slug}.${ROOT_DOMAIN}")
    done < <(sudo -u postgres psql -d "$(env_get DB_DATABASE)" -tAc \
        "SELECT slug FROM tenants WHERE suspended_at IS NULL LIMIT 20;" 2>/dev/null || true)
fi

# Status considerados quebra de experiência. 404 fica de fora: é resposta
# legítima de loja inexistente e cacheá-la é intencional.
#
# 000 é o que o curl devolve quando não houve resposta HTTP (timeout, conexão
# recusada) — conta como quebra.
is_broken() { [ "$1" -ge 500 ] 2>/dev/null || [ "$1" = "000" ]; }

# O curl já imprime 000 quando falha; sem `|| true` o set -e aborta o script no
# exit code não-zero, e um `|| echo 0` concatenaria "0000".
http_status() {
    curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$1" 2>/dev/null || true
}

problema=""

for host in "${HOSTS[@]}"; do
    # O que o VISITANTE recebe, passando pelo edge.
    edge=$(http_status "https://${host}/")

    is_broken "$edge" || continue

    # O que a ORIGEM responde agora, contornando o edge. O parâmetro aleatório
    # garante MISS; sem ele leríamos a mesma resposta cacheada.
    origem=$(http_status "https://${host}/?__probe=$(date +%s%N)")

    if is_broken "$origem"; then
        # Origem fora: purgar não resolve e ainda amplifica a carga.
        echo "[$(date -u +%FT%TZ)] ${host}: edge=${edge} origem=${origem} — origem fora, sem purge"
    else
        echo "[$(date -u +%FT%TZ)] ${host}: edge=${edge} origem=${origem} — cache servindo erro"
        problema="sim"
    fi
done

[ -n "$problema" ] || exit 0

agora=$(date +%s)
ultimo=$(cat "$STATE" 2>/dev/null || echo 0)
if [ $((agora - ultimo)) -lt "$COOLDOWN_SECONDS" ]; then
    echo "[$(date -u +%FT%TZ)] purge suprimido: último há $((agora - ultimo))s (cooldown ${COOLDOWN_SECONDS}s)"
    exit 0
fi

echo "$agora" > "$STATE"
"$PURGE"
