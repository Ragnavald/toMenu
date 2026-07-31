#!/usr/bin/env bash
#
# Purga o cache do Cloudflare.
#
# Existe por dois motivos distintos:
#
#   1. Deploy — o passo 11 chama este script no fim. Mesmo com o nginx
#      marcando 5xx como no-store, o edge pode ter guardado uma resposta ruim
#      na janela entre o container novo subir e o nginx reconhecê-lo.
#
#   2. Monitor — o watch-errors.sh chama quando detecta erro servido do edge
#      com a origem já saudável. É o caso que o no-store não cobre: resposta
#      cacheada ANTES da correção do nginx, ou erro 4xx/5xx vindo da própria
#      aplicação com header cacheável.
#
# Uso:
#   purge-cache.sh                      # purga tudo
#   purge-cache.sh https://loja.to-menu.com/   # purga URLs específicas
#
# Requer no .env.prod:
#   CLOUDFLARE_ZONE_ID   — Overview da zona, coluna da direita
#   CLOUDFLARE_API_TOKEN — token com permissão Zone > Cache Purge
set -euo pipefail

ENV_FILE="${ENV_FILE:-/opt/tomenu/.env.prod}"

# Mesma leitura do backup-db.sh: `source` quebra em valores com < ou >.
env_get() {
    sed -n "s/^$1=//p" "$ENV_FILE" | tail -1 | sed -e 's/^"//' -e 's/"$//'
}

ZONE_ID="$(env_get CLOUDFLARE_ZONE_ID)"
API_TOKEN="$(env_get CLOUDFLARE_API_TOKEN)"

if [ -z "$ZONE_ID" ] || [ -z "$API_TOKEN" ]; then
    echo "purge-cache: CLOUDFLARE_ZONE_ID/CLOUDFLARE_API_TOKEN ausentes em $ENV_FILE" >&2
    echo "purge-cache: pulando purge (não é erro fatal para o deploy)" >&2
    exit 0
fi

if [ $# -gt 0 ]; then
    # Purga seletiva: preserva o cache do resto do site, então é preferível
    # quando se sabe qual URL está ruim.
    files=$(printf '"%s",' "$@")
    payload="{\"files\":[${files%,}]}"
    alvo="$*"
else
    payload='{"purge_everything":true}'
    alvo="tudo"
fi

response="$(curl -sS -X POST \
    "https://api.cloudflare.com/client/v4/zones/${ZONE_ID}/purge_cache" \
    -H "Authorization: Bearer ${API_TOKEN}" \
    -H "Content-Type: application/json" \
    --data "$payload")"

if echo "$response" | grep -q '"success":true'; then
    echo "[$(date -u +%FT%TZ)] purge ok: ${alvo}"
else
    # Não aborta o deploy: cache sujo é degradação, deploy pela metade é pior.
    echo "[$(date -u +%FT%TZ)] purge FALHOU: $response" >&2
    exit 1
fi
