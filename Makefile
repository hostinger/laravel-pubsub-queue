.SILENT:

DOCKER_COMPOSE = docker compose
DOCKER_PHP_CONTAINER_EXEC = $(DOCKER_COMPOSE) exec php

DOCKER_RUN_CMD = docker run --rm -v `pwd`:/app laravel-pubsub-queue-php:latest

help: ## Prints help
	@fgrep -h "##" $(MAKEFILE_LIST) | fgrep -v fgrep | sed -e 's/\\$$//' | sed -e 's/##//'

install: ## Setup the project by installing dependencies and run tests
	make build-image
	${DOCKER_RUN_CMD} composer i
	make test

test: ## Run PHPUnit tests and static analysis
	make test-static
	make test-unit

test-unit: ## Run PHPUnit tests
	$(DOCKER_RUN_CMD) ./vendor/bin/phpunit --bootstrap bootstrap.php --configuration ./phpunit.xml.dist --no-coverage --testsuite Unit

test-static: ## Run static analysis
	$(DOCKER_RUN_CMD) make l-test-static

l-test-static: ## Run static analysis locally (PHPStan/Pint wired but not enforced yet)
	echo "Static analysis is not enforced yet. Run 'make phpstan' or 'make lint-style' manually."

phpstan: ## Run PHPStan static analysis (not yet enforced in CI)
	$(DOCKER_RUN_CMD) php -dmemory_limit=2G ./vendor/bin/phpstan analyse

format: ## Fix code style with Laravel Pint (not yet enforced in CI)
	$(DOCKER_RUN_CMD) ./vendor/bin/pint

lint-style: ## Check code style with Laravel Pint without fixing (not yet enforced in CI)
	$(DOCKER_RUN_CMD) ./vendor/bin/pint --test

build-image: ## Build the docker image
	docker compose build

test-coverage: ## Run PHPUnit tests with coverage
	$(DOCKER_RUN_CMD) php -d error_reporting=32767 ./vendor/bin/phpunit --testsuite Unit --bootstrap vendor/autoload.php --configuration ./phpunit.xml.dist \
	    --coverage-html build/coverage/ --coverage-clover build/clover.xml --coverage-xml build/junit.xml --testdox
