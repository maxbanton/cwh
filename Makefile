PHP ?= 8.4
SERVICE := php$(subst .,,$(PHP))
COMPOSE := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
DC := $(COMPOSE) run --rm $(SERVICE)

.PHONY: build build-svc install update test test-coverage lint lint-fix analyse ci ci-full shell matrix clean

build:
	$(COMPOSE) build

build-svc:
	$(COMPOSE) build $(SERVICE)

install:
	@rm -f composer.lock
	$(DC) composer update --no-progress --prefer-dist

update:
	$(DC) composer update

test:
	$(DC) vendor/bin/phpunit

test-coverage:
	$(DC) vendor/bin/phpunit --coverage-text --coverage-clover build/logs/clover.xml

lint:
	$(DC) vendor/bin/phpcs --standard=psr2 --ignore=Tests src/

lint-fix:
	$(DC) vendor/bin/phpcbf --standard=psr2 --ignore=Tests src/

analyse:
	$(DC) vendor/bin/phpstan analyse --level=8 src/

ci: lint test
ci-full: lint analyse test

shell:
	$(DC) bash

matrix:
	@printf '8.2\n8.3\n8.4\n8.5\n' | xargs -n1 -P4 -I{} $(MAKE) PHP={} install ci

clean:
	$(COMPOSE) down -v
