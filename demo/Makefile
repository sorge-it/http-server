# Records the demo of the README and the social preview in Docker: no local PHP and no VHS needed.
# `make -C demo all` from the root of the package.

.DEFAULT_GOAL := help

ROOT  := $(abspath $(CURDIR)/..)
IMAGE := http-server-demo
# As the user of the host: files the container writes stay the user's on Linux too.
RUN   := docker run --rm --cpus 2 --user $(shell id -u):$(shell id -g) -e HOME=/tmp -v "$(ROOT):/app" -w /app/demo/app $(IMAGE)

.PHONY: help build install preflight gif png all clean

help: ## Show the targets
	@grep -E '^[a-z]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-10s %s\n", $$1, $$2}'

build: ## Build the image: VHS with PHP, SQLite, Chromium and Composer
	docker build -q -t $(IMAGE) $(CURDIR)

install: build ## Install the demo app with the package from this checkout
	$(RUN) sh -c 'rm -rf vendor composer.lock app.sqlite* && composer install --no-interaction --no-progress -q'

preflight: install ## Check that each scene shows what the recording expects
	$(RUN) sh -c "grep -A3 '^.db = new PDO' test.php | tail -1 | grep -qF \"VALUES ('Ada')\""
	$(RUN) sh -c 'php php-s.php | grep -qx "Users: none"'
	$(RUN) sh -c 'php http-server.php | grep -qx "Users: Ada"'

gif: preflight ## Record output/demo.gif
	mkdir -p output
	$(RUN) vhs ../demo.tape

png: preflight ## Record output/social.png
	mkdir -p output
	$(RUN) vhs ../social.tape

all: gif png ## Record both

clean: ## Remove the installed demo app and its database
	rm -rf app/vendor app/composer.lock app/app.sqlite*
