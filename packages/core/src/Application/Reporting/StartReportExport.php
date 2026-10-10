<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Reporting;

use Shondalai\VidyaLMS\Core\Application\Platform\GetSiteLearningReport;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportParameters;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportScopeDigest;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\OrgScopedReportInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * The write half of the asynchronous export contract.
 *
 * `POST /v1/manage/reports/{key}/exports` answers 202 with a run id and a
 * Retry-After hint, and `GET /v1/manage/report-runs/{id}` is polled until the
 * run is terminal. Canvas implements exactly this shape for content exports,
 * and 11-risks.md already states the policy that reports are computed by
 * scheduled jobs and never on page load. **This is the enforcement point that
 * policy currently lacks.**
 *
 * A run is computed inline here rather than queued, and that is deliberate for
 * this wave rather than an oversight. Both hosts' schedulers are unreliable by
 * their own documentation (Joomla's lazy scheduler has a floor and fires from
 * frontend responses, WP-Cron fires on page load or never), so an export that
 * only ever existed as a queued job would be an export that never arrives on a
 * quiet site. The contract is what matters and it is honoured either way: the
 * caller is handed a run id and polls, so moving the work behind the queue in a
 * later wave changes nothing a caller can see. The row cap is what keeps the
 * inline run honest, and above it the caller is refused with the reason.
 */
final class StartReportExport
{
    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly ReportExportPipeline $pipeline,
        private readonly AccessGuard $guard,
        /**
         * The site's report timezone. Trailing and defaulted, so every caller
         * built before the zone was read keeps UTC, which is what it stamped.
         */
        private readonly ?Settings $settings = null,
    ) {
    }

    /** The zone this site cuts its report windows on. */
    private function timezone(): string
    {
        $zone = $this->settings?->getString(GetSiteReport::TIMEZONE_SETTING, 'UTC') ?? 'UTC';

        return $zone === '' ? 'UTC' : $zone;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function execute(
        Principal $principal,
        string $key,
        string $formatKey,
        ?string $deliveryKey = null,
        array $parameters = [],
    ): ReportRun {
        $this->guard->require($principal, Capability::VIEW_REPORTS);

        try {
            $provider = $this->reports->get($key);
        } catch (UnknownProviderException) {
            // The read route answers a miss with 200 and a reason because it
            // has a shape to answer with. A write has no artefact to hand back
            // and no run to poll, so it is a 404 on the run that was never
            // created rather than a 202 promising one.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('report', $key);
        }

        if ($provider instanceof OrgScopedReportInterface) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('report', $key);
        }

        if (!$provider instanceof TabularReportInterface) {
            throw ValidationException::forField('report', sprintf(
                'The "%s" report is scalar, so there is nothing to export; only a tabular report has rows.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $key,
            ));
        }

        $this->guard->require($principal, $provider->requiredCapability());
        $scope = GetSiteLearningReport::scopeFor($this->guard, $principal);

        // The write mirror of the read route's probe, and of the organisation
        // export's HonoursOrgScope refusal. A provider that does not declare
        // FiltersByVisibility is site-wide by definition, so a narrowed run
        // is refused rather than trusted to narrow itself: an export is a
        // file of personal data that outlives the request that asked for it.
        if (!$scope->isSiteWide() && !$provider instanceof FiltersByVisibility) {
            throw ValidationException::forField('report', sprintf(
                'The "%s" report was not written to honour a visibility scope, so it is refused inside one '
                . 'rather than trusted to narrow itself.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $key,
            ));
        }

        // Before the run row opens, so a bad filter costs a 422 rather than a
        // manifest row and an artefact of personal data. No instanceof here:
        // TabularReportInterface extends ReportDefinitionInterface, and the
        // refusal above has already established which one this is.
        $parameters = ReportParameters::validate($provider->parameterSchema(), $parameters);

        // The site's own zone, not the constant UTC every synchronous path
        // stamped. `report_runs.timezone` is non-null and part of the scope
        // digest for the stated reason that an extract cut on the wrong
        // midnight cannot be regenerated identically, and nothing was asking.
        $context = ReportContext::create(
            $parameters,
            $principal,
            $scope,
            null,
            null,
            null,
            null,
            'en-GB',
            null,
            $this->timezone(),
        );
        $this->refuseOversizedRun($provider, $context);

        return $this->pipeline->execute(
            $provider,
            $context,
            $formatKey,
            $deliveryKey,
            ReportScopeDigest::of($scope),
        );
    }

    /**
     * A synchronous streamed response is allowed only under a documented row
     * cap and refused above it.
     *
     * The cap is asked of the report before the run opens, so a refusal costs
     * one count rather than a materialised export, and the reason names the
     * number rather than saying the request was too large.
     */
    private function refuseOversizedRun(TabularReportInterface $provider, ReportContext $context): void
    {
        $cap = $this->pipeline->syncRowCap();
        $expected = $provider->describe($context)->rowCount;

        if ($expected > $cap) {
            throw ValidationException::forField('rows', sprintf(
                'This run would produce about %d rows and this site exports at most %d in one request. '
                . 'Narrow the report or raise reports.export_row_cap_sync.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $expected,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $cap,
            ));
        }
    }
}
