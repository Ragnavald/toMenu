.PHONY: up down dev build test fresh api-shell logs

COMPOSE = docker compose
API = $(COMPOSE) run --rm api
# Migrations e seeds usam o papel dono das tabelas; a aplicação em runtime
# conecta com o papel restrito (sem BYPASSRLS), que é o que faz o RLS valer.
API_ADMIN = $(COMPOSE) run --rm -e DB_USERNAME=tomenu -e DB_PASSWORD=secret api
API_TEST = $(COMPOSE) run --rm -e DB_DATABASE=tomenu_test -e DB_USERNAME=tomenu_app api

up:
	$(COMPOSE) up -d postgres redis
	$(API_ADMIN) php artisan migrate --force
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

api-shell:
	$(API) bash

logs:
	$(COMPOSE) logs -f api

down:
	$(COMPOSE) down
