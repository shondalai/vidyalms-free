# VidyaLMS UI

The React apps of VidyaLMS: the **course player**, the **quiz runner**, the **instructor
workbench** and the **admin** screens, one Vite entry each in one package over one shared
foundation. The same bundles ship in both CMS packages; only the mount markup the adapter renders
differs. All HTTP goes through the generated SDK in
[`packages/api-sdk`](../api-sdk/README.md); apps never hand-write fetch calls. Every app targets
WCAG 2.2 AA.

## Scripts

```bash
npm install
npm run dev         # dev harness at http://localhost:5173 (index.html hosts every mount)
npm run build       # dist/ bundles the adapters ship
npm test            # vitest + testing-library + happy-dom
npm run test:react18 # isolated React 18.3.1 instructor field regression (requires npm access)
npm run lint        # eslint (typescript-eslint + react-hooks)
npm run type-check  # tsc --noEmit, strict
```

### WordPress React 18 field regression

The normal suite uses the UI package's React 19 runtime. `npm run test:react18`
copies the current instructor field source and its regression tests to a temporary
directory, installs real React/ReactDOM 18.3.1 with the checkout's test dependency
versions, asserts that React 18 is running, and removes the fixture afterward.
It does not change the checkout's dependencies or build any application bundles.
The tests check native refs, unmount cleanup, React Hook Form defaults, required
field focus, edited submit values, checkbox/select values, and reset behavior.

After the coordinator rebuilds and installs the final WordPress assets, also check
an instructor course form with the browser console open:

1. Confirm `React.version` is 18.x. Open an existing lesson or course form and
   verify text, textarea, select, and checkbox defaults are populated.
2. Clear a required title and save. The title should receive focus. Restore it,
   edit the other controls, save, reopen, and verify the saved values.
3. Cancel a draft, reopen the form, and verify persisted defaults return. There
   should be no “Function components cannot be given refs” warning.
4. On Free, confirm Runs/Course runs and item-analysis Reports are absent. Visit
   `#vy/cohorts`, `#vy/reports`, and the admin builder's `#/course/<id>/vy/cohorts`;
   they should fall back to Curriculum without a cohorts or item-analysis request.
   With the corresponding routes registered, confirm those tools remain available.

## The mount contract (verbatim, identical on both hosts)

The adapter renders

```html
<div id="vidyalms-player" data-vy-config='{json}'></div>
```

(or `id="vidyalms-quiz-runner"`, `id="vidyalms-instructor"`, `id="vidyalms-admin"`,
`id="vidyalms-notifications"`) and loads the bundle. The `api`, `user` and `locale` members are identical on every app; the rest is per app,
each app declaring its own schema over the shared half in `src/shared/config.ts`. The learner
apps read:

```json
{
  "api": {
    "base": "string",
    "mode": "prefix | query",
    "headers": { "name": "value" }
  },
  "course": { "id": 1, "slug": "string" },
  "lessonId": 1,
  "user": { "id": 1, "name": "string" },
  "locale": "en-GB",
  "urls": { "course": "string", "myLearning": "string" }
}
```

- `lessonId` is `number | null` (null when no lesson is selected).
- URL building: mode `"prefix"` means `url = base + path`; mode `"query"` means
  `url = base + encodeURIComponent(path)` where `base` already ends with its query key. The query
  mode exists because the Joomla SITE app must proxy the API through a component task URL.
- `headers` carries whatever auth header the host needs on every request: `X-CSRF-Token` on
  Joomla, `X-WP-Nonce` on WordPress.
- Every app reads ONLY this config, with exactly one documented exception: `window.vidyalms`,
  which carries the client renderer registry and the pinned React runtime a third-party question
  type bundle needs. See The client renderer registry below.

The instructor mount keeps `course` (nullable) and replaces `lessonId` and the learner `urls`:

```json
{
  "course": { "id": 1, "slug": "string" },
  "routes": ["/manage/quizzes/{id}/export"],
  "urls": {
    "courses": "…host course list…",
    "workbench": "…?view=course&id={course_id}",
    "preview": "/courses/{slug}"
  }
}
```

- `course` names the course the workbench opens on. `null` renders the caller's course list from
  `GET /v1/manage/courses` instead, each row linking into a workbench through `urls.workbench`.
- `workbench` and `preview` are host URL templates carrying the `{course_id}` and `{slug}` tokens
  the app substitutes; `courses` is the host's own list page (back to courses). The app never
  builds a host URL itself.
- The tabs are hash routes under `#vy/` (`#vy/gradebook`), so a hash the host page owns is left
  alone.
- `routes` is OPTIONAL and is a discovery key, never a permission. It lists the contract paths, as
  their templates, that this host actually serves, and a control whose route is not listed is not
  offered: the bundle and the extension ship and upgrade separately, so a workbench newer than its
  host would otherwise offer a control that answers 404. A host that says nothing is a host from
  before the key existed, so nothing new is offered on it and nothing old changes. What the caller
  MAY do is still the API's 403, rendered as the permission states. Today one control reads it,
  the quiz export.

The admin mount replaces `course`, `lessonId` and the learner `urls` with its own half:

```json
{
  "screen": "dashboard | courses | enrolments | learners | certificates | notifications | settings",
  "urls": {
    "workbench": "…?view=course&id={course_id}",
    "preview": "/courses/{slug}",
    "settings": "…host settings page…",
    "screens": { "courses": "…", "enrolments": "…" }
  }
}
```

- `screen` identifies the initial host menu entry. The workspace intercepts ordinary links to
  registered `urls.screens` destinations and updates browser history without replacing the mount,
  API client, or query cache. Reloads still resolve through the native CMS. Modified clicks,
  downloads, external links, and native settings/import actions keep their browser behavior.
- Course links use the configured Courses destination plus `#/course/{id}`; workbench tabs append
  `/vy/{tab}`. Learner, question-bank, certificate, and other feature hashes retain their existing
  routes. Unknown extension screens still show an unavailable state rather than another screen.
- `workbench` and `preview` are host URL templates, with the `{course_id}` and `{slug}` tokens the
  app substitutes. It never builds a host URL itself.
- `settings` is the host's own settings page: `GET /v1/admin/settings` is read-only by contract,
  so everything editable lives there.
- `screens` is optional, one entry per menu item the host can link to. A screen with no URL
  renders as text instead of a dead link.

The notification site pages are a fifth mount, `vidyalms-notifications`, on the SITE and not in the
back end:

```json
{
  "screen": "preferences | unsubscribe",
  "token": "…signed unsubscribe token, on the unsubscribe screen…",
  "urls": { "home": "…", "preferences": "…host preference page…" }
}
```

- `screen` says which of the two pages this is; the host ships one page per screen.
- `token` is the signed token from a message's `List-Unsubscribe` header or body link, read from
  the host rather than from the address bar, because the host owns its own URL shape.
- It is a SEPARATE entry from the player deliberately. The unsubscribe page is reached from an
  inbox with no session at all, and an app that assumed a learner would make the one page a person
  reaches after leaving depend on being signed in, which the law does not permit: nothing beyond
  the token may be demanded and nothing may be charged.
- The GET renders a form and acts on nothing. Mail security scanners follow links, which is why
  the one-click mechanism is specified as a POST; a page that unsubscribed on load would turn a
  scanner into an opt-out. **The host must serve both pages with no shared cache and a noindex
  header**; the bundle's own client cache for them is already per mount and is never reused.

`src/shared/config.ts` validates the shared half with zod and each app extends it (see
`src/admin/config.ts`). A missing or malformed config renders a visible error state into the mount
(never a blank div) and stops; React does not start.

## Build outputs the adapters consume

`npm run build` writes to `dist/` with **fixed entry/CSS names and content-hashed secondary
JavaScript**. Adapters cache-bust the fixed files by extension version; changed chunks and
their importers receive new URLs automatically, without a browser hard refresh:

| File | Load it? | Content |
|---|---|---|
| `player.js` | yes, on player pages | entry, ES module |
| `player.css` | yes, on player pages | full styles for the player mount |
| `quiz-runner.js` | yes, on quiz pages | entry, ES module |
| `quiz-runner.css` | yes, on quiz pages | full styles for the quiz mount |
| `instructor.js` | yes, on the course workbench | entry, ES module |
| `instructor.css` | yes, on the course workbench | full styles for the instructor mount |
| `admin.js` | yes, on the administrator screens | entry, ES module |
| `admin.css` | yes, on the administrator screens | full styles for the admin mount |
| `notifications.js` | yes, on the preference centre and unsubscribe pages | entry, ES module |
| `notifications.css` | yes, on the same two pages | full styles for the notifications mount |
| `credentials.js` | yes, on the credentials and verification pages | entry, ES module |
| `credentials.css` | yes, on the same two pages | full styles for the credentials mount |
| `learner-navigation.js` | yes, first, on every learner page | entry, ES module: SPA navigation over the learner shell; mounts nothing itself and has no stylesheet |
| `public-theme.css` | yes, on learner pages | the learner page theme |
| `shared-[hash].js` | no | shared foundation chunk; imported by every entry and by most chunks, and it imports only `vendor` |
| `vendor-[hash].js` | no | node_modules chunk, imported by every entry and by the chunks that need React or the SDK |
| `learner-navigation-vendor-[hash].js` | no | swup and its plugins; imported by `learner-navigation.js` only, so ordinary learner navigation loads no React |
| `question-types-[hash].js` | no | client renderer registry, the eight primitives, the six free modules and their authoring copy; imported by the `quiz-runner`, `instructor` and `admin` entries and by the workbench chunks below, never by `player`, `notifications`, `credentials` or `learner-navigation` |
| `admin-foundation-[hash].js` | no | shared admin contexts, fields and screen primitives, with no app mounting; imported by `admin`, `instructor` and `credentials` and the admin lazy screens |
| `lib-[hash].js`, `api-[hash].js`, `routes-[hash].js`, `studio-api-[hash].js` | no | helpers shared by two or more entries (`lib`: `player` and the workbench; `api`: the admin side and `credentials`; `routes` and `studio-api`: the workbench) |
| `cohorts-tab-[hash].js` | no | the course workbench tabs, a static dependency of `instructor.js` and of the lazy course workspace |
| `course-workspace`, `templates-tab`, `screen`, `screens`, `article-editor` (`-[hash].js`) | no | lazy screens, loaded through `import()` on demand: the course workspace, certificate studio, organizations, reports and operations screens of `admin.js`, and the article editor (with `article-editor-vendor`) of the workbench |

Every hashed chunk is pulled in automatically through relative ESM imports (`base: './'`), so the
adapter only needs to keep the `dist/` files in one directory. Source maps (`*.js.map`) are optional
to ship. What a page loads, in document order:

- **A learner page** (catalogue, course, My learning, achievements, organizations, notification
  preferences, player, quiz): `learner-navigation.js` first, then at most one mount entry,
  `player.js`, `quiz-runner.js`, `credentials.js` or `notifications.js`, with its stylesheet and
  `public-theme.css`. A page with no React screen loads `learner-navigation.js` alone. After an SPA
  swap, `learner-navigation.js` itself loads the next page's marked entry and stylesheet, so the
  entry then evaluates on a page that already holds its mount and `data-vy-config`.
- **An administrator or workbench page**: exactly one entry pair, `admin.js` or `instructor.js`
  with its stylesheet, and no `learner-navigation.js`.
- Contributed bundles (Pro and third parties) load after the free entry of their page.

The build-only `.vite/manifest.json` identifies every entry, dependency, lazy screen and emitted
asset for packaging completeness checks. Runtime adapters do not need to read it. The final-output
guard parses actual JavaScript imports: no chunk may import a mounting entry, every secondary JS
file must be hashed and present, and the certificate studio, organizations and operations screens
must remain three independent lazy chunks. This prevents both duplicate entry/context execution
and mixed-version lazy loading after upgrades. The same guard fails on any static import cycle
between emitted chunks and names it: a chunk cycle has no safe load order, and the one between
`shared` and `question-types` blanked the learner player and stopped learner SPA navigation on
2026-09-30. Code in `src/shared` that needs `window.vidyalms` takes it from
`src/shared/vidyalms-namespace.ts`, never from the question type layer, which imports `shared`.
The same guard also fails when a module that `learner-navigation.js` evaluates translates at module
scope: that entry runs on pages that carry no `data-vy-config`, so such a table would keep its
English strings on every page an SPA swap brings in afterwards. Those tables are built with
`localizedStrings` (`src/shared/i18n.ts`), which rebuilds a table the first time it is read after
the page's catalogue changes.

A second gate, `scripts/check-entry-evaluation.mjs`, runs the build in a happy-dom page with every
request answered locally. Each entry is loaded alone with a config that satisfies its own mount
schema, so the app really mounts and renders, and then every lazy chunk it can reach is imported
once. Each learner entry is also loaded after `learner-navigation.js` on one page, both as a native
page and across an SPA swap. The gate fails when a file throws while it is evaluated, when an
uncaught error, an unhandled rejection or an app error boundary report surfaces while the page
settles, or when a mount stays empty or shows the malformed-config notice. A third check runs inside
the Rollup build (`appSourceCycleGuard` in `vite.config.ts`): any static import cycle between the
package's own source modules fails the build, because a cycle inside one chunk is invisible to the
chunk guard and holds only while nothing reads across it at module scope.

`npm run build` (`scripts/build.mjs`) runs vite, then all three post-build guards, and publishes
`.vite/manifest.json` only when every guard passed. A rejected build keeps its files for
inspection, with the manifest at `.vite/manifest.rejected.json` and the reasons in
`.vite/rejected.txt`, so `tooling/releases/ui-manifest.php`, both packagers and
`tooling/operations/install-dashboard-assets.ps1` all refuse it.

Both adapter packagers do this automatically (their "Step 2b"): they run `npm run build` here when
`dist/` is missing or older than `src/` (skipping with a warning where node is absent) and copy the
bundles into the shipped assets, `com_vidyalms/media/app/{js,css}` on Joomla and
`vidyalms/assets/app/` on WordPress, both gitignored and cache-busted by the extension version. The
live mounts are:

| Host | Player page | Quiz handoff (`urls.course` + `vy-quiz`) | API mode |
|---|---|---|---|
| Joomla | site view `player` (`/courses/{slug}/player`) | course view renders the runner mount | `query` via the site task `api.route` with `X-CSRF-Token` |
| WordPress | rewrite `/courses/{slug}/learn` | single-course template renders the runner mount | `prefix` via `rest_url('vidyalms/v1')` with `X-WP-Nonce` |

The `vy-quiz` handoff parameter and the `vy-lesson` deep-link parameter are ratified host contract:
both adapters route them.

The two administrator apps mount inside each host's own back end, one page per admin screen plus one
for the workbench:

| Screen | Joomla | WordPress |
|---|---|---|
| Dashboard | `administrator/index.php?option=com_vidyalms&view=dashboard` | `wp-admin/admin.php?page=vidyalms` |
| Courses | `…&view=courses` | `…?page=vidyalms-courses` |
| Enrolments | `…&view=enrolments` | `…?page=vidyalms-enrolments` |
| Learners | `…&view=learners` | `…?page=vidyalms-learners` |
| Certificates | `…&view=certificates` | `…?page=vidyalms-certificates` |
| Notifications | `…&view=notifications` | `…?page=vidyalms-notifications` |
| Settings | `…&view=settings` | `…?page=vidyalms-settings` |
| Course workbench | `…&view=workbench&id={course_id}` | `…?page=vidyalms-course&course={course_id}` |

The two notification SITE pages sit beside the player rather than in the back end, because the
second of them is reached with no session:

| Page | Joomla | WordPress |
|---|---|---|
| Preference centre | site view `notifications` | rewrite `/notifications` |
| Unsubscribe | site view `unsubscribe` carrying the token | rewrite `/unsubscribe/{token}` |

The two credential SITE pages sit beside them for the same reason: a certificate code is printed on
paper and the person checking it has no account at all.

| Page | Mount config | Reached by |
|---|---|---|
| My credentials | `screen: "credentials"` | a signed-in holder |
| Verification | `screen: "verify"`, `subject`, `code` | anyone, with no session |

The verification mount renders the RESULT; the `noindex` header, the meta tag and the rate limit are
core middleware on the route, because a security control written in an adapter covers only the routes
that adapter happens to own.

Joomla's administrator session is not its site session, so the component exposes the identical
passthrough task under `administrator/index.php?option=com_vidyalms&task=api.route&path=` and the
administrator mounts carry that base in `query` mode with the administrator form token. WordPress has
no separate administrator credential: its admin pages call `rest_url('vidyalms/v1')` with the
`wp_rest` nonce exactly as the learner pages do. Either way the principal is the logged-in
administrator and the core decides everything else.

`urls.settings` is the host page where the options actually are: the component's own Options screen on
Joomla, and on WordPress the Settings page's own form, which sits below the mount on the same page.

## Tokens and how a host restyles the apps

`src/shared/tokens.css` declares the `--vy-*` set from the Foundations artboard ON THE MOUNT
SELECTOR (the host owns `:root`): 13 colour tokens (`--vy-bg`, `--vy-surface`, `--vy-surface-2`,
`--vy-fg`, `--vy-fg-2`, `--vy-border`, `--vy-control-border`, `--vy-accent`, `--vy-accent-fg`, `--vy-accent-soft`,
`--vy-ok`, `--vy-warn`, `--vy-danger`) plus `--vy-radius`, `--vy-radius-lg`, `--vy-btn-radius`,
`--vy-font-body`, `--vy-font-display`, `--vy-text`, `--vy-focus`, `--vy-space`, `--vy-dur-1`,
`--vy-dur-2`.

**Hosts override by setting the same properties on the mount element or above it:**

```css
:root { --vy-accent: var(--template-link-color); }
#vidyalms-player { --vy-radius: 0; }
```

Internally the bundle reads an indirection (`--_vy-x: var(--vy-x, default)`), which is what makes
ancestor-level overrides inherit in while the bundled neutral defaults fill every gap. The dark
value set keys on the host's `data-color-scheme="dark"` (on the mount or any ancestor); host
overrides still win there. `--vy-dur-*` collapse to 0 under `prefers-reduced-motion`.

Utilities never hardcode a colour that has a token: Tailwind maps semantic names (`bg-surface`,
`text-fg-2`, `border-border`, `bg-accent`, `text-ok`, `rounded-lg`, `rounded-btn`, `font-display`,
`duration-1`) onto the tokens.

## Embedding guarantees

- Tailwind's preflight is off; a reset scoped under the mount ids replaces it. Every utility
  is emitted under `:is(#vidyalms-player, #vidyalms-quiz-runner, #vidyalms-instructor,
  #vidyalms-admin)`, so nothing in these bundles can restyle the host page, and host content
  rules cannot cheaply reach inside the mounts.
- Because that scope is a descendant selector, **utility classes do not work on the mount element
  itself**; the mount's own base look comes from tokens.css. App roots are children of the mount.
- No viewport units; no fixed positioning except dialogs; z-index ceiling 100; dialogs and
  tooltips portal into the mount, not `document.body`, so tokens and scoped styles keep applying.
- Focus: one global `:focus-visible` ring driven by `--vy-focus` (2px, 2px offset), for
  everything inside the mounts.

## The client renderer registry

A question type is **one registration**, not five edits to free-build files. Before this existed, a
new type meant editing the runner's `QuestionBody` switch, the `isAnswered` switch, the
`describeAnswer` switch, the workbench's `AnswerBlock` switch and one flat form model, which is a
fork of the free build in everything but name. The registry closes it, and the six free types are its
first six consumers, so the seam is exercised by the free build rather than by the first extension.

The contract is copied from the QTI Portable Custom Interaction Communication Bridge rather than
invented, which means a renderer written against VidyaLMS is structurally the same object as one
written against any QTI engine. It lives in `src/question-types/`, ships as `question-types-[hash].js`, and
is described in full in
[docs/technical/12-assessment-types.md](../../docs/technical/12-assessment-types.md).

### The one sanctioned global

`src/shared/config.ts` says every app reads only the `data-vy-config` JSON on its mount element.
`window.vidyalms` is the single documented carve-out, created at module scope by the **quiz-runner**
and **instructor** entries before either app mounts, because they are separate bundles that never
share a page:

| Global | What it is |
|---|---|
| `window.vidyalms.questionTypes` | the registry: `register`, `replace`, `get`, `entry`, `keys`, `subscribe` |
| `window.vidyalms.runnerPanels` | the runner panel registry: `register`, `replace`, `get`, `has`, `slots`, `subscribe` |
| `window.vidyalms.react` | the pinned React a third-party build marks external |
| `window.vidyalms.reactDom` | ditto, for `react-dom/client` |
| `window.vidyalms.jsxRuntime` | ditto, for `react/jsx-runtime` |
| `window.vidyalms.questionTypeContract` | the contract version this bundle speaks |
| `window.vidyalms.reactMajor` | the React major an authoring block must be built against |
| `window.vidyalms.availabilityReasons` | the availability reason renderer registry: `register`, `replace`, `get`, `has`, `keys`, `subscribe` (see `src/availability-reasons/`) |

Which keys a page publishes depends on its entry. `quiz-runner.js`, `instructor.js` and `admin.js`
create the question type surface above at module scope, before their app mounts; `quiz-runner.js`
adds `runnerPanels` and `admin.js` adds `adminScreens` at the same moment, and `conditionEditors`
and `certificateDesigners` appear the first time the workbench or the certificate studio asks for
them. `player.js`, `notifications.js`, `credentials.js` and
`learner-navigation.js` create nothing at load; on those pages `window.vidyalms` appears only when
a blocked item first renders its reason, and then holds `availabilityReasons` alone. Whichever
bundle arrives first creates the one namespace object and every later one extends it, in either
order, so a contributed bundle reads the keys it needs and never assumes the others.

The React runtime is published because the learner side is framework neutral (`mount(el, ctx)`) but
the authoring side is a `ComponentType`. Without it a third-party bundle would either ship a second
React, which breaks hooks and doubles the payload, or ship no authoring UI.

### How a third party registers a renderer

```ts
// acme-molecule.js, built with react and react-dom marked external and
// mapped onto window.vidyalms.react / window.vidyalms.reactDom.
window.vidyalms.questionTypes.register('x_acme_molecule', {
    version: 1,
    reactMajor: 19,
    mount(el, ctx) { /* fill el, return a QuestionInstance */ },
    answeredStatus(response, ctx) { /* notpresented | presented | attempted | answered */ },
    describeResponse(response, presented) { /* plain words for review and grading */ },
    interactionModes: () => ['POINTER_NO_DRAG', 'KEYBOARD'],
    conformanceResult: () => 'acme.kit.molecule.1',
    authoring: { Block, toFormValues, fromFormValues, blankFormValues, formSchema },
});
```

Load order is declarative, never hoped for: the Joomla adapter declares the third-party asset as
depending on the free bundle in `joomla.asset.json`, and the WordPress adapter does the same through
`wp_enqueue_script` dependencies. A deferred chunk that registers late still renders without a
reload, because the runner subscribes to the registry's change event.

Third-party type keys carry a reserved `x_<vendor>_` prefix. `questions.type` is `string:30`.

### The runner panel registry

The question type registry answers "who renders this item". It cannot answer "who renders the
confidence scale beside every item" or "who renders the feedback panel between two tries", because
neither belongs to a type: both belong to the attempt **behaviour**, which is a quiz-level policy
provider and never reaches a question type by construction.

`window.vidyalms.runnerPanels` is that second seam, installed by the quiz-runner entry beside the
first and reached the same way. It offers two named slots, each a place in the runner's own DOM with
its own props:

| Slot | Props | Rendered when |
|---|---|---|
| `certainty` | `{ scale, value, onChange, controlId, readOnly, locale, announce }` | the frozen snapshot carries a certainty scale |
| `item_feedback` | `{ behaviour, triesUsed, maxAttempts, showFeedback, showSolution, feedback, checking, onCheck, locale, announce }` | the resolved item session control allows more than one try |

```ts
window.vidyalms.runnerPanels.register('certainty', {
    version: 1,
    reactMajor: 19,
    Panel: MyConfidenceScale,
    behaviours: ['certainty_based'],
});
```

The slot set is closed and the renderers are open, on purpose. A slot is a place in the runner's DOM
with its own props, so a new slot is a runner change whatever registry it went through; a new
renderer for an existing slot, which is what a new behaviour actually needs, is a registration and
nothing else.

**The free build registers neither panel**, which is what makes the bundle the same bytes with or
without Pro. When a behaviour asks for a panel nothing registered, the runner renders a placeholder
that names what is missing, states that the learner's work is preserved and says what happens
instead, rather than rendering nothing at all.

### The availability condition editor registry

`window.vidyalms.conditionEditors` is the third published seam, installed by the instructor entry
beside the other two. It answers "who draws the form for this restriction", keyed by the condition
provider key exactly as it is stored on a leaf, so the key space is OPEN rather than a closed slot
set: the whole point is that nobody has to have heard of the key.

```ts
window.vidyalms.conditionEditors.register('acme.forum_posts', {
    version: 1,
    reactMajor: 19,
    Editor: MyForumPostsEditor,
    summarise: (payload) => `${payload.minimum} posts`,
});
```

The editor is handed `{ condition, payload, onChange, anchors, targets, controlId, locale, errors }`:
the provider's own catalogue entry, its unwrapped payload, the registered schedule anchors and the
items in this course resolved to titles.

**A miss is not a failure, and that is the point.** A condition with no registered editor is drawn
from the `params_schema` the provider declares, so a third-party condition is authorable the day it
installs with no client code at all; an editor adds the things a JSON Schema cannot say, such as
that a maximum score is exclusive or that a days-valued offset steps a local calendar. The free
build registers editors for its own five conditions through the same function a package uses.

### The certificate designer registry

`window.vidyalms.certificateDesigners` is the fifth published seam, installed by the admin entry
beside the others. It answers "who lets an author drag the elements of a certificate page around",
and it offers one closed slot, `page_designer`, which is a place in the admin's own DOM with its own
props.

```ts
window.vidyalms.certificateDesigners.register('page_designer', {
    version: 1,
    reactMajor: 19,
    label: 'Designer',
    Designer: MyPageCanvas,
});
```

The designer is handed `{ template, page, elementTypes, tokens, selectedElementId, onSelect,
onPlace, onReorder, onAdd, onRemove, busy, readOnly, locale, controlId, announce }`: the whole
aggregate, the palette exactly as the server declared it for this template, and callbacks.

**A designer never writes.** It reports intent through those callbacks and the free screen owns every
request, so a package cannot invent a payload shape, skip the optimistic bookkeeping or bypass the
idempotency key.

**A miss is not a failure, and that is the point.** The free build owns the whole template aggregate:
the pages, the elements, the palette from the element registry, the settings form each element type
declares, the preview and the publication findings, all drawn as a form. With no designer registered
that form is what an author uses and every element is still addable, positionable, editable and
removable; with one, the same elements can also be dragged. **The free build registers none**, which
is what keeps the bundle the same bytes with or without Pro.

Whatever a designer draws, it owes the same accessibility guarantee the eight client primitives owe:
a drag is a pointer gesture, and a pointer gesture that is the only way to do something fails SC
2.1.1 and SC 2.5.7. Every move a pointer can make, a keyboard must be able to make too, and both must
announce.

### What a module is

Five things, because the five old switches proved five things are needed:

| Member | What it is | Absent |
|---|---|---|
| `mount(el, ctx)` | the learner renderer; returns a `QuestionInstance` | required |
| `answeredStatus(response, ctx)` | `notpresented` / `presented` / `attempted` / `answered` | required |
| `describeResponse(response, presented)` | the saved answer as plain words | required |
| `interactionModes()` | at least `POINTER_NO_DRAG` and `KEYBOARD` | registration refused |
| `authoring` | `Block`, `toFormValues`, `fromFormValues`, `blankFormValues`, `formSchema`, optional `needsManualMarking` | the workbench renders the placeholder and writes the stored definition back untouched |
| `essentialDraggingJustification()` | required only when `POINTER_DRAG` is declared alone | registration refused |
| `conformanceResult()` | the identifier of a kit result | registers **flagged**, and the workbench says so |
| `supportedDefinitionVersions()` | which `schema_version` values the renderer can read | `[1]` |

`QuestionInstance` is `getResponse`, `setResponse`, `getState`, `setState`, `getErrors`, `setStatus`,
`destroy`. `getState`/`setState` are a pair on purpose: `getState` alone makes the stored UI state a
column that is written and never read, and the reason for splitting it from the response is that a
half-completed placement must survive navigation.

`ctx` carries `{ definition, response, state, status, readOnly, locale, accessibilityProfile,
announce, shell, mountChild, onready, ondone, onResponseChange, onStateChange }`. `status` is PCI's
own vocabulary (`interacting`, `suspended`, `closed`, `review`, `solution`).

### What a module must NOT own

- **No live region.** `ctx.announce(channel, message)` reaches the shell's four channels: `status`
  (saves, counts, drag messages), `alert` (validation failure and connection loss only), `log`
  (reorder announcements, incremental), `timer`. Messages are queued and serialised.
- **No error UI.** `getErrors()` returns `{ controlId, kind, message, partId? }`; the shell renders
  one GOV.UK-shaped summary above the content, moves focus to it and prefixes the document title. A
  composite's per-part errors group by `partId` in that one summary.
- **No DOM outside its own `el`.** The containment a grab needs is `ctx.shell.captureInteraction()`
  and `releaseInteraction()`, which set `inert` outside the item body; the header, timer, palette and
  navigation are the shell's. The conformance kit asserts this.
- **No global key handler**, no `user-scalable=no`, no clipboard, selection or context-menu
  suppression.

### The eight client primitives

`SingleSelectGroup`, `MultiSelectGroup`, `OrderedList`, `AssociationSet`, `RegionSet`,
`ScalarControl`, `TextEntry`, `ArtifactCapture`. A type composes them and inherits keyboard
operation, non-drag pointer operation, announcements and focus management. Every one answers to the
three-mode rule: operable by pointer without dragging, operable by keyboard, and a `presentation`
of `"linear"` giving the single-input equivalent (a native select, a position control, coordinate
fields, the parallel text list beside an image). Select-then-place is built once in
`useSelectThenPlace` and reused by ordering, matching, gap match and hotspot placement.

Composing them is what makes passing the conformance kit straightforward. It is not what makes
passing it automatic: `mount(el, ctx)` hands a module a bare element it may fill with anything.

### A registry miss is a normal path

An unregistered key, or a registered one whose renderer cannot read the served `schema_version`,
falls through to a generic renderer keyed on the **response shape** (cardinality plus base type,
which core knows because it is a real column), never to a blunt unsupported panel. It names the type,
states that the stored response is preserved, and renders it as readable text, so a missing associate
renderer still shows the chosen pairs and a missing point renderer still shows the coordinate list.
`answeredStatus` for that path is derived from the response itself, so a question with a working
third-party renderer is never shown as permanently unanswered.

### The conformance kit

`src/question-types/conformance.tsx` exports `runConformanceKit(fixture)`, a vitest helper a
third-party author runs against its own renderer:

```ts
import { runConformanceKit } from '@vidyalms/ui/src/question-types/conformance';

runConformanceKit({
    typeId: 'x_acme_molecule',
    definition,
    completedResponse: { /* … */ },
    completeWithPointer: async (user) => { /* single activations, no drag */ },
    completeWithKeyboard: async (user) => { /* keyboard only */ },
});
```

It runs the three-mode declaration, a keyboard-only completion, a pointer-without-drag completion,
an accessible-name-and-role assertion for every control, a 320 CSS pixel width check, a
state-indicator check (tokens only, and never colour alone), the DOM containment rule and the four
integrity prohibitions. `src/question-types/conformance.test.tsx` runs it against all six free types
and is the worked example to copy. A DOM without layout composites no colours, so the contrast and
reflow checks read declared tokens and declared widths; neither replaces a rendered audit.

## Shared foundation map

| Module | What it is |
|---|---|
| `src/shared/config.ts` | zod-validated `readMountConfig`, renders the in-place error state; documents the one sanctioned global |
| `src/shared/mount.tsx` | `mountApp({ mountId, schema, context, render })`: providers, error boundary, DOM-ready |
| `src/shared/context.ts` | mount config, mount element and API client contexts |
| `src/shared/api.ts` | `queryKeys` plus useCourse, useCurriculum, useLesson, useMyProgress, useAttempt, useStartAttempt, useSaveAnswers, useSubmitAttempt, useHeartbeat, useMyCertificates |
| `src/shared/a11y.ts` | `moveFocus`, the four-channel live region registry (`channelRegistry`, `createAnnouncer`, `useAnnouncer`) and the politeness-shaped `announce`/`useAnnounce` the app screens use |
| `src/shared/sanitize.ts` | `sanitizeHtml`, the one DOMPurify profile for authored HTML (reading bodies, assignment instructions, shared stimuli) |
| `src/question-types/` | the client renderer registry, its eight primitives, the shell (error summary, placeholder) and the six free modules |
| `src/shared/strings.ts` | all shared copy as one typed object; each app adds its own module |
| `src/shared/components/` | Button, Card, Badge, ProgressBar, Spinner, Skeleton, EmptyState, ErrorState, SaveIndicator, Dialog, Tooltip, VisuallyHidden, Icon, DataTable, Pagination |

Errors surface as `ProblemError` (RFC 9457) from the SDK; `ErrorState` knows how to render one.
The heartbeat ENGINE (15s loop, retry, offline escalation) belongs to the player app;
`useHeartbeat` is only the mutation. Query keys live in `queryKeys` only; invalidate through them.

The player app lives in `src/player/` (built from artboard 02, direction 1a): the curriculum
rail, the per-type lesson panes, the whole-panel states, and the heartbeat engine in
`src/player/heartbeat.ts` (15s cadence, 120s cap, visibility and blur pause, keepalive flush on
pagehide, offline escalation, 409 suspension). The quiz Start/Resume handoff is a link built by
`quizRunnerHref` in `src/player/lib.ts`: `urls.course` plus a `vy-quiz=<lessonId>` parameter the
Mount phase adapter routes to the quiz-runner mount.

The instructor workbench lives in `src/instructor/` (artboards 1a to 4b of 04 Instructor
Dashboard): one course, five tabs. Curriculum writes order as the whole list through the contract's
order routes, with move buttons and a keyboard-operable drag handle on every row, and its
add-lesson menu lists what `GET /v1/lesson-types` answers with each type's completion mode.
Quizzes carries the one question editor that serves every registered type: prompt, the answer block
the client renderer registry resolves for that type, and marking. There is no switch on a type key
in the workbench; `src/instructor/lib/question-definitions.ts` owns only the shared prompt, points
and feedback fields and delegates the rest. Correctness reaches the browser
from `GET /v1/manage/quizzes/{id}` alone. Gradebook is the queue and the table over one route;
Learners keeps a suspended learner's progress visible and re-activates through an entitlement
extension; Settings holds the fields, the three completion rules, prerequisites, instructors and
the publish and archive moves that PATCH deliberately cannot make. Every write runs through
`useSaveTracker`, so a retry replays the same `Idempotency-Key` instead of executing twice.

The admin app lives in `src/admin/` (artboards 1a and 2a to 2e of 05 Admin): the site-wide
dashboard, the course, enrolment and certificate lists over the shared DataTable, the learner
record, and the read-only settings screen. Its hooks are in `src/admin/api.ts`, keyed under
`['vidyalms', 'admin']` so the shared root invalidation still reaches them. Every list sorts,
filters and pages on the server, because the contract pages before it sorts.

Built from the UX design in `docs/design/project` (01 Foundations, 02 Learner Player, 03 Quiz
Runner, 04 Instructor Dashboard, 05 Admin). See [docs/technical](../../docs/technical/README.md).
