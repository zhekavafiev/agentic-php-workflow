SHELL := /bin/bash

##
## Execution prefix
## ----------------
## By default commands run in the current shell (backend/ as the working dir).
## Point EXEC at your container runner if you work inside Docker, e.g.:
##   make psalm EXEC="docker compose exec -T backend"
## or set it once in your environment.
##
EXEC        ?=
BACKEND_DIR ?= backend
PHP         ?= php
MAIN_BRANCH ?= main

RUN = cd $(BACKEND_DIR) && $(EXEC)

##
## Code quality
## ------------

lint: ## Check code style (PHP CS Fixer), no changes
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff --verbose
.PHONY: lint

fix-cs: ## Fix code style (PHP CS Fixer)
	$(RUN) vendor/bin/php-cs-fixer fix --diff
.PHONY: fix-cs

psalm: ## Full static analysis with Psalm (https://psalm.dev)
	$(RUN) $(PHP) -d memory_limit=2G vendor/bin/psalm --config=psalm.xml --no-cache
.PHONY: psalm

psalm-diff: ## Psalm on files changed against origin/$(MAIN_BRANCH) only
	@FILES=$$(cd $(BACKEND_DIR) && git diff --name-only --diff-filter=ACMRUXB origin/$(MAIN_BRANCH) -- src/ | grep '\.php$$' | sed 's|^$(BACKEND_DIR)/||'); \
	if [ -z "$$FILES" ]; then \
		echo "No changed PHP files against origin/$(MAIN_BRANCH)"; \
	else \
		echo "Checking:"; echo "$$FILES" | sed 's/^/  /'; \
		$(RUN) $(PHP) -d memory_limit=2G vendor/bin/psalm --config=psalm.xml --no-cache --no-diff $$FILES; \
	fi
.PHONY: psalm-diff

rector: ## Analyse PHP with Rector, no changes (https://getrector.org)
	$(RUN) vendor/bin/rector process --dry-run
.PHONY: rector

rector-fix: ## Apply Rector fixes
	$(RUN) vendor/bin/rector process
.PHONY: rector-fix

deptrac: ## Verify layer boundaries (https://qossmic.github.io/deptrac)
	$(RUN) vendor/bin/deptrac analyse --config-file=deptrac.yaml --fail-on-uncovered --report-uncovered
.PHONY: deptrac

##
## Tests
## -----

test-unit: ## Unit tests (fast, no database)
	$(RUN) vendor/bin/phpunit --testsuite=unit
.PHONY: test-unit

test-integration: ## Integration tests (needs a running database)
	$(RUN) vendor/bin/phpunit --testsuite=integration
.PHONY: test-integration

test-coverage: ## HTML coverage report for unit tests (needs Xdebug or PCOV)
	$(RUN) sh -c 'XDEBUG_MODE=coverage vendor/bin/phpunit --testsuite=unit --coverage-html var/coverage/html'
.PHONY: test-coverage

##
## Composer
## --------

vendor: ## Install Composer dependencies
	$(RUN) composer install --no-interaction --prefer-dist
.PHONY: vendor

composer-validate: ## Validate composer.json
	$(RUN) composer validate --strict --no-check-publish
.PHONY: composer-validate

composer-audit: ## Check dependencies for known vulnerabilities
	$(RUN) composer audit
.PHONY: composer-audit

##
## Aggregates
## ----------

fix-all: rector-fix fix-cs ## Run every automatic fixer
.PHONY: fix-all

check: lint psalm rector deptrac composer-validate composer-audit ## Run every check (what CI runs)
.PHONY: check

help:
	@awk ' \
		BEGIN {RS=""; FS="\n"} \
		function printCommand(line) { \
			split(line, command, ":.*?## "); \
			printf "\033[32m%-24s\033[0m %s\n", command[1], command[2]; \
		} \
		$$1 ~ /^[0-9a-zA-Z_-]+: .*?##/ { printCommand($$1); next; } \
		/^##(\n##.*)+$$/ { gsub("## ?", "\033[33m", $$0); print $$0; next; } \
	' $(MAKEFILE_LIST) && printf "\033[0m"
.PHONY: help
.DEFAULT_GOAL := help
