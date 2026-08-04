.PHONY: up down dev build test lint lint-fix fresh api-shell logs \
        deploy deploy-admin deploy-build deploy-migrate deploy-recreate \
        platform-admin \
        prod-logs prod-ps

COMPOSE = docker compose
API = $(COMPOSE) run --rm api
# Migrations e seeds usam o papel dono das tabelas; a aplicação em runtime
# conecta com o papel restrito (sem BYPASSRLS), que é o que faz o RLS valer.
API_ADMIN = $(COMPOSE) run --rm -e DB_USERNAME=tomenu -e DB_PASSWORD=secret api
API_TEST = $(COMPOSE) run --rm -e DB_DATABASE=tomenu_test -e DB_USERNAME=tomenu_app api

# --- Produção --------------------------------------------------------------
# Os alvos `deploy-*` rodam NO DROPLET, a partir de /opt/tomenu. Não funcionam
# na máquina de desenvolvimento: dependem do .env.prod e dos certificados que
# só existem no servidor.
PROD = $(COMPOSE) -f docker-compose.prod.yml --env-file .env.prod
PROD_API = $(PROD) run --rm api

up:
	$(COMPOSE) up -d postgres redis
	$(API_ADMIN) php artisan migrate --force
	@# Mesmo motivo do deploy-migrate: sem a linha do plano, o wizard e a
	@# navegação do painel leem as capacidades erradas num banco que já existe.
	$(API_ADMIN) php artisan db:seed --class=PlanSeeder --force
	$(COMPOSE) up -d api
	@echo "API em http://localhost:8000"

# O nome curto da classe evita o escape do namespace, que o shell do make
# interpretava errado e fazia o seeder rodar sem efeito.
fresh:
	$(API_ADMIN) php artisan migrate:fresh --force
	$(API_ADMIN) php artisan db:seed --class=DemoSeeder --force
	$(API) php artisan cache:clear
	@echo "Banco recriado com as lojas de demonstração."

dev:
	@echo "storefront :3000 · admin :5173 — Ctrl+C encerra ambos"
	@npm --prefix apps/storefront run dev & \
	 npm --prefix apps/admin run dev & \
	 wait

build:
	npm --prefix apps/storefront run build
	npm --prefix apps/admin run build

test:
	$(API_TEST) ./vendor/bin/pest

# Estilo do PHP. Sempre no container: o Pint depende de ext-dom, que o PHP da
# máquina de desenvolvimento pode não ter — e sem ela o erro sai como um crash
# do PHPUnit, sem dizer qual extensão falta.
#
# `lint` só verifica, para caber em CI e hook. `lint-fix` reescreve os arquivos.
lint:
	$(API) ./vendor/bin/pint --test

lint-fix:
	$(API) ./vendor/bin/pint

api-shell:
	$(API) bash

logs:
	$(COMPOSE) logs -f api

down:
	$(COMPOSE) down

# --- Produção (rodar no droplet, em /opt/tomenu) ----------------------------

# Deploy completo. A ordem dos passos não é intercambiável:
#
#   1. build da imagem da API ANTES do migrate — o `migrate` e os seeds rodam
#      num container efêmero feito dessa imagem, então eles só enxergam código
#      que já esteja nela. Sem este passo, uma migration ou um seeder NOVO roda
#      contra a imagem do deploy anterior: a migration simplesmente não existe
#      lá, e uma seeder nova morre com "Target class does not exist" — o
#      autoloader de produção é `--classmap-authoritative`, que desliga o
#      fallback PSR-4 e torna a classe invisível mesmo com o arquivo no disco.
#      Este `build` não sobe container nenhum; só prepara a imagem.
#   2. migrate DEPOIS do build, mas ANTES do `up` — o código novo consulta
#      colunas e tabelas que a migration cria. Subir primeiro abriria uma
#      janela de 500 em toda requisição que tocasse o schema novo.
#   3. restart do nginx DEPOIS do up — `up -d` só recria o container quando a
#      imagem ou a definição do serviço muda, e editar o default.conf montado
#      por volume não é nenhum dos dois. Sem o restart, um server block novo
#      simplesmente não existe (o host cai no wildcard e serve outra coisa).
#   4. purge por último — limpa do edge qualquer resposta ruim guardada na
#      janela entre o container novo subir e o nginx reconhecê-lo.
#
# `--remove-orphans` porque `up -d` só CRIA o que falta: um serviço apagado do
# compose continua rodando no droplet indefinidamente. Foi o que aconteceu com o
# antigo `reports-worker`, que sobreviveu ao próprio deploy que o removeu do
# arquivo e ficou consumindo ~60 MB à espera de uma fila extinta.
#
# `git pull` fica de fora de propósito: o deploy não deve decidir sozinho qual
# commit vai para produção.
deploy: deploy-build deploy-migrate
	$(PROD) up -d --build --remove-orphans
	$(PROD) restart nginx
	@# O purge é instalado à mão no provisionamento (DEPLOY.md §12), então pode
	@# não existir. Cache sujo é degradação passageira; abortar aqui deixaria o
	@# deploy "falhado" depois de já ter subido tudo com sucesso — pior leitura.
	@if [ -x /usr/local/bin/tomenu-purge ]; then \
		/usr/local/bin/tomenu-purge; \
	else \
		echo "aviso: tomenu-purge não encontrado; purgue o cache do Cloudflare à mão."; \
	fi
	@echo "Deploy concluído."

# Constrói a imagem da API sem subir nada.
#
# Existe separado porque `deploy-migrate` roda num container efêmero: ele usa a
# imagem que estiver disponível, e `run --rm` não reconstrói uma imagem velha.
# Rodar `deploy-migrate` sozinho depois de um `git pull` exige este build antes,
# senão migrations e seeders novos ainda não existem dentro da imagem.
deploy-build:
	$(PROD) build api

# Migrations isoladas, para quando o deploy já rodou e faltou só o schema.
#
# ATENÇÃO: rode `make deploy-build` antes se o código mudou. Este alvo executa
# na imagem existente, e migration/seeder novo não está nela.
#
# O seed dos planos anda junto com o migrate e não só no provisionamento: a
# migration cria as colunas de capacidade, mas quem cria a LINHA de um plano
# novo é o seeder. Rodar só o migrate deixa o plano inexistente, e aí toda loja
# cai no fallback `?? true` do Tenant — o painel passa a oferecer entrega e
# pagamento para quem contratou só o cardápio. É idempotente (updateOrCreate),
# então repetir a cada deploy não custa nada além de um UPDATE.
deploy-migrate:
	$(PROD_API) php artisan migrate --force
	$(PROD_API) php artisan db:seed --class=PlanSeeder --force
	@# As duas lojas que a landing linka ("ver exemplo") precisam existir em
	@# produção, senão os links dão 404. A seeder é idempotente e não toca em
	@# loja que já existe, então repetir a cada deploy é seguro.
	$(PROD_API) php artisan db:seed --class=ExampleStoresSeeder --force

# Republica só os painéis (lojista e plataforma), sem tocar em API nem
# storefront. É o caminho para uma mudança que só existe no bundle do Vite.
#
# O admin-build roda uma vez e sai com Exited (0) — é o esperado, não é falha.
# O restart do nginx é o que faz ele enxergar o dist novo no volume.
deploy-admin:
	$(PROD) up -d --build admin-build
	$(PROD) restart nginx
	@echo "Painéis republicados em app. e admin.{domínio}."

# Recria TODOS os containers, mesmo os que não mudaram.
#
# Necessário quando muda uma opção que o Docker só lê na criação do container e
# que não altera a imagem — `logging`, `ulimits`, `sysctls`. Nesses casos o
# `make deploy` normal não recria nada e a mudança fica no arquivo sem efeito
# nenhum, sem que nada acuse o problema.
#
# NÃO faz parte do `deploy` de propósito: recriar tudo a cada publicação
# alargaria a janela de indisponibilidade sem motivo. Rode quando souber que
# mudou uma dessas opções.
#
# Para conferir se a rotação de log pegou:
#   docker inspect tomenu-prod-nginx-1 --format '{{.HostConfig.LogConfig.Config}}'
deploy-recreate:
	$(PROD) up -d --build --remove-orphans --force-recreate
	$(PROD) restart nginx
	@echo "Containers recriados."

# Cria (ou atualiza a senha de) uma conta de staff da plataforma.
#
#   make platform-admin EMAIL=voce@to-menu.com NAME="Seu Nome"
#
# A senha é pedida sem eco. O guard existe porque sem EMAIL o artisan abriria
# um prompt interativo confuso em vez de dizer o que falta.
platform-admin:
	@test -n "$(EMAIL)" || { echo "Uso: make platform-admin EMAIL=voce@to-menu.com [NAME=\"Seu Nome\"]"; exit 1; }
	$(PROD_API) php artisan platform:admin "$(EMAIL)" --name="$(NAME)"

prod-ps:
	$(PROD) ps

prod-logs:
	$(PROD) logs -f --tail=100 api nginx
