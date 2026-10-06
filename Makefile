COMPOSE ?= docker compose

.PHONY: up down logs install pest larastan infection pint quality-gate live ready

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f app web worker scheduler

install:
	$(COMPOSE) exec app composer install

pest:
	$(COMPOSE) exec app php vendor/bin/pest --testsuite=Unit,Feature,Integration,Contract,BDD,Architecture --parallel --processes=2

larastan:
	$(COMPOSE) exec app php vendor/bin/phpstan analyse --no-progress

infection:
	$(COMPOSE) exec app php vendor/bin/infection --threads=1 --no-progress

pint:
	$(COMPOSE) exec app php vendor/bin/pint --dirty

# Requer o script composer quality-gate a ser adicionado pelo integrador.
quality-gate:
	$(COMPOSE) exec app composer quality-gate

live:
	curl --fail --silent --show-error $${APP_URL:-http://localhost:8080}/api/health/live

ready:
	curl --fail --silent --show-error $${APP_URL:-http://localhost:8080}/api/health/ready
