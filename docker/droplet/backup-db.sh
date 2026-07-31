#!/usr/bin/env bash
#
# Dump diário do Postgres para o Cloudflare R2.
#
# Complementa o backup automático do droplet, que não o substitui: snapshot de
# disco é tirado com o Postgres rodando e pode restaurar num estado que exige
# recuperação. `pg_dump` produz um artefato consistente, e mandá-lo para o R2
# tira a cópia de dentro da DigitalOcean — o que importa no cenário de conta
# suspensa ou região indisponível.
#
# Instalação (como root no droplet):
#   cp /opt/tomenu/docker/droplet/backup-db.sh /usr/local/bin/tomenu-backup
#   chmod +x /usr/local/bin/tomenu-backup
#   crontab -e   ->   15 4 * * *  /usr/local/bin/tomenu-backup >> /var/log/tomenu-backup.log 2>&1
#
# Requer: awscli (o R2 fala o protocolo do S3) e as credenciais no .env.prod.
set -euo pipefail

ENV_FILE="${ENV_FILE:-/opt/tomenu/.env.prod}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"

# `grep` em vez de `source`: o .env.prod é lido pelo Docker, não pelo shell, e
# aceita valores que quebram o bash — `<account_id>` no endpoint do R2 vira
# redirecionamento de arquivo e aborta o script.
env_get() {
    sed -n "s/^$1=//p" "$ENV_FILE" | tail -1 | sed -e 's/^"//' -e 's/"$//'
}

DB_DATABASE="$(env_get DB_DATABASE)"
CLOUDFLARE_R2_BUCKET="$(env_get CLOUDFLARE_R2_BUCKET)"
CLOUDFLARE_R2_ENDPOINT="$(env_get CLOUDFLARE_R2_ENDPOINT)"
CLOUDFLARE_R2_REGION="$(env_get CLOUDFLARE_R2_REGION)"
CLOUDFLARE_R2_ACCESS_KEY_ID="$(env_get CLOUDFLARE_R2_ACCESS_KEY_ID)"
CLOUDFLARE_R2_SECRET_ACCESS_KEY="$(env_get CLOUDFLARE_R2_SECRET_ACCESS_KEY)"

: "${DB_DATABASE:?}"
: "${CLOUDFLARE_R2_BUCKET:?preencha as credenciais do R2 no .env.prod}"
: "${CLOUDFLARE_R2_ENDPOINT:?}"
: "${CLOUDFLARE_R2_ACCESS_KEY_ID:?}" "${CLOUDFLARE_R2_SECRET_ACCESS_KEY:?}"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DUMP="/tmp/tomenu-${STAMP}.dump"

cleanup() { rm -f "$DUMP"; }
trap cleanup EXIT

# Roda como `postgres` (superuser local, via socket), NÃO como DB_USERNAME.
#
# As tabelas pertencem a tomenu_app, que tem FORCE ROW LEVEL SECURITY: um
# pg_dump com esse papel falha com "query would be affected by row-level
# security policy" — ou, pior, num cenário com app.tenant_id definido, geraria
# um dump silenciosamente incompleto, contendo só as linhas de um tenant.
# Superuser ignora RLS, que é exatamente o que um backup precisa.
#
# -Fc (custom): comprimido e restaurável seletivamente com pg_restore, ao
# contrário do SQL puro. --no-owner evita que o restore exija os mesmos papéis
# do servidor de origem.
sudo -u postgres pg_dump \
    --dbname="$DB_DATABASE" \
    --format=custom \
    --no-owner \
    --file="$DUMP"

# O dump nasce pertencendo ao postgres; o aws s3 cp roda como root.
chown root:root "$DUMP"

# Um dump vazio ou truncado é pior que nenhum: passa despercebido até a hora do
# restore. 24 tabelas hoje; o limiar cobre o caso de o dump sair sem dados.
TABLES="$(sudo -u postgres pg_restore -l "$DUMP" | grep -c 'TABLE DATA' || true)"
if [ "$TABLES" -lt 10 ]; then
    echo "ERRO: dump com apenas $TABLES tabelas — abortando antes de enviar" >&2
    exit 1
fi

export AWS_ACCESS_KEY_ID="$CLOUDFLARE_R2_ACCESS_KEY_ID"
export AWS_SECRET_ACCESS_KEY="$CLOUDFLARE_R2_SECRET_ACCESS_KEY"
export AWS_DEFAULT_REGION="${CLOUDFLARE_R2_REGION:-auto}"

aws s3 cp "$DUMP" "s3://${CLOUDFLARE_R2_BUCKET}/backups/tomenu-${STAMP}.dump" \
    --endpoint-url "$CLOUDFLARE_R2_ENDPOINT"

# Remove dumps antigos do bucket. Sem isto o custo cresce indefinidamente, e
# backup de banco de pedidos não tem valor retroativo além de alguns meses.
CUTOFF="$(date -u -d "${RETENTION_DAYS} days ago" +%Y%m%d)"

aws s3 ls "s3://${CLOUDFLARE_R2_BUCKET}/backups/" \
    --endpoint-url "$CLOUDFLARE_R2_ENDPOINT" \
    | awk '{print $4}' \
    | while read -r key; do
        [ -z "$key" ] && continue
        # tomenu-20260130T041500Z.dump -> 20260130
        stamp="${key#tomenu-}"
        stamp="${stamp%%T*}"
        if [[ "$stamp" =~ ^[0-9]{8}$ ]] && [ "$stamp" -lt "$CUTOFF" ]; then
            aws s3 rm "s3://${CLOUDFLARE_R2_BUCKET}/backups/${key}" \
                --endpoint-url "$CLOUDFLARE_R2_ENDPOINT"
        fi
    done

echo "[$(date -u +%FT%TZ)] backup concluído: tomenu-${STAMP}.dump"
