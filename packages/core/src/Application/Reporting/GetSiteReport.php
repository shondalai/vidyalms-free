<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Reporting;

use Shondalai\VidyaLMS\Core\Application\Platform\GetSiteLearningReport;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportParameters;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportDefinitionInterface;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;
use Shondalai\VidyaLMS\Core\Registry\Report\OrgScopedReportInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * Any registered site-scoped report, by its own key.
 *
 * `GET /manage/courses/{id}/reports/{key}` was the only generic report route,
 * so revenue, tax, subscription churn, coupon usage, payouts and organisation
 * dashboards had nothing to hang from: every one of them would have been a new
 * core route, a new core handler and a new contract operation, which is the
 * shape ADR 0011 says none of these should have.
 *
 * **The registry-miss contract is the shipped one, kept exactly.** An unknown
 * key answers 200 with `computed` false, an empty list and a translated reason,
 * **after** authorisation has run, so a caller with no claim cannot learn which
 * report keys a site has by reading the reasons apart. It is never a 404, and a
 * route that failed without a paid package would be a gate by another name.
 *
 * An organisation-only key takes the same answer here. That is not a special
 * case bolted on: the miss contract's whole purpose is that this route says the
 * same thing about every key it will not serve, and carving out a 403 for the
 * organisation ones would restore exactly the difference the contract exists to
 * remove.
 */
final class GetSiteReport
{
    /** Where the site says which zone its report windows are cut on. */
    public const TIMEZONE_SETTING = 'reports.timezone';

    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly AccessGuard $guard,
        private readonly TranslatorPort $translator,
        /**
         * The pipeline is here for the one read stage, so the screen and the
         * file disclose and suppress identically. Trailing and defaulted,
         * because a harness built before the tabular read existed still
         * constructs this class; absent, rows are rendered with no policy at
         * all, which is the shape a scalar-only build has anyway.
         */
        private readonly ?ReportExportPipeline $pipeline = null,
        /**
         * The site's report timezone. Trailing and defaulted, so every caller
         * built before the zone was read keeps UTC, which is what they were
         * stamping anyway.
         */
        private readonly ?Settings $settings = null,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function execute(Principal $principal, string $key, array $parameters = []): array
    {
        $page = $this->positive($parameters, 'page', 1);
        $perPage = $this->positive($parameters, 'per_page', 25, 100);

        // Authorisation first and always, on the widest reading of what a
        // report is, exactly as the course-scoped reader does it. A caller who
        // could not read any report must not be able to enumerate the site's
        // report keys by watching which ones answer differently.
        $this->guard->require($principal, Capability::VIEW_REPORTS);

        try {
            $provider = $this->reports->get($key);
        } catch (UnknownProviderException) {
            return $this->placeholder($key, $page, $perPage);
        }

        if ($provider instanceof OrgScopedReportInterface) {
            return $this->placeholder($key, $page, $perPage);
        }

        $this->guard->require($principal, $provider->requiredCapability());
        $scope = GetSiteLearningReport::scopeFor($this->guard, $principal);

        // The mirror of what the organisation read does with HonoursOrgScope,
        // and for the same reason. FiltersByVisibility's own default is that a
        // provider which does not declare it is site-wide by definition and a
        // scoped run refuses it. Without the probe, a report registered from a
        // KernelReady listener that ignores the scope returns site-wide rows
        // to a caller narrowed to their own courses, and the core trusts it
        // rather than refusing it.
        if (!$scope->isSiteWide() && !$provider instanceof FiltersByVisibility) {
            return $this->placeholder($key, $page, $perPage, 'vidyalms.report.scope_unsupported');
        }

        // The definition's own published schema, applied before the provider
        // sees the bag. It had no caller anywhere, so a filter the definition
        // never declared reached the context exactly as the query string
        // spelled it, which is how the interface's own "a condition is not a
        // filter" rule was bypassed.
        if ($provider instanceof ReportDefinitionInterface) {
            $parameters = ReportParameters::validate($provider->parameterSchema(), $parameters);
        }

        $context = ReportContext::create($parameters, $principal, $scope, null, null, null, null, 'en-GB', null, $this->timezone());
        $result = $provider->generate($context);

        // The one shared stage, run identically here and on the organisation
        // route and in the export writer. This path used to suppress rows
        // alone: it never disclosed, never probed AggregatesPeople and never
        // suppressed the scalar half, so a site-wide report whose facts are
        // counts of people was served with the counts in them.
        $omitted = [];

        if ($this->pipeline !== null) {
            [$result, $omitted] = $this->pipeline->screenStage($provider, $context, $result, $page, $perPage);
        } elseif ($provider instanceof TabularReportInterface) {
            // The harness path, for a caller built before the tabular read
            // existed. No policy is resolvable without the pipeline, so the
            // rows are rendered unsuppressed, which is the shape a scalar-only
            // build has anyway.
            $result = self::withoutPolicies($provider, $context, $result, $page, $perPage);
        }

        if ($provider instanceof TabularReportInterface) {
            $result['total'] = $provider->describe($context)->rowCount;
        }

        return $result + [
            'report' => $key,
            'computed' => true,
            'computed_by' => $provider->key(),
            'reason' => null,
            'omitted_fields' => $omitted,
            'items' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * The site's own report timezone, which is what makes an artefact
     * regenerable.
     *
     * `report_runs.timezone` is non-null and part of the scope digest for the
     * stated reason that an extract cut on the wrong midnight cannot be
     * reproduced, and every synchronous path stamped the constant UTC because
     * nothing asked. The scheduled path already carries the schedule's own
     * zone.
     */
    private function timezone(): string
    {
        $zone = $this->settings?->getString(self::TIMEZONE_SETTING, 'UTC') ?? 'UTC';

        return $zone === '' ? 'UTC' : $zone;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function withoutPolicies(
        TabularReportInterface $provider,
        ReportContext $context,
        array $result,
        int $page,
        int $perPage,
    ): array {
        $columns = $provider->columns($context);
        $items = [];
        $taken = 0;

        foreach ($provider->rows($context) as $row) {
            $taken++;

            if ($taken <= ($page - 1) * $perPage) {
                continue;
            }

            if ($taken > $page * $perPage) {
                break;
            }

            $items[] = $row;
        }

        $result['items'] = $items;
        $result['suppressed_cells'] = 0;
        $result['columns'] = array_map(
            static fn (ColumnDefinition $column): array => $column->toArray(),
            $columns,
        );

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholder(
        string $key,
        int $page,
        int $perPage,
        string $reason = 'vidyalms.report.not_installed',
    ): array {
        return [
            'report' => $key,
            'computed' => false,
            'computed_by' => null,
            'reason' => $this->translator->translate($reason, ['report' => $key]),
            'items' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * A pagination parameter, read numerically.
     *
     * A query string is always strings, so the strict `is_int` reading this
     * used to do meant `page` and `per_page` could never move off their
     * defaults from the route that publishes them: the two parameters were
     * declared in the contract and inert in the code. The bound on `per_page`
     * is the same one the site learning report already enforces, because a
     * caller must not be able to ask for the whole table one page at a time.
     *
     * @param array<string, mixed> $parameters
     */
    private function positive(array $parameters, string $key, int $default, int $max = 1000000): int
    {
        $value = $parameters[$key] ?? null;

        if (\is_string($value) && preg_match('/^[0-9]{1,9}$/D', $value) === 1) {
            $value = (int) $value;
        }

        return \is_int($value) && $value > 0 ? min($value, $max) : $default;
    }
}
