<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * An importer an organisation administrator may start from their own console.
 *
 * A capability interface rather than a list of keys in the use case, for the
 * reason every capability in this codebase is one: a list in a use case is a
 * list a third-party roster mapper cannot get onto without a core edit, and a
 * screen-specific rule is exactly what the coding standards forbid. Declaring
 * this is the importer saying "I am safe to run under a tenant's authority".
 *
 * ## What declaring it costs the importer
 *
 * Three obligations, and an importer that cannot meet all three must not
 * declare it:
 *
 * - **It reads only what the run context gives it.** An org administrator is
 *   not a site manager, so an importer reachable from their console must not
 *   read the host's own tables, another tenant's rows, or any file the
 *   organisation did not upload. The three shipped course importers and the
 *   six format importers all read the host database, which is the site's, so
 *   none of them declares this.
 * - **It writes inside the tenant.** Every organisation-scoped object it
 *   records is keyed through ImportSourceKey::forOrganisation() with the
 *   organisation from the run context.
 * - **It refuses to widen the scope.** Nothing it reads out of the source may
 *   name an organisation.
 *
 * ## Why the absence of this interface is the safe default
 *
 * Before it existed, OrganisationImports::start() passed whatever importer key
 * the body carried straight into the runner, so an org administrator holding
 * MANAGE_GROUPS could preview any registered importer, including the ones that
 * read the host's own database, and could aim any of them at any path in the
 * site's private store. The absence of a declaration is now a refusal, so a
 * new importer is site-only until somebody decides otherwise in code.
 *
 * The two roster importers of R5, org-members-csv:in and oneroster-csv-1.2:in,
 * are the first shipped declarers. Until they land, the free build's
 * organisation import surface is reachable and has nothing registered to run,
 * which is an honest empty list rather than a padlock.
 */
interface RunsForAnOrganisation extends ImporterInterface
{
}
