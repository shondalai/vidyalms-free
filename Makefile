# GNU Make. Builds the free VidyaLMS packages from this repository.
#
# Run from the repository root. The build needs PHP 8.2 or newer with the zip
# extension, Composer, Node.js 22.12 or newer and npm. When they are not on
# PATH, pass them as variables or put them in local.mk (git-ignored), for example:
#
#   PHP = C:/php/php.exe
#   COMPOSER = $(PHP) C:/tools/composer.phar
#
# Targets:
#   make build       both packages into dist/ (wordpress/ and joomla/)
#   make wordpress   the WordPress plugin only
#   make joomla      the Joomla package only
#   make deps        install the engine, SDK and interface dependencies
#   make test        engine, SDK and interface test suites
#   make clean       remove build output and the packagers' generated copies
#
# Variables:
#   VERSION=X.Y.Z    stamp a version (default: the version in the sources)
#   OUT=dir          where the packages are written (default: dist)
#   WORK=dir         a short staging folder for the WordPress packager on Windows
#   STRICT=          allow build warnings (default: --strict, warnings fail)

.DEFAULT_GOAL := help

PHP ?= php
COMPOSER ?= composer
NODE ?= node
NPM ?= npm
VERSION ?=
OUT ?= dist
WORK ?=
STRICT ?= --strict
COMPOSER_FLAGS ?=

-include local.mk

VERSION_ARG = $(if $(strip $(VERSION)),--version=$(VERSION))
WORK_ARG = $(if $(strip $(WORK)),--work=$(WORK))

.PHONY: help versions deps deps-core deps-core-dev deps-sdk deps-ui sdk ui ui-wordpress ui-joomla \
        wordpress joomla build test test-core test-sdk test-ui lint clean distclean

help:
	@echo "VidyaLMS free packages"
	@echo ""
	@echo "  make deps                 install dependencies (engine, SDK, interface)"
	@echo "  make build                build both packages into $(OUT)/"
	@echo "  make wordpress            build the WordPress plugin"
	@echo "  make joomla               build the Joomla package"
	@echo "  make test                 run the engine, SDK and interface tests"
	@echo "  make lint                 type-check and lint the SDK and the interface"
	@echo "  make clean                remove build output and generated copies"
	@echo "  make distclean            clean, plus node_modules and vendor"
	@echo ""
	@echo "  VERSION=X.Y.Z OUT=dir WORK=dir STRICT=   see the comment at the top of the Makefile"

versions:
	$(PHP) --version
	$(COMPOSER) --version
	$(NODE) --version
	$(NPM) --version

# Dependencies. The engine needs only its runtime dependencies to be packaged;
# its test suite needs the development ones (deps-core-dev).
deps: deps-core deps-sdk deps-ui

deps-core:
	$(COMPOSER) install --no-dev --working-dir=packages/core $(COMPOSER_FLAGS)

deps-core-dev:
	$(COMPOSER) install --working-dir=packages/core $(COMPOSER_FLAGS)

deps-sdk:
	cd packages/api-sdk && $(NPM) ci

deps-ui:
	cd packages/ui && $(NPM) ci

# The generated API client, which the interface builds against.
sdk:
	cd packages/api-sdk && $(NPM) run build

# Two interface builds: WordPress leaves React to WordPress, Joomla bundles it.
ui: ui-wordpress ui-joomla

ui-wordpress: sdk
	cd packages/ui && $(NPM) run build:wordpress

ui-joomla: sdk
	cd packages/ui && $(NPM) run build

# Packages. Each packager copies the engine, compiles the schema and the
# message catalogue, takes the matching interface build and writes its ZIP.
wordpress: ui-wordpress
	$(PHP) packages/adapter-wordpress/build/build.php $(STRICT) $(VERSION_ARG) $(WORK_ARG) --out=$(OUT)/wordpress

joomla: ui-joomla
	$(PHP) packages/adapter-joomla/build/build.php $(STRICT) $(VERSION_ARG) --out=$(OUT)/joomla

build: wordpress joomla

# Tests. The engine's schema tests compare the engine with the files the
# packagers generate, so build first.
test: test-core test-sdk test-ui

test-core: deps-core-dev
	cd packages/core && $(PHP) vendor/bin/phpunit --no-coverage

test-sdk:
	cd packages/api-sdk && $(NPM) test

test-ui:
	cd packages/ui && $(NPM) test

lint:
	cd packages/api-sdk && $(NPM) run type-check && $(NPM) run lint
	cd packages/ui && $(NPM) run type-check && $(NPM) run lint

# Cleaning goes through Node so the same recipe works in sh and cmd.exe.
CLEAN_PATHS = $(OUT) packages/adapter-wordpress/dist packages/adapter-joomla/dist \
        packages/api-sdk/dist packages/ui/dist packages/ui/dist-wordpress \
        packages/adapter-wordpress/vidyalms/core packages/adapter-wordpress/vidyalms/vendor-psr \
        packages/adapter-wordpress/vidyalms/assets/app packages/adapter-wordpress/vidyalms/schema/schema.php \
        packages/adapter-wordpress/vidyalms/src/I18n/catalogue.php packages/adapter-wordpress/vidyalms/src/I18n/gettext.php \
        packages/adapter-wordpress/vidyalms/THIRD-PARTY-NOTICES.txt \
        packages/adapter-joomla/lib_vidyalms_core/src packages/adapter-joomla/com_vidyalms/media/app \
        packages/adapter-joomla/com_vidyalms/admin/sql/install.mysql.utf8.sql \
        packages/adapter-joomla/com_vidyalms/admin/sql/uninstall.mysql.utf8.sql \
        tooling/schema/out tooling/i18n/out

DISTCLEAN_PATHS = packages/core/vendor packages/api-sdk/node_modules packages/ui/node_modules

clean:
	$(NODE) -e "for (const p of process.argv.slice(1)) require('fs').rmSync(p, { recursive: true, force: true })" $(CLEAN_PATHS)

distclean: clean
	$(NODE) -e "for (const p of process.argv.slice(1)) require('fs').rmSync(p, { recursive: true, force: true })" $(DISTCLEAN_PATHS)
