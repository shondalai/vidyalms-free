# Build

`build.php` is the pkg_vidyalms packager: plain PHP CLI, no Composer, no Joomla bootstrap, same behaviour on
Windows and Linux.

```
php build/build.php [--out=<dir>] [--version=X.Y.Z] [--install-to=<joomla root>] [--strict]
```

| Option | Meaning |
|---|---|
| `--out=<dir>` | Where the zips land. Default `packages/adapter-joomla/dist`. |
| `--version=X.Y.Z` | Overrides the version for the package file name and for the manifest copy written into the zip. The manifest on disk is never rewritten. Default: the `<version>` in `pkg_vidyalms.xml`. |
| `--install-to=<joomla root>` | Copies the assembled package into `<joomla root>/tmp` and prints the CLI install command. |
| `--strict` | Turns any skipped extension or warning into a non-zero exit. Use this in CI; leave it off while extensions are still being written. |

Three scripts live beside it and are documented in the adapter README. None is part of the build:

| Script | What it answers |
|---|---|
| `smoke.php` | does the whole access spine work against a real site and a real MySQL |
| `port-contract.php` | do `JoomlaDatabase`, the capability-aware native course-category port and suppression of untruthful category count links satisfy their live MySQL contracts |
| `kernel-ready.php` | does `onVidyaLmsKernelReady` fire, and can a listener register a provider, in all five contexts (site, administrator, api, cli, cron) |

## What a run does

1. **Compile.** Runs `tooling/schema/compile.php` and `tooling/i18n/compile.php` into `build/.generated`, then
   composes their Joomla output into the component:
   - `com_vidyalms/admin/sql/install.mysql.utf8.sql`, the generated core tables followed by
     `build/sql/adapter.install.mysql.utf8.sql` (the adapter-owned job queue)
   - `com_vidyalms/admin/sql/uninstall.mysql.utf8.sql`, the adapter drops followed by the generated ones, so
     nothing outlives the extension it belongs to
   - the generated strings written into `com_vidyalms/admin/language/en-GB/com_vidyalms.ini` between
     `; >>> BEGIN VIDYALMS GENERATED CORE STRINGS` and `; <<< END VIDYALMS GENERATED CORE STRINGS`

   Only the text between those markers is touched. Hand-written component strings outside the block survive
   every run, and the block itself is deleted and rewritten each time, so a removed catalogue key cannot linger.
   The site catalogue gets no copy: `JoomlaTranslator` loads `com_vidyalms` from `JPATH_ADMINISTRATOR` in the
   site and API applications too. Never edit the block or the two SQL files: edit
   `packages/core/i18n/catalogue.php`, `packages/core/schema/tables.php` or `build/sql/` and rebuild.

   Versioned update scripts under `com_vidyalms/admin/sql/updates/mysql` stay hand-owned; the schema compiler
   does not emit them. What the build does check is that one exists for the current schema version, which
   `tooling/schema/version.php` holds for the whole repository. The install script always creates the current
   shape, so a clean install is never wrong; a site that upgrades gets only what that folder holds, and a
   missing file is silent until the first write to a column that never arrived.

2. **Copy the core.** Deletes `lib_vidyalms_core/src` and copies `packages/core/src` into it. The library ships
   no vendor directory: the core needs only `psr/clock` and `psr/log`, both bundled with Joomla 6.

2b. **Bundle the learner apps.** The React player and quiz runner from `packages/ui` ship as a copy of that
   package's `dist/` in `com_vidyalms/media/app` (gitignored): JS and maps under `app/js`, CSS under `app/css`,
   because Joomla's relative asset resolution inserts the `js`/`css` folder itself and every entry and chunk
   must share one directory for the relative ESM imports. When `dist/` is missing, incomplete or older than the
   app sources, the step runs `npm run build` there and fails the build if npm fails; on a machine without node
   it degrades to a warning instead (a fresh `dist/` still ships as-is, no `dist/` ships no apps), which
   `--strict` turns into a failure like any other warning. The asset versions in `media/joomla.asset.json` are
   restamped to the release version inside the zip, like the manifests, because the bundle file names are
   unhashed and that version is the cache-buster.

3. **Zip.** One zip per extension into the output directory, then `pkg_vidyalms-<version>.zip` holding
   `pkg_vidyalms.xml`, `pkg_script.php` and a `packages/` folder with the child zips. The child list comes from
   `pkg_vidyalms.xml` itself, so the manifest is the single source of truth for what ships. Source directories
   are found by name: `plg_*` under `plugins/`, `mod_*` under `modules/`, everything else at the adapter root.

4. **Stage.** With `--install-to`, copies the package zip into `<joomla root>/tmp` and prints:
   `php <joomla root>/cli/joomla.php extension:install --path=<joomla root>/tmp/pkg_vidyalms-<version>.zip`

## The package is the install unit, not the component

`pkg_vidyalms-<version>.zip` is the only supported thing to install. The child zips in `dist/` are build
output, present because the package is assembled from them, and installing one on its own is not a
supported route.

The package installer records the neutral schema version, enables newly installed plugins, and
provisions scheduled tasks, including EasyCommerce access reconciliation. Installing only the
component omits those steps and the shop integration plugin. Financial records remain in EasyCommerce.

## Exit codes and missing pieces

The build fails loudly (exit 1) when something it cannot work around is missing: `packages/core/src`,
`packages/core/vendor/autoload.php` (the schema compiler needs it), either compiler, `lib_vidyalms_core`,
`pkg_vidyalms.xml`, `pkg_script.php`, or a compiler that produced no output.

An extension directory that does not exist yet is a warning, not a failure: the zip is skipped and counted, and
the package is not assembled while any child is missing. That is what a run looks like while extensions are
still being written. Exit is non-zero only when nothing at all was built, or under `--strict`.

Runs are idempotent: zips are replaced, the copied `src` is deleted first, and the generated INI block is
rebuilt rather than appended to.

`.git`, `.gitignore`, `.gitattributes`, `.idea`, `.vscode`, `node_modules`, `.DS_Store` and `Thumbs.db` are kept
out of every zip. File lists are sorted, so two builds of unchanged sources produce the same archive contents.
