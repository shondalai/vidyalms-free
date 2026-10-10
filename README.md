# VidyaLMS free source

This repository holds the source of the free editions of VidyaLMS, a learning management system
for WordPress and Joomla, as they are packaged for the WordPress.org plugin directory and the
Joomla Extensions Directory:

- the WordPress plugin `vidyalms` (`vidyalms-<version>.zip`)
- the Joomla package `pkg_vidyalms` (`pkg_vidyalms-<version>.zip`): the component `com_vidyalms`,
  the library `lib_vidyalms_core` and six plugins

Both are built from one PHP engine and one React interface. The repository is an export of the
maintainers' development tree and is refreshed for every release; each release commit names the
version it corresponds to.

The paid VidyaLMS Pro add-on (which, among other things, renders certificates as PDF files) is
not part of this repository. The free editions do not need it: without it, certificates are HTML
documents that print from the browser.

## Licence

GPL-3.0-or-later. The full text is in [LICENSE](LICENSE). The third-party JavaScript libraries
compiled into the interface keep their own licences; both packagers collect their notices into
the built packages (`THIRD-PARTY-NOTICES.txt` in the WordPress plugin,
`THIRD-PARTY-LICENSES.txt` beside the interface in the Joomla component).

## Layout

| Path | What it is |
|---|---|
| `packages/core` | The engine (`Shondalai\VidyaLMS\Core`): courses, enrolment, assessment, progress, completion, certificates and notifications. Plain PHP 8.1 with no CMS dependency; each host supplies users, database, files, mail and scheduling through ports. |
| `packages/api-sdk` | The typed REST client the interface uses. Its types are generated from `openapi.yaml`. |
| `packages/ui` | The React interface: course player, quiz runner, learner pages, instructor studio and admin screens. |
| `packages/adapter-wordpress` | The WordPress plugin. `vidyalms/` is the plugin folder and `build/build.php` its packager. |
| `packages/adapter-joomla` | The Joomla package: `com_vidyalms`, `lib_vidyalms_core`, `plugins/` and `pkg_vidyalms.xml`. `build/build.php` is its packager. |
| `tooling/schema` | Compiles `packages/core/schema/tables.php` into WordPress `dbDelta` statements and Joomla SQL. |
| `tooling/i18n` | Compiles the string catalogue `packages/core/i18n/catalogue.php` for both hosts. |
| `tooling/contract` | The port contracts the engine and both adapters are tested against. |
| `tooling/wordpress`, `tooling/releases` | Helpers the packagers call: exception annotation checks, PSR isolation, the interface manifest check and the dependency notices. |
| `tooling/ci/gate.php` | The repository's conformance checks (`php tooling/ci/gate.php --list`). |
| `openapi.yaml` | The REST contract shared by both hosts. |

Each package has a README with more detail. The packagers copy the engine into the extension
folders at build time (`vidyalms/core/` for WordPress, `lib_vidyalms_core/src/` for Joomla), and
the compiled interface into `vidyalms/assets/app/` and `com_vidyalms/media/app/`. Those copies are
generated, ignored by git and rewritten on every build; change `packages/core` and `packages/ui`
instead.

## Requirements

- PHP 8.2 or newer with the `zip` extension, and Composer, to run the packagers. The packages
  themselves need PHP 8.1 or newer on WordPress 6.6 or newer, and PHP 8.3 or newer on Joomla 6.
- Node.js 22.12 or newer and npm, to build the interface.
- No WordPress or Joomla installation is needed to build either package.

On Windows, clone into a short path such as `C:\src\vidyalms-free`. The WordPress packager stages
files several folders deep, and PHP's zip extension cannot read paths longer than 260 characters;
`--work=<short folder>` moves the staging area if the checkout has to live deeper.

## Building with make

A GNU Makefile at the root runs the steps below in order. With PHP, Composer, Node.js and npm on PATH:

```sh
make deps      # composer install for the engine, npm ci for the SDK and the interface
make build     # both packages: dist/wordpress/vidyalms-<version>.zip and dist/joomla/pkg_vidyalms-<version>.zip
```

`make wordpress` and `make joomla` build one package. `VERSION=X.Y.Z` stamps a version, `OUT=<dir>` moves the output, `WORK=<short dir>` moves the WordPress staging area on Windows, and `STRICT=` lets a build finish with warnings. When the tools are not on PATH, put their paths in a git-ignored `local.mk` next to the Makefile (`PHP = C:/php/php.exe`, `COMPOSER = $(PHP) C:/tools/composer.phar`). `make test` runs the engine, SDK and interface suites, `make lint` the type checks and linters, and `make clean` removes the build output and the copies the packagers generate inside the extension folders.

## Building the WordPress plugin

From the repository root:

```sh
composer install --no-dev --working-dir=packages/core

cd packages/api-sdk
npm ci
npm run build

cd ../ui
npm ci
npm run build:wordpress

cd ../..
php packages/adapter-wordpress/build/build.php --strict
```

The plugin is written to `packages/adapter-wordpress/dist/vidyalms-<version>.zip`. Add
`--version=X.Y.Z` to stamp a version and `--out=<folder>` to write the ZIP elsewhere.
`packages/adapter-wordpress/build/README.md` describes every step.

## Building the Joomla package

The engine and the SDK are prepared in the same way. Joomla takes the default interface build,
which bundles React, instead of the WordPress profile:

```sh
composer install --no-dev --working-dir=packages/core

cd packages/api-sdk
npm ci
npm run build

cd ../ui
npm ci
npm run build

cd ../..
php packages/adapter-joomla/build/build.php --strict
```

The package is written to `packages/adapter-joomla/dist/pkg_vidyalms-<version>.zip`, next to the
extension ZIPs it contains. `packages/adapter-joomla/build/README.md` describes every step.

## How the shipped interface relates to packages/ui

The JavaScript and CSS files in the WordPress plugin's `assets/app/` folder are the output of
`npm run build:wordpress` in `packages/ui`, built against `packages/api-sdk` with the lockfiles in
this repository. The packager copies them unchanged except for one thing: it removes the trailing
`sourceMappingURL` comment, because the plugin does not ship source maps. That build profile
leaves React, ReactDOM and the JSX runtime out of the bundle and uses the copies WordPress
provides. The Joomla package carries the output of `npm run build` in `com_vidyalms/media/app/`,
which bundles React itself.

The build is deterministic: the same commit, `npm ci` and the same build command give the same
file names and contents in any folder. The entry files have fixed names (`player.js`,
`quiz-runner.js`, `admin.js` and so on) and the shared chunks carry content hashes, so a chunk
name in a released package identifies the build that produced it. To compare a released plugin
with this source, check out the release commit, run the WordPress steps above and compare
`packages/ui/dist-wordpress/` with the plugin's `assets/app/`.

## Tests

- Engine: build both packages first (the steps above), because the schema tests compare the
  engine's schema with the install SQL and the WordPress schema file the packagers generate. Then
  `composer install --working-dir=packages/core` (with the development dependencies and PHP's
  `pdo_sqlite` extension enabled in `php.ini`, because a few tests run in a separate PHP process
  that does not inherit `-d` flags) and `vendor/bin/phpunit` in `packages/core`. The tests that
  compare the engine with the Pro schema or with internal design documents are skipped here,
  because this repository carries neither.
- Interface and SDK: `npm test` in `packages/ui` and `packages/api-sdk`.
- Conformance: `php tooling/ci/gate.php` runs the development tree's conformance rules
  (`--list` names them). It still reports open findings, interface placeholders and a few
  organisation-scope reads that are being worked through, so today it exits non-zero.

## Issues and pull requests

Bug reports and questions are welcome in this repository's issue tracker. Pull requests are
welcome too and are reviewed. Because this repository is exported from the development tree, an
accepted change is applied there and reaches this repository with the next release export.

Please report security problems privately at https://shondalai.com/contact-us rather than in a public
issue.

## Product page

Documentation, the Pro add-on and support: https://shondalai.com/products/vidyalms
