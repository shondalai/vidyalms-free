# The vidyalms packager

`build/build.php` produces the installable WordPress ZIP on Windows or Linux.
The release builder needs PHP 8.2 or newer, `zip`, Composer and Node.js 22.12 or newer.
The installed plugin still supports PHP 8.1 and WordPress 6.6 or newer.
Before packaging, run `composer install` in `packages/core`, then `npm ci` in `packages/api-sdk`
and `packages/ui`. Build the API SDK with `npm run build` before building the interface.
The free plugin bundles no PDF engine, so neither `packages/pdf-runtime` nor the PHP-Scoper
install in `tooling/wordpress` is needed here; the Pro packager uses both.

```
php build/build.php [--out=<dir>] [--version=X.Y.Z] [--install-to=<plugins dir>] [--strict]
```

| Flag | Default | What it does |
|---|---|---|
| `--out=<dir>` | `dist/` | Where the zip is written. |
| `--version=X.Y.Z` | the `Version:` header of `vidyalms/vidyalms.php`, then the `Stable tag:` of `readme.txt` | The release version, stamped into the build output only. |
| `--install-to=<dir>` | not set | A WordPress `wp-content/plugins` directory. The staged plugin is copied in so a test site picks it up. |
| `--strict` | off | Exit non-zero if the build produced any warning. Use this in CI. |

## What it does

**Step 1: schema and strings.** Runs `tooling/schema/compile.php` and copies its WordPress output
(`dbDelta` statements compiled from `packages/core/schema/tables.php`) to
`vidyalms/schema/schema.php`. It then checks the version half: `Setup\Activator::SCHEMA_VERSION` has
to equal `tooling/schema/version.php`, and no file in `vidyalms/schema/migrations/` may sit above it.
A constant left behind means a site upgrading to the new schema runs neither `dbDelta` nor the
migrations for it, and a migration above the constant is one no site would ever run. Runs
`tooling/i18n/compile.php --wordpress=<dir>` and copies its two WordPress files into
`vidyalms/src/I18n/`: `catalogue.php`, the key to English map compiled from
`packages/core/i18n/catalogue.php`, and `gettext.php`, one literal `_x()` call per key with the key
as context. The translator port resolves a core string key through the catalogue and asks
`gettext.php` for its translation, so both are shipped files, not only build inputs. The literal
calls are what translate.wordpress.org extracts. The plugin ships no POT and no `languages/`
folder: translations arrive as WordPress language packs.

The step also deletes folders older builds generated into the plugin directory and this one no
longer writes (`pdf-runtime/`, `build-source/`, `i18n/`, `languages/`), because the stage step
copies the whole directory. A `languages/` folder holding anything but the old POT stops the build
rather than being deleted.

**Step 2: the core.** WordPress has no library-extension concept, so the CMS-agnostic core ships
inside the plugin. `packages/core/src` is copied to `vidyalms/core/` as a clean copy (the target is
deleted first, so a class removed from the core cannot survive in a build), and
`vidyalms/core/autoload.php` is generated: a small PSR-4 loader for `Shondalai\VidyaLMS\Core\`.

The same step vendors the interface packages the core type-hints and WordPress does not bundle,
`psr/clock` and `psr/log`, into `vidyalms/vendor-psr/` with their licences, and generates
`vidyalms/vendor-psr/autoload.php`. That loader registers a package only when the site does not
already provide it (`interface_exists` on `Psr\Clock\ClockInterface` and `Psr\Log\LoggerInterface`),
so a site running its own Composer autoloader keeps its own copy and nothing is ever declared twice.
Class-to-file maps are read out of the vendored files rather than guessed from paths.

**Step 2b: the learner apps.** The React player and quiz runner from `packages/ui` ship as a copy
of that package's `dist-wordpress/` in `vidyalms/assets/app/` (flat, so the entries' relative ESM imports
resolve; gitignored). When `dist-wordpress/` is missing, incomplete or older than the app sources, the step
runs `npm run build:wordpress` there and fails the build if npm fails; on a machine without node it degrades
to a warning instead (a fresh build still ships as-is; without a build there are no apps), which `--strict`
turns into a failure like any other warning.

Source maps are not copied. They are read first, to prove no chunk bundles its own React or ReactDOM
(the WordPress profile uses the copies WordPress supplies), and the trailing `sourceMappingURL`
comment is stripped from every copied script and stylesheet so nothing points at a missing file. The
readable source lives in the public repository the readme links to,
https://github.com/shondalai/vidyalms-free. The step then writes `vidyalms/THIRD-PARTY-NOTICES.txt`
(see `third-party-notices.php`): the licence texts of the production dependencies recorded in the
`packages/ui` and `packages/api-sdk` lockfiles, with `licenses/` supplying any a package tarball
omits.

**Step 3: stage, stamp, zip.** The plugin directory is copied to `build/.stage/vidyalms`, the
release version is stamped there into the `Version:` plugin header and the `Stable tag:` of
`readme.txt`, and the staged copy is zipped to `dist/vidyalms-<version>.zip` with `vidyalms/` as the
top-level entry, which is what WordPress requires of an uploadable plugin zip. The committed
`vidyalms.php` and `readme.txt` are never rewritten: a release stamps the artefact, not the source
tree.

Before zipping, the staged `assets/` tree is swept once more: any `*.map` is removed and any
`sourceMappingURL` comment stripped, hand-written scripts included. WordPress uses its supplied
React libraries. Set `VIDYALMS_WORDPRESS_VENDOR` to the review site's
`wp-includes/js/dist/vendor` directory to mount-test against those exact libraries.

The ZIP carries no PDF engine. The core registers its PDF certificate renderer only when it is
handed a `PdfPort`, and this plugin asks for one through the `vidyalms_pdf_port` filter, which
the VidyaLMS Pro add-on answers with its own scoped Dompdf runtime. Without it certificates
render as HTML. A ZIP above 10,000,000 bytes fails the build; one above the 6,000,000 byte
budget is a warning, which `--strict` turns into a failure.

**Step 4: install.** With `--install-to`, the staged directory is copied into the given plugins
directory as `vidyalms/`, and the build prints what it wrote. It refuses to delete a directory
called `vidyalms` that is not a VidyaLMS plugin, so a wrong path cannot destroy someone's work.

## Generated files, and the contract around them

Everything below is produced by the packager and gitignored. None of it may be hand-edited: the next
build overwrites it.

| Path in the plugin | Source | Who reads it |
|---|---|---|
| `vidyalms/core/**` | `packages/core/src` | `vidyalms/core/autoload.php` |
| `vidyalms/core/autoload.php` | generated | the plugin bootstrap, once, before anything else |
| `vidyalms/vendor-psr/**` | `packages/core/vendor/psr/{clock,log}` | `vidyalms/vendor-psr/autoload.php` |
| `vidyalms/vendor-psr/autoload.php` | generated | the plugin bootstrap, before `core/autoload.php` |
| `vidyalms/schema/schema.php` | `packages/core/schema/tables.php` | the activation hook: `require` it, replace `{prefix}` with `$wpdb->prefix`, hand each statement to `dbDelta()` |
| `vidyalms/src/I18n/catalogue.php` | `packages/core/i18n/catalogue.php`, via `tooling/i18n/compile.php` | the translator port, at runtime |
| `vidyalms/src/I18n/gettext.php` | the same catalogue | the translator port at runtime, and translate.wordpress.org's string extractor |
| `vidyalms/assets/app/**` | `packages/ui/dist-wordpress`, without maps | the admin and learner screens |
| `vidyalms/THIRD-PARTY-NOTICES.txt` | the `packages/ui` and `packages/api-sdk` lockfiles and `build/licenses/` | readers of the ZIP |

The bootstrap must require the two autoloaders in that order, PSR interfaces first, because the core
type-hints them.

## The other scripts here

None of them is part of the build; all are documented in the adapter README.

| Script | What it answers |
|---|---|
| `smoke.php` | does the whole access spine work against a real site and a real MySQL |
| `port-contract.php` | does `WpDatabase` satisfy the shared database port contract |
| `course-category-port.php` | does the native taxonomy supply exact paging, literal search, hierarchy labels, capability-aware native links and the Categories submenu; pass `--source` before a coordinated package install |
| `kernel-ready.php` | does `vidyalms_kernel_ready` fire, and can a listener register a provider, in all five contexts (site, wp-admin, REST, WP-CLI, WP-Cron) |
| `upgrade.php` | runs the schema upgrade the way `admin_init` runs it, for a site being driven from a terminal |

## Failure discipline

The build fails loudly, with a non-zero exit and one clear line, when a source it cannot invent is
missing: the plugin directory, `packages/core/src`, either compiler, the string catalogue, the core's
Composer autoloader (with the exact `composer install` command to fix it), or either vendored PSR
package. It warns and carries on where a sibling artefact is simply not written yet: a missing
`vidyalms/vidyalms.php` produces every generated file but no zip, so the pipeline can be run and
verified before the plugin entry point exists. `--strict` turns those warnings into a failure.

Running the build twice produces the same tree and the same zip contents: every generated directory
is deleted before it is rewritten, and file lists are sorted so ordering cannot drift.

## Releasing to wordpress.org

1. `php build/build.php --version=X.Y.Z --strict`
2. From the repository root, `php tooling/wordpress/package-test.php --zip=packages/adapter-wordpress/dist/vidyalms-X.Y.Z.zip`.
   It checks the ZIP has `vidyalms/` as its single top-level entry, stays under its 6,000,000
   byte budget, carries no PDF runtime, `build-source/`, source maps, `languages/` or root `i18n/`,
   leaves no `sourceMappingURL` comment in a script or stylesheet, and that every catalogue key in
   `src/I18n` reaches a literal contextual `_x()` call. Add a WordPress root as a second argument
   to also run the gettext checks on a disposable site with that build installed.
3. Commit the version in `vidyalms/vidyalms.php` and the `Stable tag` in `readme.txt` so the
   repository agrees with the release that was published.
4. Copy the unzipped tree into the wordpress.org SVN `trunk/`, then `svn cp trunk tags/X.Y.Z`.
   Screenshots and the banner belong in SVN `assets/`, never in the plugin zip.

Before submission, install the finished ZIP and the official Plugin Check plugin on a disposable
WordPress site. Run `tooling/wordpress/plugin-check.ps1` with `-WordPressRoot`, `-Php` and
`-WpCli`. It retains every finding and returns failure when the checker reports errors,
including low-severity errors, even when WP-CLI itself exits successfully. A scan that
cannot finish also fails. `-SkipPlugins vidyalms-pro` tests Free without loading the
separate Pro companion; this does not disable either plugin on the site. Review the
complete report before uploading.

Directory updates are handled by WordPress; this package supplies no external update server.
Optional external lesson content and commerce services are disclosed in `readme.txt`.
Plugin Check is an aid to review, not approval from the WordPress.org team.

From the repository root, `php tooling/wordpress/submission-audit.php <zip>
<installed-plugin-directory>` verifies the first-release metadata, archive size,
path safety and an exact installed-file match. Keep its JSON output beside the
Plugin Check report and record the SHA-256 of the ZIP actually submitted.

The shared engine's typed exceptions are not HTML. Run
`php tooling/wordpress/exception-review.php --verify` to verify the narrow
exception annotations, and run `packages/adapter-wordpress/tests/directory-boundaries-test.php
<wordpress-root>` to exercise JSON and HTML escaping on a real local WordPress
installation. Preserve an annotation-disabled diagnostic report alongside the
normal checker output; explain justified findings rather than excluding checks.
The annotation tool checks that reported lines belong to throw expressions and that
adding comments leaves every executable token and string unchanged. Its exceptions
apply only to `ExceptionNotEscaped`; actual output checks remain enabled.

The [1.0.0 submission review](../../../docs/technical/wordpress-submission-review-2026-10-05.md)
records the artifact, prerequisites, verification results and remaining reviewer notes.
