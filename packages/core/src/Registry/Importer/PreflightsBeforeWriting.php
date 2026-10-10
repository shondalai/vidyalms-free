<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * An importer that wants its dry pass run automatically, before every real
 * pass, in the same process.
 *
 * The dry run was already in the contract and it was an operator's choice.
 * That is the wrong shape for a roster: referential integrity is a
 * precondition of a whole file set, so the run that discovers on row nine
 * thousand that half the enrolments name a class no file defines has already
 * written eight thousand nine hundred rows. An operator who did not think to
 * tick the box gets the outcome the box existed to prevent.
 *
 * So an importer declaring this is run twice by ImportRunner: once with
 * dry_run true, which parses, resolves every reference, counts what it would
 * create, reports its row problems and writes nothing at all; then once for
 * real. Both passes report into one progress sink, so the totals are one run's
 * and the job log tells one story.
 *
 * **Preview and run share one parse**, which is what makes the second pass
 * nearly free and is the reason this is a capability interface rather than a
 * flag the runner sets on everybody: the sharing is the importer's to
 * implement (a parsed file held on the instance for the length of the run) and
 * an importer that cannot share one should not declare this and pay for two.
 * The three shipped course importers do not declare it and are run exactly
 * once, as they always have been.
 */
interface PreflightsBeforeWriting extends ImporterInterface
{
}
