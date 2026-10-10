# The database port contract

`DatabasePortContract` is the shared proof that every implementation of
`Shondalai\VidyaLMS\Core\Port\Database\DatabasePort` answers the same
question the same way. There are three of them:

| Implementation | Where | Runs the contract from |
|---|---|---|
| `SqliteDatabase` | `packages/core/tests/Support` | `tests/Integration/Port/DatabasePortContractTest.php` |
| `JoomlaDatabase` | `packages/adapter-joomla` | `build/smoke.php`, and `build/port-contract.php` on its own |
| `WpDatabase` | `packages/adapter-wordpress` | `build/smoke.php`, and `build/port-contract.php` on its own |

Each adapter's `build/port-contract.php` boots that CMS from the command line, resolves the port
from the host's own container and runs nothing but this contract, which is the quicker loop while a
compiler is being written. The smoke harnesses run it as one of their sections, so it is covered by
the ordinary gate either way.

It is a plain class rather than a PHPUnit test case because two of the three
only exist inside a booted CMS, where PHPUnit is not loaded. One body of
assertions, three callers.

## What it covers

The parts of the port a repository cannot verify for itself, because they
compile to SQL the adapter writes:

- plain aggregates (`COUNT(*)`, `COUNT(column)`, `MIN`, `MAX`, `SUM`, `AVG`)
  under an alias, over a `GROUP BY`
- conditional counts, `COUNT(CASE WHEN ... THEN 1 END)`, including the case
  a `LEFT JOIN` that matched nothing must count as zero and not as one
- a grouped aggregate over a join, ordered on the aggregate alias and cut to
  a page at the database, which is what the managed course list sorts on
- rates, `AVG(CASE WHEN domain AND match THEN 1.0 WHEN domain THEN 0.0 END)`,
  whose answer must be null and not zero when the domain is empty
- `nullsLast` ordering in both directions, which neither MySQL nor MariaDB
  spells natively, so each adapter compiles it as a leading
  "column IS NULL" key
- one level of OR (`whereAnyOf`), including the empty `IN` branch
- `count()` of a grouped query, which counts groups and not rows

## Running it

```php
require_once __DIR__ . '/../../tooling/contract/bootstrap.php';

use Shondalai\VidyaLMS\Tooling\Contract\DatabasePortContract;

$failures = DatabasePortContract::failures($database);
```

An empty array means the port satisfies the contract. Each entry is one line
naming the expectation, what it wanted and what it got.

## Safety

Every row the contract writes goes in inside a transaction it deliberately
rolls back, so a run against a live site leaves nothing behind. It creates no
table and drops no table, which is the rule in
[10-coding-standards.md](../../docs/technical/10-coding-standards.md). Rows
carry the `__vidyalms_port_contract__` marker in their title and slug, so a
host whose transaction support silently did nothing leaves evidence that says
exactly what happened.

# The assessment foundation contract

`AssessmentFoundationContract` is the second shared body of assertions in this
folder, and it answers a different question: not "does this port compile SQL
correctly" but "did the free schema actually land on this site, and does an
attempt that predates it still read".

| Runs from | Against |
|---|---|
| `packages/adapter-joomla/build/smoke.php` | the Joomla site the run is pointed at |
| `packages/adapter-wordpress/build/smoke.php` | the WordPress site the run is pointed at |

## What it covers

- the four tables 1.1.0 adds answer a query compiled by this host's port
- every column 1.1.0 and 1.3.0 add is addressable in a predicate, so a column
  that arrived under a different name fails here and not on the first write.
  The two versions are counted separately, because a site that upgraded
  through 1.1.0 and stalled before 1.3.0 is a different problem from one whose
  1.1.0 never ran
- every question row carries the marking rule, response shape and scoring
  marker the migration writes, and **every scoring policy key it wrote
  resolves in the free build's own registry**: a pin naming a provider only
  Pro registers would leave a free site unable to grade an existing question
- every attempt already on the site reads back: through the attempt
  repository, through `AttemptManifest::forAttempt()` (which for an attempt
  with no `attempt_questions` rows is the pre-manifest fallback, and that path
  can only be exercised where such attempts exist), through `answersFor()`,
  `submissionsFor()` and `pendingCountFor()`, and a graded attempt still has
  its grade rows
- every task type the core queues has a handler in the scheduled task
  registry, and an unregistered type reports a miss rather than answering

## Running it

```php
require_once __DIR__ . '/../../tooling/contract/bootstrap.php';

use Shondalai\VidyaLMS\Tooling\Contract\AssessmentFoundationContract;

['failures' => $failures, 'facts' => $facts] = AssessmentFoundationContract::run($kernel, $ports);
```

## Safety

It is read only. It writes no row, creates no table and drops none, so it runs
inside both smoke harnesses under their schema seals and leaves the site
exactly as it found it. The shape of the schema is checked separately by
`tooling/schema/verify.php`, which needs a database of its own and therefore
creates and drops one.

# The Pro schema contract

`ProSchemaContract` is the third, and it is the Pro counterpart of the second.
`tooling/schema/verify.php --pro` proves the shape by comparing two
INFORMATION_SCHEMA readings and never asks the adapter a question. This asks
the adapter, over the three shapes wave 3b introduced and in the order an
author meets them.

| Runs from | Against |
|---|---|
| `packages/pro-joomla/build/smoke.php` | the Joomla site the run is pointed at, in **both** halves of its edition proof |
| `packages/pro-wordpress/build/smoke.php` | the WordPress site the run is pointed at, in **both** halves |

Being in both halves is the point. With Pro installed it says the package's
installer produced tables this adapter can address. With Pro removed it says
the same thing, which is the stronger claim: removing Pro leaves the bank, the
slot tree and the rubrics exactly where they were, and these assertions are
what would notice if an uninstall had quietly taken them.

## What it covers

- **a banked quiz with a pool slot.** A category, a bank entry, a version
  pinned to the immutable question row, and a slot tree with a section, a
  question slot resolving the latest version and a pool slot carrying a
  serialised predicate tree. It proves the tree reassembles in position order,
  that "where is this used" is answered by the `bank_entry_id` column rather
  than by scanning stored specs, that `required` and `fixed` stay two
  independent columns, that a null `question_version_id` survives as null
  rather than as zero, and that a nested and/or/not tree comes back a tree
  through the host's JSON column
- **a rubric-graded submission.** A rubric, a version, criteria and levels, and
  one criterion-level row per criterion against a grade row the site actually
  has. It proves the level id is stored beside the points, which is what makes
  analytic feedback and rater agreement possible at all, that the grade names
  the rubric version it ran under, and that a second rubric version leaves the
  historic grade pointing at the first
- **a regrade.** A second grade row for the same attempt, question and part, at
  version 2. It proves the append-only invariant holds with the new columns on
  the row: the version 1 row is untouched, the frozen manifest is untouched,
  and the attempt still reads afterwards

The list of Pro tables is read from `packages/pro-core/schema/tables.php` rather
than copied out of it, because a table can move between the two editions and one
already has: a copy here would go on naming a Pro table the free package now
installs, and would pass while saying something untrue.

## Running it

```php
require_once __DIR__ . '/../../tooling/contract/bootstrap.php';

use Shondalai\VidyaLMS\Tooling\Contract\ProSchemaContract;

['failures' => $failures, 'facts' => $facts] = ProSchemaContract::run($kernel, $ports, $quizId, $questionId);
```

## Safety

Every row it writes goes in inside a transaction it deliberately rolls back,
exactly as `DatabasePortContract` does, and the three roots it marks are counted
again afterwards so a host whose transaction support quietly did nothing is
caught rather than trusted. It creates no table and drops none.

It writes rows rather than reading them because the question cannot be answered
by reading: on a site where nobody has authored a bank yet, every one of these
tables is empty, and an empty table proves only that it exists.

# Route-to-contract parity

`route-parity.php` boots the free kernel, enumerates every route it registers,
and fails unless that set is exactly the operations `openapi.yaml` declares for
the free build.

```
php -d extension=pdo_sqlite -d extension=sqlite3 tooling/contract/route-parity.php
```

or `composer contract:parity` from `packages/core`. Exit code 0 means parity, 1
means a difference and the script names every operation on either side of it.

It exists because the match was being held by hand. Twenty-nine organisation
path items carried exactly the thirty-nine organisation routes the kernel
registers, correctly, and nothing at all held them there; separately, twenty
credential endpoints once sat on the contract, in the generated SDK and on no
server, which no unit test can see because every one of them passes.

## The four ways it fails

| Failure | What happened |
|---|---|
| Registered by the free kernel and declared nowhere | a route was added without a path item |
| Declared as free and registered by no free route | a path item promises an operation nothing serves |
| Allow-listed as Pro and served by the free build | an operation moved edition and the list did not |
| Allow-listed as Pro and declared by no path item | a path was renamed and the list still names the old one |

## The Pro allow-list

Everything the contract declares is free unless `x-vidyalms-editions.pro` in
`openapi.yaml` lists it. That block is the one place an edition is recorded, and
the last two failures above pin it from the free side, so it cannot rot into a
comment: every entry must be a real path item, and no entry may be an operation
the free build serves.

The check never loads `packages/pro-core`. A free build's gate that needs a paid
package to run is a gate the free build cannot run. The other half of the claim,
that the list is exactly what Pro adds, belongs with `pro-core`, which owns it.
To re-derive the list, attach `ProKernel` to a booted `CoreKernel` in a scratch
script and diff the route sets before and after; the seventy-four entries there
now were derived that way and verified against
`packages/pro-core/src/Http/*Routes.php`.

## Reading the contract without a YAML parser

`OpenApiPaths` reads the path items. It is not a YAML parser: the core takes no
runtime dependencies and neither adapter ships one, so it handles exactly the
three shapes `openapi.yaml` is authored in (block mappings, a flow mapping on
the path key's own line, and a pretty-printed flow mapping under the key or
under one method) and raises on anything else. Under-reporting is the one answer
it must never give, because a path item it silently could not read would look
like an operation nobody declared.

## Two paid tiers, one union, and a second contract file

`x-vidyalms-editions` carries `business` beside `pro`, and an operation belongs
to exactly one of them. `OpenApiPaths::proOperations()` and
`businessOperations()` read them separately and `paidOperations()` returns the
union; route parity compares the union, because its only question is whether a
free build serves the operation and both lists answer no. An operation on both
lists fails, in this script and again in `ProRouteParityTest`, because a tier
nobody owns makes the edition table unanswerable.

There is no separate Business package: the tier is a `BusinessEdition` marker on
a family inside `packages/pro-core`, so one `ProKernel::attach` adds both lists'
routes and nothing here boots a third kernel
(see `docs/technical/adr/0028-business-is-a-flag-on-a-family-and-byte-identity-is-a-test.md`).

`openapi-scim.yaml` is the SCIM 2.0 surface's own contract. It is separate
because the surface needs `application/scim+json`, the SCIM error envelope with
`scimType`, one-based `startIndex` and `count` paging, `ETag` with `If-Match`
and a `429` with `Retry-After`, none of which match the error model every
operation in the main document answers.

**Its operations are deliberately not merged into the comparison.** The surface
is served by `ScimSurface`, which owns its own route table rather than mounting
on `ApiKernel`, so this script cannot see it at all: it enumerates `ApiKernel`
routes. Merging the declarations in would turn all fifteen into free
declarations nothing serves, which is the exact failure this whole check exists
to report.

So the claim is split. `packages/pro-core/tests/Integration/ScimContractParityTest.php`
asserts that `ScimRoutes::all()` and `openapi-scim.yaml` declare exactly the same
operations in both directions, and that no SCIM operation also appears in
`openapi.yaml`. This script enforces the one direction it can see: a
`/scim/v2` route that ever appears on the free kernel and is not declared there
fails, so nobody adds a SCIM route to any build without the contract moving
with it.
