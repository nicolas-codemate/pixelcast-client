.DEFAULT_GOAL := help

# Docker binary (full path to avoid conflict with local docker/ directory)
DOCKER := $(shell which docker)

# Docker compose detection
ifneq ($(shell $(DOCKER) compose version 2>/dev/null),)
	DOCKER_COMPOSE=$(DOCKER) compose
else
	DOCKER_COMPOSE=docker-compose
endif

.PHONY: build build-prod up up-prod down down-prod logs logs-prod shell shell-prod enable-xdebug disable-xdebug test

help:
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m\033[0m\n"} /^[0-9a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-30s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

build: ## build dev
	$(DOCKER_COMPOSE) build --no-cache

build-prod: ## build prod
	$(DOCKER_COMPOSE) -f compose.yaml build --no-cache

up: down ## up dev env
	$(DOCKER_COMPOSE) up -d

up-prod: down-prod ## up prod env
	$(DOCKER_COMPOSE) -f compose.yaml up -d

down:  ## down compose
	$(DOCKER_COMPOSE) down

down-prod:  ## down compose prod
	$(DOCKER_COMPOSE) -f compose.yaml down

logs: ## printout latest logs
	$(DOCKER_COMPOSE) logs php -f

logs-prod: ## printout latest prod logs
	$(DOCKER_COMPOSE) -f compose.yaml logs php -f

shell: ## access to php shell
	$(DOCKER_COMPOSE) exec php sh

shell-prod: ## access to php shell prod
	$(DOCKER_COMPOSE) -f compose.yaml exec php sh

enable-xdebug: ## enable xdebug
	XDEBUG_MODE=debug $(DOCKER_COMPOSE) up -d

disable-xdebug: ## disable xdebug
	XDEBUG_MODE=off $(DOCKER_COMPOSE) up -d

test: ## smoke-check Symfony boot
	$(DOCKER_COMPOSE) exec -T php php bin/console about
