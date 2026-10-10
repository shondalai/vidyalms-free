# VidyaLMS core

The CMS-agnostic learning engine. PHP 8.1+, no CMS imports, ever. The core owns every learning rule; the
Joomla and WordPress adapters supply users, database, permissions, files, mail, scheduling and eventing
through the ports defined in `src/Port`.

## Layout

```
src/
├─ Domain/          entities, value objects, repository interfaces and domain events per bounded context
├─ Application/     use cases; every rule and authorization check lives here, not in controllers
├─ Port/            the fixed set of interfaces an adapter must implement
├─ Registry/        extension points and their default (free) providers
├─ Http/            framework-neutral request, response and handlers for the /v1 contract
├─ Infrastructure/  DatabasePort-backed repository implementations, one per bounded context
└─ Schema/          loader for the neutral table definitions in schema/tables.php
schema/tables.php   the single source of truth for every table; compiled per CMS at build time
tests/              PHPUnit against SQLite with fake ports; runs in seconds, no CMS loaded
```

## Rules of the core

- No CMS imports. `use Joomla\...` or a WordPress function anywhere under `src/` is a build failure.
- Authorization checks happen inside use cases, so no API surface can bypass them.
- Attempts are append-only; grading writes separate rows so re-grading keeps history.
- The completion engine is a pure function over progress. Completion emits exactly one set of events.
- Every mutating operation is idempotent by client-supplied key.
- Quiz correctness never leaves the server before an attempt is submitted.

## Running checks

```
composer install
composer test
composer analyse
composer cs:check
```
