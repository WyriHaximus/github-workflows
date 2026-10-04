# set all to phony
SHELL=bash

.PHONY: *

#PHP_VERSION:=$(shell docker run --rm -v "`pwd`:`pwd`" jess/jq jq -r -c '.config.platform.php' "`pwd`/composer.json" | php -r "echo str_replace('|', '.', explode('.', implode('|', explode('.', stream_get_contents(STDIN), 2)), 2)[0]);")
PHP_VERSION="8.5"
CONTAINER_NAME=$(shell echo "ghcr.io/wyrihaximusnet/php:${PHP_VERSION}-nts-alpine-dev")
# Avoid parse-time `composer` (Renovate's wrapper) and docker probes; env overrides from renovate-runner.
COMPOSER_CACHE_DIR ?= ${HOME}/.composer-php/cache
COMPOSER_CONTAINER_CACHE_DIR = /opt/app/.composer/cache
TTY_AVAILABLE=$(shell (test -t 1 && echo 0) || echo 1)

ifneq ("$(wildcard /.you-are-in-a-wyrihaximus.net-php-docker-image)","")
    IN_DOCKER=TRUE
else
    IN_DOCKER=FALSE
endif

ifeq ("$(IN_DOCKER)","TRUE")
	DOCKER_RUN:=
	DOCKER_SHELL:=
else
	DOCKER_SECURITY_OPS=--cap-drop=ALL --security-opt="no-new-privileges=true" --user="`id -u`:`id -g`"
	DOCKER_RUN:=docker run --rm -i ${DOCKER_SECURITY_OPS} \
		-v "`pwd`:`pwd`" \
		-v "${COMPOSER_CACHE_DIR}:${COMPOSER_CONTAINER_CACHE_DIR}" \
		-w "`pwd`" \
		${CONTAINER_NAME}
ifeq ($(TTY_AVAILABLE),0)
	DOCKER_SHELL:=docker run --rm -it ${DOCKER_SECURITY_OPS} \
		-v "`pwd`:`pwd`" \
		-v "${COMPOSER_CACHE_DIR}:${COMPOSER_CONTAINER_CACHE_DIR}" \
		-w "`pwd`" \
		${CONTAINER_NAME}
else
	DOCKER_SHELL:=$(DOCKER_RUN)
endif
endif

generate-readme:
	$(DOCKER_RUN) php etc/generate.php

generate: install generate-readme

after-renovate: generate ## Tasks to run after Renovate updates dependencies ####

shell: ## Provides Shell access in the expected environment ####
	$(DOCKER_SHELL) bash

install: ## Install dependencies ####
	$(DOCKER_RUN) sh -ec 'git config --global --add safe.directory "$$(pwd)" && composer install'

update: ## Update dependencies ####
	$(DOCKER_RUN) sh -ec 'git config --global --add safe.directory "$$(pwd)" && composer update -W'

outdated: ## Show outdated dependencies ####
	$(DOCKER_RUN) composer outdated

