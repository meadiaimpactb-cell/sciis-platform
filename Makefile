# SCIIS platform — developer entry points
# `make help` lists targets. All compose commands read ./.env (copy from .env.example).

SHELL := /bin/bash
COMPOSE ?= docker compose
API      = $(COMPOSE) exec -T api
WEB      = $(COMPOSE) exec -T web

.DEFAULT_GOAL := help

.PHONY: help env key up down restart ps logs build migrate fresh seed shell-api shell-web \
        test-api test-web test lint lint-api lint-web lint-openapi lint-mobile openapi-preview \
        horizon tinker backup restore clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

env: ## Create .env from .env.example if missing
	@test -f .env || (cp .env.example .env && echo ".env created — edit DB/MinIO passwords, then: make key")

key: env ## Generate APP_KEY into .env
	@$(COMPOSE) run --rm --no-deps api php artisan key:generate --show | sed 's/^/APP_KEY=/' > .appkey \
	&& sed -i "s|^APP_KEY=.*|$$(cat .appkey)|" .env && rm .appkey && echo "APP_KEY written"

up: env ## Build and start the full stack
	$(COMPOSE) up -d --build
	@echo "api:     http://localhost:$${API_PORT:-8000}/api/v1/health"
	@echo "web:     http://localhost:$${WEB_PORT:-3000}"
	@echo "mailpit: http://localhost:$${MAILPIT_PORT:-8025}"
	@echo "minio:   http://localhost:$${MINIO_CONSOLE_PORT:-9001}"

down: ## Stop the stack (keeps volumes)
	$(COMPOSE) down

restart: ## Restart api, horizon, scheduler
	$(COMPOSE) restart api horizon scheduler

ps: ## Service status
	$(COMPOSE) ps

logs: ## Tail logs (make logs s=api)
	$(COMPOSE) logs -f --tail=200 $(s)

build: ## Rebuild images without cache
	$(COMPOSE) build --no-cache

migrate: ## Run database migrations
	$(API) php artisan migrate --force

fresh: ## Drop everything and re-migrate + seed (synthetic data only — SEC-04)
	$(API) php artisan migrate:fresh --seed --force

seed: ## Seed demo data (SPEC §11)
	$(API) php artisan db:seed --force

shell-api: ## Shell into the api container
	$(COMPOSE) exec api sh

shell-web: ## Shell into the web container
	$(COMPOSE) exec web sh

tinker: ## Laravel tinker
	$(COMPOSE) exec api php artisan tinker

horizon: ## Open Horizon status
	$(API) php artisan horizon:status

test-api: ## Pest test suite (sqlite in-memory via phpunit.xml env)
	$(API) php artisan test --parallel

test-web: ## Vitest + typecheck
	$(WEB) npm run test --if-present
	$(WEB) npx nuxi typecheck

test: test-api test-web ## All tests

lint-api: ## Pint (PSR-12) + composer audit
	$(API) ./vendor/bin/pint --test
	$(API) composer audit

lint-web: ## ESLint + npm audit
	$(WEB) npm run lint --if-present
	$(WEB) npm audit --audit-level=high

lint-openapi: ## Redocly lint of docs/openapi.yaml
	npx -y @redocly/cli@latest lint docs/openapi.yaml

lint-mobile: ## flutter analyze (requires local Flutter SDK)
	cd mobile && flutter pub get && flutter analyze

lint: lint-api lint-web lint-openapi ## All linters (mobile excluded; run lint-mobile locally)

openapi-preview: ## Serve interactive API docs on :8081
	npx -y @redocly/cli@latest preview-docs docs/openapi.yaml --port 8081

backup: ## Encrypted pg_dump to ./backups (see docs/DEPLOYMENT.md)
	@mkdir -p backups
	$(COMPOSE) exec -T postgres pg_dump -U $${DB_USERNAME:-sciis} -Fc $${DB_DATABASE:-sciis} \
	  | gpg --symmetric --cipher-algo AES256 --batch --passphrase "$${BACKUP_PASSPHRASE:?set BACKUP_PASSPHRASE}" \
	  > backups/sciis-$$(date +%Y%m%d-%H%M%S).dump.gpg
	@ls -lh backups | tail -1

restore: ## Restore from file: make restore f=backups/xxx.dump.gpg
	@test -n "$(f)" || (echo "usage: make restore f=backups/file.dump.gpg" && exit 1)
	gpg --decrypt --batch --passphrase "$${BACKUP_PASSPHRASE:?set BACKUP_PASSPHRASE}" $(f) \
	  | $(COMPOSE) exec -T postgres pg_restore -U $${DB_USERNAME:-sciis} -d $${DB_DATABASE:-sciis} --clean --if-exists --no-owner

clean: ## Stop and remove volumes (DESTROYS LOCAL DATA)
	$(COMPOSE) down -v --remove-orphans
