#!/usr/bin/env bash
#
# Vigia memória, swap e disco do droplet, e avisa antes do problema virar queda.
#
# Complementa o tomenu-watch, que olha só o HTTP: lá o sintoma já chegou ao
# visitante. Aqui a ideia é ver a pressão crescendo antes disso — o OOM killer
# derrubando o php-fpm, ou o disco enchendo e travando o Postgres, são falhas
# que dão sinal com antecedência e passam despercebidas sem alguém medindo.
#
# O que NÃO é alarme:
#
#   - RAM "usada" alta. O Linux usa a memória livre como cache de disco e a
#     devolve sob pressão. Quem responde "cabe mais processo aqui?" é o
#     `available` do /proc/meminfo, e é ele que este script observa. Alarmar
#     por `used` produziria alerta constante numa máquina perfeitamente sadia.
#
#   - Swap com uso baixo e estável. O kernel move páginas ociosas para o disco
#     de propósito; com vm.swappiness=10 isso é raro e saudável. O que importa
#     é swap ALTO junto com available BAIXO — aí a máquina está de fato sem RAM.
#
# Instalação:
#   cp docker/droplet/watch-resources.sh /usr/local/bin/tomenu-resources
#   chmod +x /usr/local/bin/tomenu-resources
#   crontab -e  ->  */5 * * * * /usr/local/bin/tomenu-resources >> /var/log/tomenu-resources.log 2>&1
set -euo pipefail

# Limiares. Escolhidos a partir da linha de base medida em 02/08/2026:
# ~1.0 Gi disponível de 1.9 Gi, swap em 162 Mi, disco em 28%.
#
# MEM_MIN_MB em 300: abaixo disso o php-fpm não consegue mais forkar worker sob
# pico, que é o primeiro sintoma real de falta de memória nesta stack.
MEM_MIN_MB="${MEM_MIN_MB:-300}"
# Metade do swapfile de 2 Gi. Sozinho não é problema; combinado com pouca RAM
# disponível, é. Ver a regra composta abaixo.
SWAP_MAX_MB="${SWAP_MAX_MB:-1024}"
# O Postgres para de aceitar escrita com o disco cheio, e a recuperação é
# manual. 85% dá folga para agir — o backup diário sozinho ocupa espaço.
DISK_MAX_PCT="${DISK_MAX_PCT:-85}"

# Silencia repetição: sem isto uma condição persistente vira uma linha de log a
# cada 5 min e o arquivo fica ilegível justamente quando você precisa dele.
STATE="${STATE:-/var/run/tomenu-resources.last-alert}"
COOLDOWN_SECONDS="${COOLDOWN_SECONDS:-3600}"

log() { echo "[$(date -u +%FT%TZ)] $*"; }

# /proc/meminfo em kB. `available` é a estimativa do próprio kernel de quanta
# memória um processo novo conseguiria sem entrar em swap — mais confiável que
# qualquer conta com free/used/cache feita à mão.
mem_available_mb=$(awk '/^MemAvailable:/ {print int($2/1024)}' /proc/meminfo)
swap_total_mb=$(awk '/^SwapTotal:/ {print int($2/1024)}' /proc/meminfo)
swap_free_mb=$(awk '/^SwapFree:/ {print int($2/1024)}' /proc/meminfo)
swap_used_mb=$((swap_total_mb - swap_free_mb))

disk_pct=$(df --output=pcent / | tail -1 | tr -dc '0-9')

alertas=()

if [ "$mem_available_mb" -lt "$MEM_MIN_MB" ]; then
    alertas+=("memória: ${mem_available_mb}MB disponíveis (mínimo ${MEM_MIN_MB}MB)")
fi

# Regra composta de propósito: swap alto COM memória curta. Swap alto sozinho
# costuma ser página ociosa parqueada, que não prejudica ninguém.
if [ "$swap_used_mb" -gt "$SWAP_MAX_MB" ] && [ "$mem_available_mb" -lt $((MEM_MIN_MB * 2)) ]; then
    alertas+=("swap: ${swap_used_mb}MB em uso com apenas ${mem_available_mb}MB de RAM disponível")
fi

if [ "$disk_pct" -gt "$DISK_MAX_PCT" ]; then
    alertas+=("disco: ${disk_pct}% ocupado (limite ${DISK_MAX_PCT}%)")
fi

if [ ${#alertas[@]} -eq 0 ]; then
    # Uma linha por execução mantém histórico para comparar depois — é o que
    # transforma "está lento hoje" em "a memória caiu desde terça".
    log "ok mem=${mem_available_mb}MB swap=${swap_used_mb}MB disco=${disk_pct}%"
    exit 0
fi

agora=$(date +%s)
ultimo=$(cat "$STATE" 2>/dev/null || echo 0)

if [ $((agora - ultimo)) -lt "$COOLDOWN_SECONDS" ]; then
    log "alerta suprimido (cooldown): ${alertas[*]}"
    exit 0
fi

echo "$agora" > "$STATE"

for a in "${alertas[@]}"; do
    log "ALERTA ${a}"
done

# Um retrato do momento, no próprio log. Quando o alerta é lido — muitas horas
# depois, em geral — o estado que o causou já passou; sem isto sobraria só a
# informação de que algo aconteceu, sem pista de quem consumiu.
log "top de memória no momento do alerta:"
ps axo rss=,comm= --sort=-rss | head -5 | awk '{printf "  %6.0f MB  %s\n", $1/1024, $2}'

if command -v docker >/dev/null 2>&1; then
    log "containers:"
    docker stats --no-stream --format '  {{.Name}} {{.MemUsage}}' 2>/dev/null | head -10 || true
fi

# journald também recebe: o log em arquivo é rotacionado, e `journalctl -t`
# permite correlacionar com o resto do que aconteceu na máquina no mesmo minuto.
if command -v logger >/dev/null 2>&1; then
    logger -t tomenu-resources "ALERTA ${alertas[*]}"
fi
