<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * The progress sink, asked which organisation this run belongs to.
 *
 * A capability interface on the sink for the same reason RecordsRowProblems is
 * one: ImportProgress is shipped, a third-party importer has implemented it,
 * and adding a method to it would break that importer on upgrade. An importer
 * that mints organisation-scoped source keys probes:
 *
 * ```php
 * $organisationId = $progress instanceof KnowsItsOrganisation
 *     ? $progress->organisationId()
 *     : null;
 * ```
 *
 * ## Why it had to exist
 *
 * ImportRunner refuses a DeclaresSourceTypes importer whose job names no
 * organisation, on the grounds that every key it mints is composed as
 * "{organisation_id}:{foreign key}" and there is no id to compose with. It
 * then handed that importer nothing that named one: ImportProgress carried a
 * job id and a dry-run flag, and import() took a source, an options map and a
 * principal. So the only organisation an importer could reach was the one in
 * its own options map, which is the caller's payload, which is the one place
 * the design says the organisation may never come from: "the organisation
 * comes from the run context and never from the file". A mapper either
 * re-read import_jobs through the DatabasePort to find its own tenant, which
 * is a query no provider should be making, or trusted a number the caller
 * sent.
 *
 * The run context is the recorder, so the recorder is what carries it. Null
 * means the site, which is what a migration started from the site console is,
 * and an importer that mints reserved source types will already have been
 * refused before it saw one.
 */
interface KnowsItsOrganisation
{
    /**
     * The organisation this run belongs to, or null for a site-wide run.
     *
     * It is the id stamped on the import job by the run context, never one
     * read out of an options map or a source file.
     */
    public function organisationId(): ?int;
}
