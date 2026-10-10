# VidyaLMS Joomla adapter (pkg_vidyalms)

The Joomla 6 shell around the CMS-agnostic VidyaLMS core. It supplies the core with users, database,
permissions, files, mail, scheduling, events, commerce, translation and caching, and adds routing, server
views and a REST mount. It contains no learning rules: every write goes through a core use case, and
anything that passes the contract suite on WordPress and fails here is an adapter bug by definition.

Requires Joomla 6.0 and PHP 8.3.

## Layout

| Directory | Ships as | What it is |
|---|---|---|
| `lib_vidyalms_core/` | library `vidyalms_core` | The pure core, autoloaded by Joomla's own PSR-4 map under `Shondalai\VidyaLMS\Core`. `src/` is a build-time copy of `packages/core/src` and is gitignored: fix core bugs in the core, never here. Joomla 6 already bundles psr/clock and psr/log, so no vendor directory ships. |
| `com_vidyalms/` | component `com_vidyalms` | Namespace root `VidyaLMS\Component\VidyaLMS`. `admin/` holds the port implementations, the kernel factory, the administrator screens (React mounts, plus the server-rendered Import screen) and the passthrough shared with the site; `site/` the router, menu item types and server-rendered pages; `api/` the single REST passthrough controller. |
| `plugins/plg_webservices_vidyalms/` | plugin `webservices/vidyalms` | Mounts `/api/index.php/v1/vidyalms` on the Joomla API router. |
| `plugins/plg_system_vidyalms/` | plugin `system/vidyalms` | Boots the kernel early and publishes the complete map of learning events, `onVidyaLmsEnrolmentCreated` through `onVidyaLmsCourseArchived`, fanning two of them out to the action log. |
| `plugins/plg_system_vidyalms_easycommerce/` | plugin `system/vidyalms_easycommerce` | EasyCommerce course mapping, checkout account enforcement, shop price/purchase hooks and durable access synchronization. |
| `plugins/plg_task_vidyalms/` | plugin `task/vidyalms` | Scheduled Tasks routines: reconcile EasyCommerce access, drain the job queue, start every registered sweep, expire entitlements, prune spent records and expired import sources. |
| `plugins/plg_privacy_vidyalms/` | plugin `privacy/vidyalms` | Personal data export and erasure. |
| `plugins/plg_finder_vidyalms/` | plugin `finder/vidyalms` | Smart Search indexing of courses. |
| `build/` | not shipped | The packager (`lib/packager.php` is shared with the Pro packager), the smoke harness, the kernel-ready probe and the adapter-owned SQL. |

## EasyCommerce setup and access synchronization

The package installs and enables **System - VidyaLMS EasyCommerce**. It remains inert if
EasyCommerce or VidyaLMS is absent or disabled. The LMS shop setup link opens Joomla's plugin
manager filtered to this integration.

1. Set each course's pricing mode to **Paid** in the course editor. The integration's course
   selector lists only paid courses, and the runtime also rejects free-course mappings.
2. Open the integration plugin. Map each EasyCommerce product ID to its courses. Leave variation
   ID at zero for the product default; an explicit variation mapping overrides that default.
3. Select an existing Joomla account allowed to **Enrol others** on every mapped course, including
   courses whose older grants may need revocation. Leaving the account empty uses the core's
   configured background actor. Missing permission leaves the work queued for retry.
4. Keep **Require an account** enabled. Mapped checkout items then require a signed-in account.
   A guest order never grants access by matching a billing email, even with this option disabled.
5. Ensure Joomla Scheduled Tasks runs **Synchronize EasyCommerce access**, provisioned every five
   minutes. Administrators can run that task immediately after setup or to retry pending work.

EasyCommerce owns checkout, payments, refunds and subscriptions. The integration only reads shop
facts and calls `CoreKernel::syncShopAccess`; it has no payment credentials or payment calls.
Course prices use the existing CMS-shop price hook, in the shop currency and marked provisional;
the purchase hook opens the shop product. Learner navigation links directly to the shop account,
orders and subscriptions.

Events enqueue work within the shop transaction. When that request reaches Joomla's typed
`onAfterRespond` event, the plugin attempts one fast pass for that request's orders and related
subscriptions, under the same database lock used by cron. It processes at most ten records and
checks a two-second budget between records; an in-flight access transaction always finishes.
It skips CLI applications, open transactions, disabled autocommit and unknown connection state.
Transaction state is checked through PDO or MariaDB's session variables. MySQL connections through
mysqli use cron because that driver exposes no supported equivalent state proof.
A rollback removes its queued work. Unrelated requests do not start a pass, and overflow, lock
contention, failures or requests that bypass Joomla's response event remain for cron.

Workers reread the current order/subscription,
so delayed event payloads cannot reactivate refunded purchases. Orders grant access only after
confirmed payment and committed allocation. Recurring items grant access through the subscription,
never a permanent order grant. Pause, cancellation, expiry and refund revoke the linked access;
renewal and resume reactivate it through the current paid period. Pending cancellation/pause keeps
access until that period ends. Grants from other purchases and manual enrolment remain intact.

Purchase mappings are frozen when the integration first observes an order with configured mappings.
Historical orders receive their snapshot on discovery. A completely unconfigured integration does
not freeze empty snapshots during setup. Later catalogue mapping edits affect new purchases only;
they do not rewrite an existing paid order's courses. A subscription retains its mapping until its
actual product/variation changes. An applied plan change snapshots the new plan's courses while
correlating the original paid order with the earliest applied change's original product/variation.
Deleting a mapping does not prevent later refunds or cancellations from revoking its old grants.

Full-order refunds revoke access. Explicit cumulative `refunded_quantity` on a line, or in its
metadata, revokes that line only when no purchased units remain. The current EasyCommerce refund
service records amounts without line quantities: an amount-only partial refund retains access.
The LMS deliberately does not calculate financial allocations or infer quantities from amounts.

The plugin owns `#__vidyalms_easycommerce_sync` and `#__vidyalms_easycommerce_cursors`.
Failures retry indefinitely with delays from one minute to one hour, subject to the scheduler's
cadence. A rotating scan discovers 25 orders and 25 subscriptions per run; known objects are
rechecked hourly, including deleted objects. New notifications make them due immediately. A
connection-owned database lock prevents overlapping workers, and notification revisions preserve
events received during a run. The queue's error classification and Joomla log identify failures
without storing event payloads. Keep these tables when changing mappings; they hold purchase
snapshots and retry state.

The integration tests use the real core access service, Joomla database queries and EasyCommerce
event classes without booting or installing a live site:

```powershell
$env:PHPRC = '<folder holding a php.ini with pdo_sqlite, sqlite3 and mbstring>'
php packages/core/vendor/bin/phpunit -c tooling/phpunit.xml.dist --filter EasyCommerceJoomla
```

Run from the repository root with PDO SQLite, SQLite3 and mbstring enabled. Set
`VIDYALMS_JOOMLA_TEST_ROOT` to a Joomla source checkout; EasyCommerce event sources are read from the sibling `easycommerce` folder.
The optional `VIDYALMS_EASYCOMMERCE_TEST_MYSQL_CONFIG` variable points to a Joomla configuration
file for the actual mysqli guard test. It opens its own connection, reads session variables and
uses an empty transaction/savepoint; it reads and writes no site records and prints no credentials.

## How the ports map onto Joomla

Every port is a shared service on the **global** container (`Factory::getContainer()`), registered by
`com_vidyalms/admin/services/provider.php`, so the site app, the API app and every plugin resolve one
instance. The service ids are the port interface FQCNs, plus `Ports::class`, `CoreKernel::class`, the
alias `vidyalms.kernel` and the database alias `vidyalms.db`.

| Port | Implementation | Notes |
|---|---|---|
| Database | `Port\JoomlaDatabase` | `DatabaseDriver`, tables `#__vidyalms_*`. Compiles the core `Query` with `quoteName()` and bound parameters only; plain, dot-qualified and `AS`-aliased identifiers are the whole accepted grammar. |
| Users | `Port\JoomlaUsers` | `UserFactory`. A guest is filtered out rather than handed to the core as an account. |
| Course categories | `Port\JoomlaCourseCategories` | Reads `#__categories` for `extension=com_vidyalms` with literal own-name search, stable server-side paging and full ancestry labels for only the returned page. Root parent `1` becomes `null`; native state crosses explicitly, while manager/edit destinations are returned only when the current Joomla identity holds `core.manage` and the applicable `core.edit`. Native item-count badges stay suppressed until their links can target the SPA's category-filtered course list. A concurrent native delete can still race an LMS write because the cross-host port promises a read, not a portable lock; the stored id then remains recoverable as a missing category. |
| Authorization | `Port\JoomlaAuthorization` | ACL actions from `admin/access.xml`. `Capability::ADMIN` maps to `core.admin`, `MANAGE` to `core.manage`, everything else to the identically named `vidyalms.*` action. `course:42` scopes to the asset `com_vidyalms.course.42`, falling back to the component asset. |
| Storage | `Port\JoomlaStorage` | Public assets use `images/vidyalms`. Define `VIDYALMS_PRIVATE_DIR` in a server bootstrap loaded by web, API and scheduled workers, using an absolute dedicated directory outside all public roots. Private I/O at the default `media/com_vidyalms/private` fails closed before creating directories or guards. The runtime `private_storage_path` setting cannot select the target. Paths, symlinks and filesystem aliases are checked by the shared boundary. Signed downloads use `task=file.download`; guards are defense in depth only. Uploads share the core per-user limits (512 MiB, 500 files, 50 files/hour), including unattached files and import sources. Writes are atomic; incomplete temporary files are removed on failure. Moodle uses validated resource bytes and generated content-addressed filenames. Saved-file orphan expiry is not automatic; existing import-source retention still applies. |
| Mailer | `Port\JoomlaMailer` | A message the core rendered is sent as authored, multipart with the plain part first; a template-addressed one goes through `MailTemplate` when a template with id `com_vidyalms.<key>` is registered, and otherwise through the catalogue's `<key>.subject` and `.body`. Both carry the header map verbatim and PHPMailer's `Sender` as the envelope sender. See [the mail port](#the-mail-port-and-what-php-mail-does-to-a-structured-header). |
| Scheduler | `Port\JoomlaScheduler` | Writes `#__vidyalms_joomla_jobs`, drained by `plg_task_vidyalms`. The table is adapter-owned and deliberately outside the neutral schema, because the same port on WordPress is Action Scheduler. |
| Events | `Port\JoomlaEventBridge` | `vidyalms.course.completed` becomes `onVidyaLmsCourseCompleted`, carrying `eventName`, `idempotencyKey`, `occurredAt` and `payload`. The mapping is mechanical, so a core event added tomorrow arrives without a change here; `plg_system_vidyalms` carries the authoritative list of names. Listeners dedupe on the key; a listener that throws is logged and swallowed. |
| Commerce | `Port\JoomlaCommerce` | Asks `onVidyaLmsGetCoursePrice` and `onVidyaLmsGetCoursePurchaseUrl`; a shop plugin appends to the event's `result`. First usable answer wins. |
| I18n | `Port\JoomlaTranslator` | `vidyalms.mail.enrolled.subject` resolves to `COM_VIDYALMS_MAIL_ENROLLED_SUBJECT`, loaded from `JPATH_ADMINISTRATOR` in every application. |
| Cache | `Port\JoomlaCache` | Joomla cache, group `com_vidyalms`, values wrapped so a cached `false` is not read as a miss. |
| Clock | `Port\SystemClock` | Always UTC. |
| Random | `Port\JoomlaRandom` | `random_bytes` and `random_int`; verification codes drop the O/0 and I/1 pairs. |
| Logger | Joomla's `Psr\Log\LoggerInterface` | Not re-registered by this package. |

The one entry point is
`VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::kernel()`. It boots `com_vidyalms`
first when the container has no kernel yet, so plugins, scheduled tasks and CLI scripts get the same
instance whatever order Joomla woke them in. Nothing constructs a second `CoreKernel`: that would mean a
second event bus and a second idempotency store.

## Extending the kernel: `onVidyaLmsKernelReady`

The core's extension points live on `$kernel->registries`, and the moment to reach them is the kernel-ready
event. `KernelFactory::create()` dispatches it on the application dispatcher once per request, after the
`CoreKernel` is built and before any caller receives it:

| | |
|---|---|
| Event | `onVidyaLmsKernelReady` (`KernelFactory::KERNEL_READY_EVENT`) |
| Dispatched on | `Factory::getApplication()->getDispatcher()`, the same dispatcher `PluginHelper::importPlugin()` puts plugin subscribers on |
| Arguments | `kernel` and `subject`, both the one `CoreKernel` for this request. Nothing else, ever: a listener that needs more asks the kernel |
| Fires | Once per request, lazily, the first time anything resolves `CoreKernel::class` from the global container |
| A listener may | `register()` and `replace()` on any of the sixteen registries, add routes with `addRoutes()`, and read `$kernel->registries` |
| A listener must not | keep the kernel past the request, or resolve `CoreKernel` from the container while inside the listener |

**Every context it fires in**, proved by `build/kernel-ready.php` rather than asserted here:

| Context | Application | Reached through |
|---|---|---|
| Site request | `SiteApplication` | the component's views and models, through `KernelFactory::kernel()` |
| Administrator request | `AdministratorApplication` | the manager screens, the same way |
| API application | `ApiApplication` | `Api\Service\KernelLocator`, the same way |
| CLI | `ConsoleApplication` | any script or console command, the same way |
| Cron | `ConsoleApplication` | `plg_task_vidyalms`, the same way |

```
php build/kernel-ready.php [--joomla=<joomla root>]
```

One process per context. In each it does what a Pro package's plugin does: puts a listener on the
application dispatcher, lets the kernel be built, registers a provider into a registry from inside
the listener, and then has the core run it. It writes nothing.

The cron row is the one with a trap behind it. A web request reaches the component through
`plg_system_vidyalms` on `onAfterInitialise`, and booting the component is what puts the kernel on
the container. **`ConsoleApplication` dispatches no `onAfterInitialise` at all**, so on
`php cli/joomla.php scheduler:run`, which is how a production site runs its scheduler, nothing has
booted the component and the container has never heard of the kernel. A caller that asks the
container directly gets a `KeyNotFoundException` there and nowhere else. `KernelFactory::kernel()`
boots the component when the service is absent, which is why it is the documented way in for a
plugin, a module or a CLI command, and why `plg_task_vidyalms` uses it rather than the container.

```php
public function onVidyaLmsKernelReady(EventInterface $event): void
{
    $kernel = $event->getArgument('kernel');

    // A new key: register() throws DuplicateProviderException if it is taken.
    $kernel->registries->enrolmentRules->register(new CohortEnrolmentRule());

    // An existing key: replace() requires the key to be there and hands back
    // what it displaced, so the override decorates the previous provider
    // instead of reimplementing it.
    $renderers = $kernel->registries->certificateRenderers;
    $renderers->replace(new PdfCertificateRenderer($renderers->get('html')));
}
```

Three rules for a listener:

- **Use the kernel the event carries.** Resolving `CoreKernel` from the container inside a listener re-enters
  the factory, because the container caches a shared service only once its factory has returned.
- **Throwing is not fatal.** A listener that throws is logged and swallowed; whatever it registered before it
  threw stays registered. A third-party plugin cannot take the site down at boot.
- **There is no `unregister()`.** Stored rows reference provider keys (`lessons.type`, `questions.type`,
  `entitlements.source`), so the core keeps every key resolvable. Turning a capability off is a `replace()`
  with an implementation that refuses.

The adapter uses the seam itself before dispatching, which is why a listener that replaces one of the same
keys wraps the site's policy rather than dropping it. Today that is one policy: the `self_enrolment` option
replaces the free build's `open` enrolment rule with `Provider\SiteSelfEnrolmentRule`, which refuses while
the switch is off and delegates to the free rule otherwise. Because it is a provider and not a controller
guard, it holds on the course page, on a hand-built POST to `task=enrol` and on `POST /enrolments` alike:
all three reach `SelfEnrol`, which resolves the rule when it runs and raises `ConflictException`, answered as
409 `application/problem+json` on the REST mount. The switch is read from the component configuration, not
from `Factory::getApplication()->getParams()`, because the merged menu params the site application returns do
not exist in the API application.

## Adding a bundle to a mount: `onVidyaLmsMountAssets`

`onVidyaLmsKernelReady` is the server half of an add-on. The client half is a JavaScript bundle that
has to be on the page rendering the mount, and there is no CMS-neutral place to say so: only this
component knows which mount is being rendered, what the free bundle for it is called, and that the
free bundle must run first, because it is what creates `window.vidyalms.questionTypes`. So this
component owns the decision and publishes the seam.

| | |
|---|---|
| Event | `onVidyaLmsMountAssets` (`Administrator\Service\MountAssets::EVENT`) |
| Dispatched on | `Factory::getApplication()->getDispatcher()` |
| Arguments | `registry` (a `MountAssetRegistry`), `context` (a `MountAssetContext`), and `subject`, the registry again |
| Fires | Once per rendered mount, from `MountAssets::attach()`, before any asset is enabled |
| Mounts | `player`, `quiz-runner` (site), `instructor`, `admin` (administrator), as `MountAssets::MOUNT_*` |
| A listener may | `register()` a bundle under a new name, `replace()` one another extension registered and receive what it displaced |
| A listener must not | expect to be the only one, or to run in any particular order relative to other listeners |

```php
public function onVidyaLmsMountAssets(EventInterface $event): void
{
    $event->getArgument('registry')->register(new MountAssetBundle(
        'vidyalms_pro.app.question-types',            // the web asset name
        [MountAssets::MOUNT_QUIZ_RUNNER, MountAssets::MOUNT_INSTRUCTOR],
        'vidyalms_pro/app/pro-question-types.js',     // media-relative script uri
    ));
}
```

Four facts a listener can rely on:

- **The free bundle is enabled first, and is added to every contributed bundle's dependencies.** A
  listener never names it, because which bundle it is depends on the mount, and naming both would
  drag the workbench app onto a learner's quiz page. Order is declared through the web asset graph
  rather than hoped for from insertion order.
- **Attributes default to `defer`.** The free bundles are ES modules, which the browser defers; a
  classic script without `defer` would execute before them and find no registry to register into.
- **The version is Joomla's.** A web asset registered without one carries `auto`, which renders the
  site's media version, and that changes whenever an extension is installed or updated. A
  contributed bundle cache-busts on its own release without this component knowing its version.
- **A listener that throws is caught**, and so is a bundle that fails to register. The page still
  renders with the free bundle, and a stored Pro-typed item falls back to the core's placeholder
  exactly as it does on a site with no Pro at all.

The registry follows the same three rules as the core's: `register()` refuses a duplicate name so an
override is never an accident of plugin ordering, `replace()` insists the name is there and hands
back what it displaced, and there is no `unregister()`. A bundle naming a mount this component does
not have is refused at construction, so a typo is a message in the log rather than a renderer that
silently never loads.

`AppView::attachAssets()`, `Site\View\Player\HtmlView` and `Site\View\Course\HtmlView` all route
through `MountAssets::attach()`, which is also where the free bundle's own
`joomla.asset.json`-or-fallback registration now lives, once instead of three times.

`packages/pro-joomla/build/mount-assets.php` proves the seam end to end against a real
`SiteApplication` and `AdministratorApplication`, printing the script tags a browser would receive.

## Building

```
php build/build.php --version=1.0.0
```

The packager compiles the neutral schema and string catalogue, copies the core into the library, zips
each extension and assembles `dist/pkg_vidyalms-<version>.zip`.

Its helpers live in `build/lib/packager.php`, which `packages/pro-joomla/build/build.php` requires
too. Neither owns a copy: two packagers each carrying their own zip writer, exclude list and
manifest restamper drift, and a release then differs between the free and the Pro lane for reasons
nobody can see in a diff. Nothing in that file knows which package is being built.

Three outputs are generated and must never be hand-edited (all are gitignored):

- `com_vidyalms/admin/sql/install.mysql.utf8.sql` (core schema from `packages/core/schema/tables.php`
  plus `build/sql/adapter.install.mysql.utf8.sql`)
- `com_vidyalms/admin/sql/uninstall.mysql.utf8.sql`
- the block between the `VIDYALMS GENERATED CORE STRINGS` markers at the end of
  `com_vidyalms/admin/language/en-GB/com_vidyalms.ini`
- `com_vidyalms/media/app/` (the learner app bundles, copied from `packages/ui/dist`)

The React app step (2b) runs `npm run build` in `packages/ui` when `dist/` is missing, incomplete or
older than the app sources, then copies all four apps (player, quiz runner, instructor workbench,
admin) into `com_vidyalms/media/app/js` and `.../app/css` (Joomla's relative asset resolution inserts
the `js`/`css` folder itself, and all entry and chunk files must share one directory so their
relative ESM imports resolve). Without node on the machine the step degrades to a warning: a fresh
`dist/` is still shipped as-is, no `dist/` means the package ships without the apps. The bundle file
names are unhashed; `media/joomla.asset.json` carries the version that cache-busts them, and the
build restamps every asset version inside the zip the same way it restamps the manifests.

`--install-to=<joomla root>` also stages the zip in that site's `tmp`. `--strict` turns any warning
into a failure.

Step 1 also checks the schema. `tooling/schema/version.php` holds one number for the whole
repository, and the build fails when `com_vidyalms/admin/sql/updates/mysql/<version>.sql` is not
there. The install script always creates the current shape, so a site that installs clean is never
wrong; a site that upgrades gets nothing but what the update folder holds, and the failure is silent
until it writes to a column it never got, on the upgraded sites and nowhere else.

`--version` stamps the release version into every manifest inside the zips: the package and all seven
child extensions. Joomla decides whether to upgrade an extension by comparing the version in that
extension's own manifest, so stamping only the package would ship an update that silently skips the
component. The manifests on disk keep their committed version, which means a release is one build
command and never a hand edit across eight files.

A clean build reports `0 skipped, 0 warning(s)`.

## Installing

```
php <joomla root>/cli/joomla.php extension:install --path=<absolute path>/dist/pkg_vidyalms-1.0.0.zip
```

Installing over an existing install is an update, and an update is what runs
`com_vidyalms/admin/sql/updates/mysql/`: Joomla executes every file whose name is above the version in
`#__schemas` for this extension, in version order, then records the highest. A fresh install runs none
of them, because `install.mysql.utf8.sql` has already created the current shape and the installer
records the newest update file as the version.

Those files are hand written, one per schema version, and they carry the DDL and the data. Two
conventions, both Joomla's, and both load-bearing:

- **one clause per statement**, never a multi-clause `ALTER`. Joomla's Database fix screen builds its
  check query from a single-clause `ALTER TABLE ... ADD COLUMN` and skips anything else, so a
  multi-clause statement means a site that lost a column is never told.
- **every DDL statement ends with Joomla's `CAN FAIL` marker** (the literal `/** CAN FAIL **/`
  immediately before the semicolon), which makes a failed statement non-fatal. The installer records the schema version only once the whole file has run, so a file
  that dies half way is re-run from the top, and a bare `ADD COLUMN` would then fail for ever.

The data statements carry no marker and are written to be repeatable instead: each either matches only
rows nothing has touched yet, or writes the value it would write again.

The package script enables a plugin only the first time that plugin is installed, because Joomla installs
plugins disabled and four of the five are load-bearing: without the webservices plugin the REST surface is
dormant, without the system plugin nothing boots the kernel outside the component, without the task plugin
the job queue never drains. It records which plugins already exist in preflight, so an upgrade leaves every
plugin exactly as the administrator set it, while a plugin added in a later release still arrives enabled.
The script also registers VidyaLMS with the User Actions Log.

Uninstalling the package removes the library, the component, all five plugins and the learning tables. Each
`<file>` id in `pkg_vidyalms.xml` is the extension's element as stored in `#__extensions`, which is what the
package uninstaller matches on: a component's element carries the `com_` prefix, and an id without it
installs cleanly and then silently orphans the component, its files and its tables.

## The React apps and the two API passthroughs

All four apps from `packages/ui` run in this component: the player and the quiz runner on site pages,
the admin screens and the instructor workbench inside the administrator. Each page renders one mount
div carrying the whole host contract in a single attribute and loads one ES-module entry through the
web asset manager (`com_vidyalms.app.player`, `.quiz-runner`, `.instructor`, `.admin` in
`media/joomla.asset.json`):

```html
<div id="vidyalms-player" data-vy-config='{"api":{...},"course":{...},...}'></div>
```

- **The player page** is the site view `player`, menu-item capable and SEF-routed under the course:
  `/courses/{slug}/player`, or `index.php?option=com_vidyalms&view=player&slug=<slug>`. An optional
  `vy-lesson=<id>` deep-links a lesson. Guests are redirected to login (with a return URL) unless the
  course has at least one free-preview lesson. The course page and My Learning link here (Resume /
  Continue learning), and for a visitor without an enrolment the course page links each free-preview
  lesson's title here with `vy-lesson` (`View\Course\HtmlView::$previewLessonUrls`).
- **The quiz runner** mounts on the handoff URL the player builds: the course URL plus
  `vy-quiz=<lessonId>`. The course view sees that parameter, requires login, and renders the
  quiz-runner mount (`tmpl/course/quiz.php`) instead of the course page.
- **The admin screens** are the administrator views `dashboard`, `courses`, `enrolments`, `learners`,
  `certificates` and `settings`, one submenu entry each. Every one of them is the same `admin` bundle
  mounted on `#vidyalms-admin`; the config's `screen` says which entry rendered it, and the app
  navigates client side only inside that screen. They replaced the PHP dashboard, the PHP course and
  enrolment lists and the PHP course form: creating, editing, publishing and archiving a course all go
  through the `/v1` management surface now, so there is one code path per rule rather than one per
  screen. Import is still a server-rendered screen, because bytes never travel through the API.
- **The instructor workbench** is the administrator view `workbench`:
  `index.php?option=com_vidyalms&view=workbench&id=<course>` opens one course and its five tabs, and
  the same URL without an `id` renders the caller's own course list. The slug in the mount config
  comes from `GetManagedCourse`, which applies the per-course rule; a caller who may not read that
  course gets an empty slug, and the app renders the refusal the API gives it, so the rule is never
  decided twice.
- **The instructor page** is the same workbench bundle on a site page: the site view `instructor`,
  menu-item capable and SEF-routed, `index.php?option=com_vidyalms&view=instructor` for the caller's
  own course list and `&id=<course>` for one course. The course id rides as a query parameter rather
  than a key segment on purpose, because `NomenuRules` reads a key segment as core's `{id}-{alias}`
  pair, which is the trap the slug views already had to be defended from.

  `Site\Service\MountConfig::forInstructor()` writes the same contract `AdminMountConfig::workbench()`
  writes, key for key, with site URLs: the passthrough of the *site* application, the site upload
  task, the player for "preview as learner", and the instructor page itself as the course list. The
  two URL templates are routed with XHTML escaping off, because they go into a JSON attribute rather
  than into markup and an app following `?id=5&amp;Itemid=101` would be asking for a parameter called
  `amp;Itemid`.

  The page decides exactly two things: a guest is sent to the login form with a return URL, and
  everybody else gets the mount. There is no capability check here and no edition check anywhere. Who
  may teach is the core's answer to `GET /manage/courses`, so a learner who finds the URL is refused
  by the API and the app renders the permission state that was designed for it. What Pro adds is not
  the page but a ready-made Instructors group to put a teacher in; see
  [the Pro package README](../pro-joomla/README.md#the-instructors-group).
- **The two API passthroughs** exist because the browser session never authenticates against the
  Joomla API application (it authenticates with API tokens). `Administrator\Service\ApiPassthrough`
  serves the same core `ApiKernel` from a CMS session: the contract path travels URL-encoded in the
  `path` query parameter (the SDK's "query" URL mode), the principal is that application's identity,
  and the kernel's response is emitted verbatim with its status and headers. It is mounted twice under
  the identical task, because Joomla's administrator session is not its site session and carries its
  own form token: `site/src/Controller/ApiController.php` for the learner apps and
  `admin/src/Controller/ApiController.php` for the administrator apps, both `task=api.route`. Every
  request must carry that application's session token in `X-CSRF-Token` (the header Joomla's own
  `Session::checkToken()` reads); each mount config hands the right token to its apps. Guests keep
  working for public paths, and the core still owns routing, authorization and every status code, so
  both surfaces answer byte-identically to `/api/index.php/v1/vidyalms` and to the WordPress mount.

- **The upload endpoint** rides the same family, `task=api.upload`, mounted on both applications for
  the same reason and behind the same session token. It is not on the contract and must never be:
  no bytes travel through `/v1`, exactly as with imports. `Administrator\Service\ApiUpload` does the
  transport (the token, the multipart parsing, the capability check through the core's `AccessGuard`,
  the emit) and `Service\UploadIntake` does the rest: extension against the purpose's allowlist,
  media type sniffed from the bytes with `fileinfo` and required to match that extension, a raster
  decode for an image, a generated storage path that never carries the client's file name verbatim,
  then `putPrivate` for a `resource` and `putPublic` for an `image`. Which purposes exist is
  `Service\UploadPolicy`, a registry an extension adds to on `onVidyaLmsUploadPolicy`
  (`register()` throws on a duplicate key, `replace()` hands back what it displaced). The two
  ceilings are component options; PHP's own limits still apply on top. The apps are handed the URL
  as `urls.upload` in the mount config, because the SDK's api base only reaches contract paths. See
  [04-api-contract.md](../../docs/technical/04-api-contract.md).

The mount config's `api` block is therefore
`{"base":"<root>/index.php?option=com_vidyalms&task=api.route&path=","mode":"query","headers":{"X-CSRF-Token":"<token>"}}`,
with `<root>` the site root on the learner pages and the administrator root on the administrator
pages. Everything else in the config is built by `site/src/Service/MountConfig.php` for the learner
apps and by `admin/src/Service/AdminMountConfig.php` for the administrator apps; both shapes are
fixed and shared with the WordPress adapter.

The administrator URLs handed to the apps are plain `index.php` URLs rather than routed ones, because
two of them are templates carrying a `{course_id}` or a `{slug}` token the app substitutes and a
router would percent-encode the braces. `urls.preview` opens the learner player and is therefore built
from the site root, which is `Uri::root()` with the administrator segment taken back off: `Uri::root()`
is derived from `Uri::base()` and carries `/administrator` while the administrator application is the
one running.

| Administrator screen | URL |
|---|---|
| Dashboard | `administrator/index.php?option=com_vidyalms&view=dashboard` |
| Courses | `administrator/index.php?option=com_vidyalms&view=courses` |
| Enrolments | `administrator/index.php?option=com_vidyalms&view=enrolments` |
| Learners | `administrator/index.php?option=com_vidyalms&view=learners` |
| Certificates | `administrator/index.php?option=com_vidyalms&view=certificates` |
| Settings (read only; the editable options are the component's own Options screen) | `administrator/index.php?option=com_vidyalms&view=settings` |
| Course workbench | `administrator/index.php?option=com_vidyalms&view=workbench&id=<course>` |
| Import (server rendered) | `administrator/index.php?option=com_vidyalms&view=import` |

## Importing

`index.php?option=com_vidyalms&view=import` is the migration screen. It offers exactly the importers the
kernel registered, described by `Service\ImportSources`, which asks each provider through `supports()`
whether it takes an upload, a `cms-db:` source or both: today `json-course` (`.json`), `moodle-backup`
(`.mbz`) and `community-quiz` (`cms-db:`, with an optional selector such as `courses=12,15`).

Beside the source controls it draws the options each importer **declares** through the core's
`ConfigurableImporterInterface`, one fieldset per importer, one control per declaration. This screen
holds no table of option names and never grows one: a QTI profile selector or a Moodle XML dialect
selector appears here the day the package registering that importer declares it. Every fieldset but
the chosen importer's is hidden **and disabled**, because hiding alone would still post one
importer's options with another importer's run, and with scripting off the first importer is the
selected one and its fieldset is the enabled one, so the screen still works. What comes back is read
through `ImportOption::read()` in the core rather than here, so this form and the WordPress one
cannot mean two different things: a ticked box reaches `import()` as a boolean and not as the string
`"1"`, which every importer's `($options['flag'] ?? false) === true` would have read as false.

Bytes never travel through the API. `Model\ImportModel::storeUpload()` validates the transport error, the
size against `import_max_upload_mb` capped by PHP's own `upload_max_filesize` and `post_max_size`, and the
extension against the allowlist the chosen importer narrowed, then writes the file through the storage
port under `imports/<user id>/` and rebuilds the stored name from the validated extension, so an uploaded
`course.php.json` cannot become a PHP file.

Preview runs inline and writes nothing; Run queues the job and answers immediately with a pending row.
The work happens in **plg_task_vidyalms**, whose `vidyalms.drain_jobs` routine drains the adapter queue
and dispatches `vidyalms.import.run` to `RunQueuedImport`. Without that plugin enabled and a scheduled
task running the drain routine, a queued import stays pending forever, so the screen says so before you
press Run. Drive it by hand with `php cli/joomla.php scheduler:run --id=<task id>`.

The upload is kept, not deleted on completion, because running the same source again is the documented
recovery and Retry re-queues that same file. The `vidyalms.prune` routine deletes it once every job
naming it has finished and the last of them finished more than `import_source_retention_days` ago (thirty
by default). A source a queued or running import still needs is never deleted, whatever its age, and a
`cms-db:` source is not a file. When the file has gone the job stops naming it and Retry says so rather
than queueing a run against bytes that are not there.

Nothing is rolled back. A run that failed after eleven of twenty lessons has created eleven lessons, and
running the same source again is the recovery: `import_map` turns the second run into an update. That
recovery needs an identity that survives the fix, which is why a JSON document that will be corrected and
uploaded again must carry a `key` of its own: without one it is identified by a digest of its exact
content, so the corrected file would import as a second course. Every keyless run warns with the key to
add, and the screen says the same before you start.

Importer-specific options (`table_prefix`, `enrolments`, `publish` for Community Quiz; `max_bytes`,
`max_entries` for Moodle; `course_key` for the JSON importer, which supplies that identity without
editing the document) are not on the screen; the screen sends `dry_run` and the selector only. Set them
through `POST /v1/import`.

The job list shows the notes that need attention above the log. It reads the level from the note rather
than from its text: a note is one line with control characters replaced, so no imported title can write a
line into the log or claim a level of its own.

## The notification surfaces

Three pages and one machine endpoint, all of them thin: each hands a contract
path to the core kernel and renders what comes back. None of them holds a rule,
and none of them knows which use case answers, because the routes are frozen by
`openapi.yaml` and the classes behind them are not this package's business.
`Administrator\Service\CoreRoute` is the whole of the plumbing, and
`CoreRouteResult` is what a page reads: whether the call succeeded, and
otherwise which of four states to render.

| Surface | URL | Who reaches it |
|---|---|---|
| Unsubscribe | `/unsubscribe/{token}`, or `index.php?option=com_vidyalms&view=unsubscribe&token=<token>` | anybody holding the link, with no session |
| Preference centre | `/notification-preferences`, or `index.php?option=com_vidyalms&view=preferences` | the signed-in learner, and nobody else's rows |
| Delivery feedback | `index.php?option=com_vidyalms&task=feedback.receive&channel=<key>` | an ESP webhook or a feedback-mailbox forwarder, with a shared secret |

Both pages are ordinary server-rendered views with menu item types, so a site
puts them where it likes and they inherit the template's accessibility
conformance. Neither is a React mount: they have to work in whatever browser a
mail client opens.

**The unsubscribe page is one URL doing two jobs, because RFC 8058 gives a
receiver one URI.** A mail client that supports one-click POSTs to the URI in
`List-Unsubscribe`; a person who clicks the link GETs the same URI. Four rules
hold it together:

- **A GET acts on nothing.** It renders a confirmation form. Mail security
  scanners follow every link in a message, which is the whole reason RFC 8058
  specifies a POST, and a GET that unsubscribed would turn a scanner into an
  opt-out.
- **Nothing beyond the token is required.** No login, no CSRF token, no address
  to confirm and no fee: CAN-SPAM bars demanding any of them. The signed token
  is the authorization and the core verifies it.
- **Neither page is ever cached.** Both carry one person's state, so
  `MountConfig::forbidPageCache()` refuses Joomla's page cache through
  `onPageCacheIsExcluded` and `allowCache(false)`, and both send `no-store` and
  `X-Robots-Tag: noindex, nofollow`.
- **The one-click POST answers the outcome as JSON and never a redirect**,
  which RFC 8058 forbids, so it is also the one POST in this component that is
  not a redirect-after-post.

The token rides as a path segment rather than a query parameter, so the URI in
the header is a path. It is a URL-safe base64 string, which means it can carry
a hyphen, and `NomenuRules` reads the first hyphen of a key segment as core's
`{id}-{alias}` separator: `Service\Router::withLiteralKeys()` undoes that for
`token` exactly as it already did for `slug` and `code`, and `smoke.php`
asserts the round trip with a hyphenated token.

Mail services that cannot send `X-VidyaLMS-Feedback-Secret` can use HTTPS Basic
authentication on this endpoint: username `vidyalms`, password the dedicated
feedback secret referenced by the email channel. Configure these credentials
in the mail service, not in a learner account. The server must forward Basic
authentication to PHP. Keep the callback credentials private and rotate them
in the secret store and the mail service together.

For Amazon SES through SNS (Pro), enter the exact **Amazon SNS topic** in the
email channel settings and allow its `sns.REGION.amazonaws.com` host under
**Settings → Network & security → Outbound hosts**. VidyaLMS checks the SNS
signature before confirming the subscription and accepts SNS's standard
`text/plain` callback format.

**The feedback endpoint is a plain site URL because the sender is a machine.**
The contract route `POST /v1/notifications/feedback/{channelKey}` answers on the
REST mount too, but Joomla's API application negotiates content types and
expects an API token, so pointing an ESP at it means asking a third party to
speak Joomla. `task=feedback.receive` speaks HTTP: it takes the raw body, its
content type and the `X-VidyaLMS-Feedback-Secret` header, hands all three to the
core route, and emits the core's answer verbatim. There is no session, no CSRF
token and no capability check, and all three absences are deliberate: the shared
secret in `provider_settings` is the authorization, and the core answers a
request without one without saying whether the channel exists.

A raw `message/feedback-report` body reaching the core at all is new. Both
Joomla mounts and the WordPress mount now share `Core\Http\RequestBody`, which
decodes a JSON content type (or an absent one) exactly as before and carries any
other content type through untouched, so an ARF report is no longer answered 400
by the adapter before the core sees it.

## The mail port, and what PHP mail() does to a structured header

`Port\JoomlaMailer` honours four obligations, and three of them are legal rather
than aesthetic:

1. A message the core rendered is sent as authored. It does not go through
   `MailTemplate`, which would substitute its own subject over the one the
   message class was decided from.
2. A rendered message carrying an HTML part is sent as `multipart/alternative`
   with the plain part first, per RFC 2046 section 5.1.4. In PHPMailer terms
   that is `Body` holding the HTML and `AltBody` the text; the assignment is not
   interchangeable and `smoke.php` asserts the resulting order.
3. Every entry of the header map is emitted verbatim, `List-Unsubscribe` and
   `List-Unsubscribe-Post` included. A value carrying a CR or an LF is skipped
   rather than folded, because folding an injected value is how a header split
   becomes a second message.
4. `envelopeSender` is written to PHPMailer's `Sender`, which is the SMTP
   `MAIL FROM`. Joomla's `setSender()` writes the `From` header, which is a
   different field, and substituting it would attribute every bounce to the
   wrong stream.

**One limitation is PHPMailer's and is reported rather than hidden.** PHPMailer
runs every custom header value through `encodeHeader()`. With no control or
8-bit characters in it the only thing that triggers an encoding is the length,
and the length depends on the transport: `MAX_LINE_LENGTH` is 998 for SMTP and
sendmail, `MAIL_MAX_LINE_LENGTH` is 63 for PHP's own `mail()`, less sixteen
characters of Q-encoding overhead. So on a site sending through `mail()`, any
structured header value over 47 characters comes out as an RFC 2047
encoded-word, and an encoded-word is not permitted in a structured header value:
a receiver reading `List-Unsubscribe` finds no URI and one-click stops working
with nothing in any log.

The header is still emitted, because an inert header is not harmful and
silently dropping a legally required mechanism would be worse. What the port
does instead is write one line to `com_vidyalms.php` naming the headers and the
remedy, and `smoke.php` asserts that the line is written. **The remedy is to
configure the site to send through SMTP**, which is what Gmail's bulk sender
requirements effectively force anyway.

## Starting the sweeps

A sweep has no event behind it. Nothing happens when a drip window opens, when
a digest period closes or when a learner fails to log in, so something has to
notice, and the something is the host's own clock.

`plg_task_vidyalms` gains the routine `vidyalms.sweeps`, and it knows no task
type. `Core\Application\Platform\EnqueuePeriodicTasks` walks the core's
scheduled task registry for handlers declaring
`Registry\ScheduledTask\RunsPeriodically`, and each of those answers its own bin
count, period bucket and dedupe key; the routine loops, asks, and queues through
the scheduler port. A sweep a paid or third-party package registers is therefore
started here the day it registers, with no change to this plugin, which is the
same property that already lets the drain run a job type this adapter has never
heard of.

It queues rows and runs none. The drain routine picks them up and resolves each
type in the registry exactly as it does for a certificate render or a queued
import, so a sweep and an event-driven job travel one path and there is one
retry regime over the whole queue rather than two.

The turn is idempotent inside a period: the scheduler port replaces a pending
task carrying the same dedupe key, and a provider's bucket is stable across the
period. Both hosts are documented multi-firers (Joomla's default lazy scheduler
is triggered from front-end responses, WP-Cron fires on page load), so a clock
that fires twice is the normal case rather than the exotic one.

The package creates the row for you (see the next section), enabled and hourly.
Change the cadence under System, Manage, Scheduled Tasks to the shortest period
any registered sweep uses. Drive it by hand with
`php cli/joomla.php scheduler:run --id=<task id>`.

**The runner reads the clock itself and never through `Factory::getDate`.**
That helper memoises: it caches the constructed `Date` under the key `now-UTC`
and hands back a clone of the first one for the life of the process. In a web
request that is invisible. In `php cli/joomla.php scheduler:run` it is not, and
it broke three things at once: a row this run queued after boot was stamped with
the real clock and was then never due against a frozen now, so the sweeps
routine and the drain routine could not cooperate inside one run; a retry
backoff of frozen-now plus five minutes could already be in the past, so a
failing job burned its whole attempt budget in one pass; and every `reserved_at`,
`finished_at` and `updated_at` a long run wrote recorded the boot time rather
than the time anything happened.

## The scheduled task rows are created by the installer

A fresh install needs no manual task setup. `pkg_script.php` creates four
`#__scheduler_tasks` rows in postflight, enabled, on install and on every
update:

| Type | Cadence | What it does |
|---|---|---|
| `vidyalms.drain_jobs` | every 5 minutes | runs due rows in `#__vidyalms_joomla_jobs`, whatever queued them |
| `vidyalms.sweeps` | hourly | one turn of the clock for every handler declaring `RunsPeriodically` |
| `vidyalms.expire_entitlements` | daily | the access sweep, which declares no period of its own |
| `vidyalms.prune` | weekly | retention over spent idempotency keys, finished jobs and import archives |

Between them they start everything: a scheduled task type in the core registry
reaches its handler through the drain whatever queued it, and a sweep a paid or
third-party package registers is started by `vidyalms.sweeps` the day it
registers. That is why four rows cover a registry that has more than a dozen
types and will have more.

**Idempotent by task type, and it never touches a row that already exists.**
An administrator who disabled a routine, moved it to a different interval or
changed its parameters made a decision an upgrade must not undo, so the check
is "does any row carry this `type`" and nothing more. A routine added in a later
release still arrives on the update that ships it, because that row does not
exist yet.

It is never fatal. A site without com_scheduler, or one whose task table
refuses an insert, gets a line in the log and a working extension; failing an
install over housekeeping would be worse than a queue an administrator has to
start by hand.

`asset_id` is left at 0. Joomla writes the asset row itself the first time the
task is saved from the Scheduled Tasks screen, and until then the task inherits
com_scheduler's rules, which is what every installer-seeded task on this
platform does.

**The certificate backfill needs no row of its own.**
`vidyalms.certificate.backfill` is a periodic handler in the core registry, so
`vidyalms.sweeps` starts it like every other one. It matters more than most:
completion is terminal and `CourseCompleted` cannot re-fire, so a completed
enrolment whose issuance failed is a gap nothing else in the product will ever
look for. `build/smoke.php` dispatches the real `vidyalms.sweeps` routine and
asserts this handler is among the ones it started, rather than trusting that
registration implies provisioning.

## Two data steps the installer runs

`postflight` also reconciles two rows, both idempotent, both narrow, and
neither of them something a schema migration could do. A migration is keyed on
the schema version and only runs when the shape changes; these have to reach a
site whose shape is already current and whose data is not, which is why a
schema drift check cannot see either of them.

**`site_base_url`** is recorded once, from the request the installer is running
in, and only when there is one. See the site-root resolver below for why a
scheduled run cannot compose this for itself.

**`template_key` on the seeded certificate template.** `EnsureDefaultTemplate`
seeds the shipped template with `vidyalms.default` and finds it by that key
afterwards. Schema 1.7.0 added the column and did not fill it, so on every
upgraded site the shipped default carried NULL: a lookup by the shipped key
missed where a fresh install found it, and the two builds disagreed about which
row is the shipped template. The step claims exactly one row, the oldest
unkeyed default, and only when nothing already holds the key, because
`template_key` is unique and because a template an administrator created and
made the default must never be renamed under them.

## Credentials: verification, download and the PDF question

**The verification page is a PII disclosure surface, and it is treated as one.**
`Site\Model\VerifyModel` calls the contract, `GET /certificates/{code}/verify`,
through `CoreRoute` rather than calling `VerifyCertificate` directly, and that
is a security decision. The page names a person against a course completion, so
it is rate limited and marked noindex, and
`13-learning-flow-and-credentials.md` requires both to be **core middleware**
rather than a rule each adapter remembers: a control written once per adapter
covers only the routes that adapter happens to own, and this page and the REST
route are two transports over one disclosure. Going through
`ApiKernel::handle()` puts the HTML page inside the same chain as the JSON
route, so a burst against either is counted against the same bucket.

`Site\View\Verify\HtmlView` carries `NoIndexMiddleware::HEADER` from the
response onto the Joomla response and mirrors it as a `robots` meta tag, with
the middleware's own constant as the value rather than a literal typed here. A
page reached with no code at all makes no contract call and still gets the
header, because the surface itself is worth keeping out of an index.

The page renders only what the design permits: name, course, issuer, dates,
status and credits. Never an email, a user id, an enrolment id or the internal
certificate id. Every field is read defensively, so a build whose verification
shape has fewer fields renders fewer rows rather than a warning.

**Downloads are signed, expiring URLs and always an attachment.**
`Site\Controller\FileController` serves a private path only for a signature
`JoomlaStorage` minted, and its media type map names the credential formats:
`jsonld` for the free `vidyalms.json` document, `png` for the baked Open Badges
2.0 assertion, `jwt` for a Pro `openbadges.vc-jwt` artefact and `pdf` for a
Business PAdES one. An unlisted extension streams as
`application/octet-stream`, which is a download rather than a failure.

`html` is deliberately absent from that map. The free certificate artefact is
an HTML document assembled from a site-authored template, and serving it as
`text/html` from this origin would run any script it carries with the visitor's
session. Every response carries `Content-Disposition: attachment` and
`X-Content-Type-Options: nosniff` besides.

**A credential can be bytes, and the core says so on the response.**
`ApiResponse` carries a decoded structure rather than a string, and one of the
free formats is a baked PNG, so a response holding bytes answers `isBinary()`
and hands them over through `binaryBytes()`.
`Api\Controller\RestController::binaryBody()` asks; it does not sniff. It used
to: the core wrote an inline literal `{"content": "<base64>", "encoding":
"base64"}` specified by a comment, and this controller and `CoreBodyEmitter` on
WordPress each carried their own heuristic for recognising it. Two emitters had
to agree forever on a rule written down nowhere, a third adapter would have
invented a third reading, and a drift there does not fail a test: it serves
base64 text where a wallet expected a PNG. The base64 envelope still rides on
`$response->data`, so a caller reading the payload rather than the wire body
sees exactly what it saw before.

Two further things had to change for `GET /v1/credentials/{uuid}` to be
reachable at all on this host, and both were silent failures.
`ApiApplication::route()` runs **its own** content negotiation before anything
of ours is dispatched and answers 406 on a miss, so `plg_webservices_vidyalms`
had to name the credential media types rather than only `application/json`. And
`ApiRequest::FORWARDED_HEADERS` did not carry `accept`, so the core saw no
Accept header from either host, every request fell through to the first
registered format, and a wallet asking for a PNG was handed JSON-LD with no
error anywhere.

**The mount's acceptable media types come from the core, not from a list this
plugin holds.** `plg_webservices_vidyalms` named the union of the six shipped
formats in a `private const`, so a seventh was a change to a plugin rather than
a registration: Open Badges 3.0 SVG baking (`image/svg+xml`) is the design's
next one, and a third party registering `application/vc+sd-jwt` got a 406 from
Joomla for a shape the core would have served. `VidyaLms::negotiableMediaTypes()`
reads `Registries->credentialFormats` instead, so the answer changes the day a
package registers. Two details are deliberate. It is **unfiltered**: a format
that is installed but cannot serve (a signing package with no key configured)
stays acceptable to the mount, so the refusal is the core's 406 naming what
this build can serve rather than the host's naming nothing. And the kernel is
booted only when the request being routed is under the `v1/vidyalms` mount,
because `onBeforeApiRoute` fires for every API request on the site and no other
component's call should pay for our container; for our own it costs nothing,
because the controller boots the same shared instance a moment later. A kernel
that will not boot falls back to `application/json` and
`application/problem+json`, which is enough for the core to write the failure
in the contract's own shape.

**Kernel options this host supplies, and the two the credential surface
refuses without.** `KernelFactory::create()` hands the core an options array of
host facts. These are the ones the credential surface reads, and none of them
is ever taken from the request: issuance and bulk reissue run from
`plg_task_vidyalms`, a scheduled task has no request behind it, and a
credential minted by cron used to carry an unresolvable id for ever inside a
document that cannot be corrected once a wallet holds it.

| Option | Where it comes from | Absent |
|---|---|---|
| `verification_base_url` | the component option, else `<site root>/index.php/component/vidyalms/verify` | the verification URL is empty and the other two bases have nothing to default from |
| `credential_base_url` | the component option, else `<site root>/api/index.php/v1/vidyalms/credentials` | `GET /v1/credentials/{uuid}` answers **409** naming the option, rather than a document with a blank `id`, which is not an IRI |
| `status_list_base_url` | the component option, else `<credential base>/status` | a `credentialStatus` entry cannot be minted and the same 409 applies |
| `site_name` | Joomla's global `sitename` | the credential issuer name falls back to the issuer URL's host, then to a translated "not named yet"; never empty |
| `site_url` | the site root below | the issuer id falls back to `urn:vidyalms:issuer`, an IRI that is honestly not dereferenceable |
| `site_logo_url` | the component option | the `{site.logo_url}` token resolves to nothing and the seeded template draws no logo |

**`<site root>` is `KernelFactory::siteRootUrl()`, and it is never
`Uri::root()` in a process with no request.** Three sources, in order of
authority: `live_site` from `configuration.php`, then the `site_base_url`
component option, then `Uri::root()` and only when the application is a
`WebApplication` outside the CLI SAPI. `Uri::base()` branches on `PHP_SAPI`
itself and, with no request, composes the base from the CLI entry point's own
path, so a certificate issued by the Scheduled Task queue or by
`cli/joomla.php` carried an id like
`http://localhost/cli/joomla.php/index.php/...`. Once that id is inside a
signed document nobody can correct it, because the only party who could is the
wallet holding it.

The `site_base_url` option is recorded once by the package's own
`postflight`, from the one request that is guaranteed to be a real one, and
never refreshed afterwards: a development box serving one site under two host
names would otherwise flip the recorded root back and forth, and this value's
whole purpose is to be the one answer a scheduled run gets. A site that sets
`live_site` never reaches it; a site that has moved edits it in the component
options.

With none of the three available the resolver answers an empty string and the
core refuses to mint a credential id at all, naming the option the host has to
supply. That is a configuration gap somebody can fix, rather than a wrong URL
frozen into a document.

The last three default the `certificates.issuer` settings record, which an
administrator overrides through `PUT /v1/admin/settings`. The record is the
only thing on the Settings screen the API writes, and it is written there
because no Joomla settings form has a field for "who issues our credentials":
while nothing could write it, every credential this product emitted named an
anonymous issuer with an empty id and an empty name, both of which are required
members of the Open Badges 3.0 Profile.

**PDF certificates are a Pro feature.** This package ships no PDF engine and
renders certificates as HTML, which is what the core does whenever no `PdfPort`
is seated. VidyaLMS Pro installs the isolated Dompdf runtime with
`lib_vidyalms_pro` and seats its renderer at `onVidyaLmsPortsReady`; Joomla then
renders PDFs in a separate PHP process. HTML verification pages remain available
independently of the PDF renderer, and a site that removes Pro keeps HTML
certificates. A design published with the PDF renderer, which 1.0.0 offered by
default, keeps issuing on such a site: the core draws it as HTML until a PDF
port is seated again. Republishing it there needs the Designed certificate
renderer.

For PDF issuance with Pro installed, the host must provide a PHP CLI executable with the
required PHP extensions (including GD and mbstring), enable `proc_open`,
`proc_get_status`, `proc_terminate` and `proc_close`, and allow a writable PHP
temporary directory. The worker uses a 256 MiB memory limit and a 30 second
wall-time budget. If `open_basedir` is enabled, it must allow the executable,
installed runtime and temporary directory. Both web requests and the Joomla
CLI scheduler need access to the same private storage configuration.

The Certificates, Settings and System health screens show a warning when the
seated renderer reports that its worker preflight fails. A site without Pro has
no renderer and no warning. Ask the hosting administrator to enable the missing
facility; changing a certificate template will not repair host restrictions.
Run a certificate preview after setup to verify the worker and its extensions.

**Neither Dompdf nor mPDF produces PDF/UA conformant tagged output.** Chromium
has produced tagged PDFs since Chrome 85, but the output is not certified
PDF/UA, tag quality depends heavily on the source HTML, and `printToPDF`
exposes only page size, margins and header or footer, so document title,
`/Lang` and `DisplayDocTitle` need a post-processing pass.

So the accessible artefact on this host is **the HTML verification page and the
credential JSON, not the PDF**. Whichever renderer runs must set a document
title and `/Lang`, give alt text to logo and signature images, and emit the
verification URL as real selectable text and not only inside the QR code. **No
Ghostscript or optimisation pass may run after generation**, because it strips
the structural annotations and destroys the tagging. That is an honest
conformance statement rather than a claim this package can certify.

## The mount discovery key

`AdminMountConfig::workbench()` and `Site\Service\MountConfig::forInstructor()`
both carry a `routes` key: every contract path template the running kernel
actually serves, once each and sorted, from `ApiKernel::servedTemplates()`.

It exists because the bundle and this extension ship separately and a paid
package adds its routes from a kernel-ready listener, so which of the contract's
paths exist on an install is decided at boot and nowhere else. Without the key
the bundle had to guess, and it guessed wrong in the one direction that matters:
the quiz export control was on the contract, in the generated SDK and in the
shipped workbench, and answered 404 on every site without the package that
serves it. `packages/ui`'s `hasRoute()` reads the key, and a route that is not
listed is not offered.

It is a **discovery** key and never a permission key. Who may export is still
the core's answer to the request, which the app renders as a permission state;
this only says whether there is anything there to ask.

## Who can use the manager screens

The component opens for a group holding `core.manage` on it, which is what "manage this component"
means to a Joomla administrator, or any one of the four operational actions `vidyalms.teach`,
`vidyalms.grade`, `vidyalms.enrol` and `vidyalms.reports`. That list is
`Helper\VidyaLmsHelper::ADMINISTRATOR_ACTIONS`, and `Dispatcher\Dispatcher::checkAccess()` widens
Joomla's own `core.manage` gate to it. Without the widening a group given only `vidyalms.teach` would
be refused at the door, before the workbench could show it the one course it does instruct.

Opening the component grants nothing inside it. Every screen is a React mount over the `/v1` contract,
and the core's `AccessGuard` and `CourseAccess` answer each request: the apps render the permission
states from those 401s and 403s. This is why the gate can be wide.

Joomla's ACL has no notion of one action implying another, and the component's custom actions are
unknown to the global configuration, so nothing grants them by default. `Port\JoomlaAuthorization`
therefore supplies the implication: `core.admin` grants every VidyaLMS capability, and `core.manage`
grants every one except `vidyalms.admin`. Without it a group given `core.manage` could open the
component and then be refused by every screen inside it.

**Delegating one course.** `CourseAccess` lets a caller holding `vidyalms.manage` act on every course
and a caller holding only teach, grade, enrol or reports act on the courses they are an assigned
instructor of. To use that, the group must hold the operational action and **not** `core.manage`,
because `core.manage` implies management of every course. A working recipe is a group with
`core.login.admin` on the root asset (so the back end opens at all) and `vidyalms.teach` on
com_vidyalms and nothing else: that group reaches the workbench of a course it is assigned to, is
answered 403 by `GET /manage/courses/{id}` for any other, and sees only its own courses in
`GET /manage/courses`. The Joomla administrator menu still filters the component's entry on
`core.manage`, so such a group reaches the screens by URL rather than from the sidebar.

Both rules are covered by `smoke.php`: the administration-access section for the implication and its
negative cases, the per-course-teaching section for the scoping, including the course the instructor
is not assigned to and the write it is refused.

## Verifying

```
php build/smoke.php --joomla=<joomla root>            # seed and assert
php build/smoke.php --joomla=<joomla root> --cleanup  # remove everything it seeded
```

`smoke.php` boots the CMS from the command line, resolves the kernel from the container and drives the
access spine against the real database through the real ports: create a course, add a module and two
reading lessons, publish, grant an entitlement to a throwaway learner, replay progress heartbeats,
complete, and verify the certificate code. It is the only test that can prove `JoomlaDatabase` compiles
the core `Query` into working MySQL, that `update()` reports genuinely affected rows so `CourseCompleted`
fires exactly once, and that `upsert()` dedupes on the unique key. It also round-trips a private file
through a signed URL, and it drives the two access rules against Joomla's real ACL: a group holding
`core.manage` alone reaching every operational capability but not `vidyalms.admin`, and a group holding
`vidyalms.teach` alone reaching the workbench of the one course it is assigned to and nothing else. It
writes to the site it is pointed at, so point it at a development site.

It also proves that the four course events reach the Joomla dispatcher: the run subscribes to
`onVidyaLmsCoursePublished` and `onVidyaLmsCourseUpdated` before it publishes and renames the
course, and asserts on the payloads, so the whole chain is checked rather than the map in the
plugin's constant. And it runs the shared database port contract against `JoomlaDatabase`.

Section 10f drives the queue end to end through the extension com_scheduler actually calls. Two rows
are queued through the scheduler port, one of a registered task type and one of a type nobody
registered; `plg_task_vidyalms` is imported onto the application's dispatcher and `onExecuteTask` is
dispatched with the drain routine exactly as com_scheduler dispatches it. The first row finishes, the
second is recorded as an unknown type with the type named in `last_error`, and neither outcome
involves this adapter knowing a job type: the runner resolves `job_type` in the core's scheduled task
registry and nowhere else, which is what lets a package queue work of its own and have both hosts run
it without either adapter changing. A runner with a switch statement in it would pass the first case
and fail the second. No `#__scheduler_tasks` row is created (com_scheduler's `Task` takes a plain
record) and both queue rows are removed on the way out, so the seal stays intact.

Section 10o proves that availability gates a **write**, which is the half an outline cannot show.
It creates a third course of its own with a module, a reading lesson and a quiz, enrols the
throwaway learner, puts a date restriction three weeks out on the quiz and posts
`/quizzes/{id}/attempts` as that learner. The refusal has to be a 409 naming both the reason key and
the instant, and no attempt row may be written; clearing the rule is then the whole of unlocking it
and the next post starts an attempt. It runs on a course of its own because the spine's enrolment is
completed by the time the section is reached and `StartAttempt` requires an active one, and it
removes that course as soon as it is finished with it.

Section 10p is the credential surface around the certificate the run already issued: the frozen
`template_snapshot` and `context_snapshot`, a template with a page and four elements, a preview that
writes nothing, the credential document in each free format, a reissue that supersedes the old row
and leaves its code resolving, and a revoke with a reason. Every assertion in it is guarded by the
route it needs, so a build that has not registered the template aggregate or the credential routes
reports each one **pending** and names the route, and each becomes an ordinary assertion the moment
the core ships it. The two that never depend on a route, the frozen snapshot and the unknown code
answering 200, are asserted unconditionally.

It seeds and removes rows; it creates no table and drops none. A `SchemaSeal` over the site's table
list is taken at the start and checked on the way out, and the last section of the run reports the
intact case out loud ("the table list is unchanged since the run started, N tables, 0 added, 0
dropped"), because a detector that is silent either way cannot be told apart from one that was never
wired in. See `tooling/fixtures/README.md`.

Course cleanup is one function, `removeSmokeCourse()`, shared by the gated-quiz section and by
`--cleanup`. Two lists of tables would drift, and a table on one list and not the other is a leak
nobody notices until a later run trips over the row.

Two sections of the run are about the schema rather than the spine. `AssessmentFoundationContract`
(`tooling/contract`, shared with the WordPress adapter) asserts that the four tables schema 1.1.0
adds answer a query, that every column 1.1.0 and 1.3.0 add is addressable in a predicate (counted
per version, because a site that stalled between them is a different problem from one whose 1.1.0
never ran), that every
scoring policy key the migration wrote resolves in the free build's own registry, and that **every
attempt already on the site still reads**: through the attempt repository, through
`AttemptManifest::forAttempt()`, through `answersFor()` and `pendingCountFor()`. An attempt started
before 1.1.0 has no `attempt_questions` rows and reads through the pre-manifest fallback, which is a
path no fresh install can exercise, because an attempt written today gets a manifest. The other
asserts that `KernelFactory::SWEEP_TASK_TYPE` is the routine `plg_task_vidyalms` advertises: two
literals in two extensions, and if they drift nothing throws, the Settings and Dashboard screens
simply report that no sweep is scheduled.

**The run imports the system plugin group before it builds the kernel, and that order is the point.**
`onVidyaLmsKernelReady` is dispatched once, from inside `KernelFactory`, on the application
dispatcher. A listener that is not on that dispatcher when the kernel is built has missed it for the
life of the request, and the container then caches that kernel. Every earlier run built the kernel on
a dispatcher carrying no plugins at all, so the whole run saw a free build even on a site with Pro
installed and enabled, and a Pro provider, a Pro route or a Pro mount key could not be asserted here
at all. The plugins are imported one at a time, each in its own `try`: a development site is a site
with other people's extensions on it, and one legacy plugin calling a class Joomla 6 removed would
otherwise be a fatal before the harness had asserted anything. Skipped plugins are named.

Five sections cover the learning-flow surfaces:

- **10i** assembles a rendered message through `JoomlaMailer` and asserts the wire format rather than
  the port's own report of itself: both `List-Unsubscribe` header names intact, `Auto-Submitted` and
  `Precedence`, the `Feedback-ID`, `multipart/alternative` with the plain part before the HTML part,
  and PHPMailer's `Sender` carrying the envelope address while `From` stays the site's. It then sends
  the same message over PHP `mail()` and asserts that the transport's header encoding is reported to
  the component log rather than shipped silently. Nothing leaves the machine: the capture calls
  `preSend()`, which assembles and does not deliver.
- **10j** round-trips the unsubscribe token (with a hyphen in it) and the preference centre through
  the router, asserts that the page refuses Joomla's page cache, that a GET of the landing page
  writes no preference and no consent row, and that a raw `message/feedback-report` body reaches the
  router rather than being answered 400 as bad JSON. Whether a route exists is asked of the kernel
  rather than inferred from a status: this token was never issued, so a registered route answers it
  404 exactly as an unregistered one would.
- **10k** asserts the mount discovery key on both workbench surfaces, including that a route the Pro
  package contributed is declared and that the site page declares the identical list.
- **10l** drives one turn of the clock end to end: every handler in the registry that declares
  `RunsPeriodically` (four on a Pro build, and a probe under a name nobody uses when a build
  registers none), the `vidyalms.sweeps` routine dispatched exactly as com_scheduler dispatches it,
  one row per bin, a second turn inside the same period queuing no second row, and the drain running
  every queued row through the registry. The rows are removed afterwards by the exact keys the
  providers minted, so a row this run did not create cannot be taken with them.
- **10m** gates a lesson on a future date over the contract and asserts the learner's own curriculum
  read comes back blocked with the instant, then creates and removes a cohort.

A check whose core route is not registered on the build under test prints `[PEND]` with the route
named, is counted separately from failures, and is reported in the summary. That is not a skip: a
check that can run and does not hold is still a `FAIL`, and each pending line becomes an ordinary
assertion the moment the core ships the route.

Whether the schema on a site is the schema a fresh install would create is a separate question, and
`tooling/schema/verify.php` answers it:

```
php tooling/schema/verify.php --joomla=<joomla root>
```

It creates a database of its own, runs this package's own `install.mysql.utf8.sql` into it, compares
both sides out of `INFORMATION_SCHEMA` and drops the database. Both sides come from the same source
of truth, so there is no second copy of the type mapping to drift. Run it after installing an update:
a migration that forgot a column the install script creates is invisible until the first write.

Adding `--pro` asks the same question of the Pro schema, against the Pro package's own install
script. It is a separate run because the two are installed by different packages and upgraded on
different days, and the comparison walks its own reference, so neither run sees the other edition's
tables. This package never installs a Pro table and the Pro package never installs a free one; the
Pro packager fails the build if its update folder tries.

The database and native course-category port contracts also run on their own,
which is the quicker loop when only these read ports are in question:

```
php build/port-contract.php [--joomla=<joomla root>]
```

Its first half is `tooling/contract`, the same assertions the core runs against
its SQLite harness and the WordPress adapter runs against `WpDatabase`:
aggregates, conditional counts, rates that answer null on an empty domain,
`nullsLast` ordering (which MySQL has no syntax for, so each adapter compiles it
as a leading "column IS NULL" key), one level of OR, and `count()` of a grouped
query. The Joomla-specific half seeds a native category tree and proves literal
search (including `%` and `_`), name and id ordering, stable tie breaks, exact
paging totals, all four lifecycle states, root normalization, ancestry labels,
cycle safety, exact lookup, capability-aware manager/edit URLs, and suppression
of Joomla's currently untruthful native item-count links. Everything it writes goes in inside a transaction
it deliberately rolls back, and the run then checks that the rollback happened
rather than assuming it.

Cross-platform HTTP verification lives in `tooling/parity/`: after `smoke.php --keep` on both dev
sites, `fixture-joomla.php` / `fixture-wp.php` decorate the seeded fixtures (a free-preview lesson, a
known learner password) and `verify.mjs` proves the player pages, the mount configs, the app assets and
`GET /lessons/{id}` (guest 200/401/404 plus signed-in 200 through the real login) over real HTTP, then
requires the two platforms' responses to be byte-identical after normalising ids and timestamps.

Static analysis:

```
composer install --no-interaction
php vendor/phpstan/phpstan/phpstan.phar analyse --memory-limit=1G
```

PHPStan runs at level 5 and is clean. `composer.json` exists only for that: the shipped extensions have
no Composer dependencies. Every `ignoreErrors` entry in `phpstan.neon.dist` names the Joomla declaration
that is looser than the runtime object; none of them hides a defect. Set `VIDYALMS_JOOMLA_SITE` to the
Joomla install the analyser scans (the same variable the `build/` scripts read); `packages/core/src` is
found relative to the configuration.

One trap in that configuration: `scanDirectories` includes the Joomla install's `libraries`, which
holds the **installed** copy of `vidyalms_core`. When the core gains a method, the analyser sees the
installed copy's older class beside the repository's and reports the new method as undefined until
the site is updated. Install the package (or refresh
`<joomla root>/libraries/vidyalms_core/src` from `packages/core/src`) before reading a
`method.notFound` on a core class as a real finding.

## Current limitations

- **Certificate verification URLs need a path-shaped base.** The default is
  `<site root>/index.php/component/vidyalms/verify`, which the core appends the code to. It works with
  SEF URLs on or off as long as the server passes `PATH_INFO`. A site where it does not must set
  `verification_base_url` in the component options.
- **URLs built outside a web request need `live_site`.** `Uri::root()` has no request to read in CLI, so
  a certificate mail sent from a scheduled task carries a wrong host unless the site sets `live_site` or
  `verification_base_url`.
- **The whole REST mount is registered as public in Joomla's sense.** Joomla does not reject before
  dispatch; the core's `AccessGuard` decides, and answers 401, 403, 404 and 405 as
  `application/problem+json`. That is what makes the Joomla and WordPress mounts answer identically for
  the same call. Authorization is not weakened: it was never in the router.
- **No delete for courses.** The core exposes no `DeleteCourse`, so Archive is the destructive action
  the workbench offers.
- **Instructors and prerequisites are not shown on the public course page.** The core has no
  learner-facing read use case for them yet; `Site\Model\CourseModel::getInstructors()` and
  `getPrerequisites()` return empty lists and are a two-line change once it does. The workbench shows
  both, from the management surface.
- **The administrator menu entry is still filtered on `core.manage`.** That is Joomla's own menu
  building, not this component's: a group given only `vidyalms.teach` opens the screens by URL. The
  component itself accepts it (see "Who can use the manager screens").
- **PDF certificates (Pro) require the isolated worker described above.** HTML verification remains
  available when the host cannot run the PDF worker; issuance failures are reported to the manager.
- **`courses.category_id` is mapped onto Joomla categories** by the admin form and the catalogue filter
  (`extension=com_vidyalms`). The core treats it as a plain integer, so the finder plugin builds no
  category taxonomy and inherits no category access.
- **Courses carry no Joomla view level.** The finder adapter indexes every course at the site's default
  access level.
- **One-click unsubscribe needs SMTP.** PHPMailer Q-encodes any custom header value over 47
  characters when the site sends through PHP's own `mail()`, and an encoded-word is not a URI, so
  `List-Unsubscribe` arrives unusable. The header is still sent and the port writes one line to
  `com_vidyalms.php` naming the remedy, which is to configure the site for SMTP. See "The mail port".
- **The unsubscribe page and the preference centre need a menu item for a pretty URL.** Without one
  they route through `NomenuRules` as `/component/vidyalms/unsubscribe/{token}`, which works but is
  not the address to print in a message header.


### Private storage deployment and migration

Web setup automatically selects a site-specific directory outside all known public roots
when the parent is writable. The location is signed with the Joomla server secret and
saved for use by both web requests and CLI jobs. Tampered or cloned records fail closed.
Check System health and include the selected directory in backups.

For restricted hosting or a custom location, define the server-owned constant before VidyaLMS is constructed in **every** Joomla
entry point. One deployment option is a dedicated file outside the document root,
`/srv/vidyalms/joomla-bootstrap.php`:

```php
<?php
if (!defined('VIDYALMS_PRIVATE_DIR')) {
    define('VIDYALMS_PRIVATE_DIR', '/srv/vidyalms-private/joomla');
    // Windows example instead: C:/vidyalms-private/joomla
}
```

Configure `auto_prepend_file = "/srv/vidyalms/joomla-bootstrap.php"`
for the **Joomla virtual host's PHP configuration**, not a shared PHP configuration
also serving WordPress. Load the same file for scheduled CLI script invocations:

```sh
php -d 'auto_prepend_file=/srv/vidyalms/joomla-bootstrap.php' \
  <joomla root>/cli/joomla.php scheduler:run --all
```

Alternatively, a deployment-managed bootstrap included by all site, administrator,
API and CLI entry points may define the constant. A component option is insufficient.
Do not rely on a site-only `defines.php`: Joomla entry points may load different
files. Avoid placing deployment statements in a configuration file that Joomla
rewrites when Global Configuration is saved. A readable constant is not enough:
web and CLI accounts must have filesystem access and compatible `open_basedir` rules.
The administrator page reports a warning if the directory is unsafe or unavailable.

The default source is
`<joomla root>/media/com_vidyalms/private`; the destination above is outside
the web server's document root. Pause web activity and workers, back up files and database, reject
links/junctions, and copy to a new empty destination preserving every relative path
and `.secrets`. Verify complete inventories, sizes and SHA-256 digests before setting
the constant. Restrict directory/file permissions to the web and worker accounts.
Test private upload/download and queued import from both contexts, then remove the
old public-root copy while activity remains paused and verify its old URLs no longer
serve bytes. Keep backups outside all served roots. Changing configuration never
moves files. The old helper that only updates `private_storage_path` does not enable
this server-owned configuration and must not be used as the final migration step.

The storage code cannot inspect arbitrary web-server aliases; deployment must ensure
no virtual host, alias or CDN serves the external directory. Unattached saved uploads
count against the cumulative quota and are not silently expired while a learner may
still reference them. Automatic reference-aware orphan expiry remains unimplemented.

Every Joomla private directory, including an explicit `VIDYALMS_PRIVATE_DIR`, is
bound to this installation's canonical filesystem root and server hostname by
`.vidyalms-owner.json`. A first write claims an empty directory. Reads and health
checks never claim one; an existing nonempty, unmarked directory fails closed.
A different installation or a damaged marker cannot read, overwrite, enumerate
or delete the private files. Public images remain usable independently.

For a verified legacy directory, stop web activity and workers and back up the
directory first. The server owner must confirm that **every** file belongs to this
installation, with no links, aliases or shared use. From that installation's
trusted Joomla CLI bootstrap, obtain the marker bytes with
`JoomlaStorage::ownershipMarker(JPATH_ROOT)` (class
`VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage`) and write them to
`<private-directory>/.vidyalms-owner.json` with owner-only permissions. The helper
only returns bytes; it performs no claim or migration. Do not overwrite an existing
marker to make a clone work: give the clone its own empty directory. A deliberate
server/path move needs the same offline ownership verification and re-binding.
For a fresh migration, initialize the destination through this site's
`ensurePrivateRoot()` **before** copying the verified data into it; keep its marker.

### Uninstall and retained private data

Before uninstalling, pause web requests and scheduler workers, record the actual
private path from System health/server configuration, and take the backups your
retention policy requires. The package removes `reports/`, `certificates/` and
`imports/` only through this installation's validated private-storage boundary.
An unavailable root, foreign/missing ownership marker, link or failed deletion
produces a warning; cleanup may then be incomplete. It never selects a fallback
directory or claims an old directory during uninstall.

`submissions/` and `attempts/` remain by default. To explicitly remove these learner
files as part of uninstall, the server owner may define
`VIDYALMS_REMOVE_LEARNER_FILES_ON_UNINSTALL` as the boolean `true` in the same trusted
bootstrap used for the uninstall request. Public `images/vidyalms`, private keys,
ownership metadata, guards and all other private paths remain, as do notification
consent/suppression tables. Inspect the uninstall messages and remove retained
data manually only after verifying the recorded path and your retention needs.
The location record is lost with the learning settings tables; record it beforehand.

### Scheduler mail and EasyCommerce follow-up

For a fixed PDF worker executable, the server owner can define
`VIDYALMS_PHP_CLI` as an absolute PHP CLI path (for example `/usr/bin/php8.3`) in
the trusted bootstrap used by every entry point. VidyaLMS Pro reads it when it
seats the PDF renderer, so the manager availability warning uses that same value. Invalid explicit values fail PDF
availability without automatic fallback. The installed
`administrator/components/com_vidyalms/README.md` includes PDF requirements,
private ownership migration and uninstall retention instructions.

Free task creation now supplies Joomla's notification flags and recipient arrays.
Mail starts disabled until an operator enables it and chooses recipients. Upgrades
repair missing fields on existing Free tasks while preserving their schedule,
enabled state, routine parameters and selected recipients; deleted tasks stay
deleted. The Pro installer owns the corresponding Pro task defaults.

The EasyCommerce response hook only runs when the database connection is known
to be outside a transaction. MySQL requires readable, enabled transaction
instrumentation with positive evidence of a completed transaction. Unknown or
unavailable instrumentation leaves reconciliation for the scheduled task. Queue
completion cannot acknowledge or demote a newer notification revision.
