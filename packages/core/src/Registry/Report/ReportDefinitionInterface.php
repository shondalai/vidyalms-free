<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Report;

use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;

/**
 * A report that publishes its parameters and answers a typed result.
 *
 * **It is a capability interface over the shipped contract, never a changed
 * signature.** {@see ReportProviderInterface} already took a context object,
 * and changing it a second time inside one release would break every external
 * implementation for the second time in two waves. So this is probed with
 * `instanceof`, exactly as `ContextAwareEnrolmentRule` is probed over the
 * enrolment rule contract, and a provider implementing neither this nor
 * {@see TabularReportInterface} keeps working byte for byte on the legacy
 * path with the parameter bag synthesised from the query string.
 *
 * Default when absent: the legacy `generate(ReportContext)` path. The report
 * renders, has no published filter form, and is absent from the export routes.
 *
 * The parameter schema is what lets the manage app render a report's filter
 * form without knowing the report, lets a schedule validate a saved parameter
 * set at save time rather than at 03:00, and gives the export layer column
 * types without asking the provider to format anything.
 *
 * **A condition is not a filter.** A condition is fixed by the definition,
 * invisible to the viewer and narrows the data set; a filter is chosen at run
 * time and must be declared here. Collapsing them lets a viewer widen a report
 * past its intended scope by supplying a parameter, which is the group-leader
 * leak wearing a different hat.
 */
interface ReportDefinitionInterface extends ReportProviderInterface
{
    /**
     * JSON Schema 2020-12 for the run-time filters: types, enums, the required
     * list and defaults.
     *
     * @return array<string, mixed>
     */
    public function parameterSchema(): array;

    /**
     * The run, already authorised by the caller.
     *
     * The capability check is not repeated here. It moved to
     * {@see \Shondalai\VidyaLMS\Core\Application\Assessment\GetCourseReport}
     * and to the reporting use cases beside it, because a provider that checks
     * its own capability is a provider that also has to filter by scope, and
     * the eleventh one forgets the second half. A definition is handed an
     * already-authorised context carrying the visibility scope it must honour.
     */
    public function describe(ReportContext $context): ReportResult;
}
