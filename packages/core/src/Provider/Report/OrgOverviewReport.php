<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Report;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationOverviewReader;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\AggregatesPeople;
use Shondalai\VidyaLMS\Core\Registry\Report\OrgScopedReportInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportDefinitionInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportResult;

/**
 * One organisation in six numbers: people, courses reached, learning under way,
 * and where the seats stand.
 *
 * **It is the re-homing amendment C7 scheduled for this wave.**
 * `ManageOrganisationLearning::summary()` behind
 * `GET /manage/organisations/{id}/summary` was a second report sitting outside
 * the catalogue with a signature of its own; C7 rules that it becomes
 * `org-overview` here, and that the shipped route stays permanently as an alias
 * answering the same shape from this definition. Both now read
 * {@see OrganisationOverviewReader}, so there is one set of queries rather than
 * two that can drift.
 *
 * It is **scalar**, so it is absent from the export routes by the published
 * rule: six numbers can never be a CSV, and a report that pretended otherwise
 * would produce a one-row file whose columns are its own field names.
 *
 * It declares {@see AggregatesPeople}, which is what makes the summary block
 * pass through small-cell suppression. A five-person organisation's overview is
 * the smallest cell in the product, and a summary that escaped the rule every
 * table row obeys would be the disclosure the rule exists to prevent, one screen
 * higher up.
 */
final class OrgOverviewReport implements
    ReportDefinitionInterface,
    FiltersByVisibility,
    HonoursOrgScope,
    OrgScopedReportInterface,
    AggregatesPeople
{
    public const KEY = 'org-overview';

    public function __construct(
        private readonly OrganisationOverviewReader $overview,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.report.org_overview');
    }

    /**
     * The capability the shipped organisation report already declares.
     *
     * The design's table says `vidyalms.manage_groups` for every organisation
     * report. That constant may not be named here: the conformance check that
     * keeps it out of site-wide guards refuses it anywhere but
     * {@see Capability} itself and the scope resolver, precisely so a provider
     * cannot turn the delegated entry probe into a tenancy answer. The
     * organisation route authorises through `OrganisationAccess`, which consults
     * it in the one place allowed to, and what this method answers is what the
     * **site-wide** route would ask, where a reports capability is the honest
     * answer. `org-access-log` shipped on the same reasoning and the two agree.
     */
    public function requiredCapability(): string
    {
        return Capability::VIEW_REPORTS;
    }

    public function parameterSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://vidyalms.org/schema/report/org-overview/1',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'include_descendants' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Whether a unit-scoped run folds in the subtree below it.',
                ],
            ],
        ];
    }

    public function generate(ReportContext $context): array
    {
        return $this->describe($context)->facts;
    }

    public function describe(ReportContext $context): ReportResult
    {
        return ReportResult::of($this->overview->facts($this->scope($context)));
    }

    /**
     * The three facts that count people. The context is unread here because
     * which facts are counts of people is a property of the report and not of
     * the run; a report whose answer varied by parameter would be a report
     * whose suppression a parameter could switch off.
     */
    public function countFactKeys(ReportContext $context): array
    {
        return OrganisationOverviewReader::countFactKeys();
    }

    private function scope(ReportContext $context): OrgScope
    {
        return $context->orgScope ?? throw ValidationException::forField(
            'organisation_id',
            'This report reads one organisation and is only reachable through an organisation route.',
        );
    }
}
