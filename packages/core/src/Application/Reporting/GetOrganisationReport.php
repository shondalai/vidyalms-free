<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Reporting;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationRetentionPolicies;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportParameters;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportDefinitionInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * A registered report over one organisation, read or exported.
 *
 * **The organisation is in the path and never in a parameter.** That is the
 * design's rule and 04-api-contract.md's, and the reason is the one the
 * condition-versus-filter split states: a run-time parameter that could name
 * the tenant is a run-time parameter that can widen a run past the tenant its
 * caller was authorised for. The scope comes from
 * {@see OrganisationAccess::require()}, which is the first production caller
 * that method has had, and the provider is handed the resolved scope rather
 * than an id it would have to re-derive.
 *
 * **A provider that does not declare {@see HonoursOrgScope} is refused**, even
 * where it declares `FiltersByVisibility`. That marker carries no method, so
 * declaring it is a claim nothing can check, and every provider carrying it
 * today was written against four scope types and is being handed one of the
 * two the organisation resolver owns. This is where that published refusal
 * stops being a comment.
 */
final class GetOrganisationReport
{
    /**
     * The right an organisation report is read under.
     *
     * It is `events` and not a new `reports` action, deliberately. The role
     * vocabulary is seven rights declared by four shipped role providers, and
     * `org_reporter` holds exactly `events` and `read`; adding an eighth would
     * be an edit to every one of those providers plus the registry's own
     * subset rule, to express a right one of them already expresses. The first
     * organisation report reads the access log, which is an events read by any
     * reading, and the existing audit export already authorises under the same
     * right. A later report whose subject is not an event is where a new right
     * earns its edit.
     */
    public const ACTION = 'events';

    /**
     * The largest page this route serves, matching the site route exactly.
     *
     * It had none, so `per_page=1000000` answered every entitled learner of an
     * organisation in one response on the one route that serves row-per-person
     * organisation data.
     */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly OrganisationAccess $access,
        private readonly ReportExportPipeline $pipeline,
        private readonly TranslatorPort $translator,
        /**
         * The owning organisation own retention policy, or nothing.
         *
         * Trailing and defaulted, so a caller built before the policy had a
         * reader keeps constructing this class and keeps the site value.
         */
        private readonly ?OrganisationRetentionPolicies $retention = null,
        /**
         * The site's report timezone, which is part of a run's identity rather
         * than of its rendering. Trailing and defaulted, so every caller built
         * before the zone was read keeps the UTC it was stamping.
         */
        private readonly ?Settings $settings = null,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    public function read(Principal $actor, int $organisationId, string $key, array $parameters = []): array
    {
        $scope = $this->access->require($actor, $organisationId, self::ACTION);
        $page = self::positive($parameters, 'page', 1);
        // The same bound the site route enforces, and for the same stated
        // reason: a caller must not be able to ask for the whole table one
        // page at a time. This route is the one that serves row-per-person
        // organisation data, and it had no maximum at all.
        $perPage = self::positive($parameters, 'per_page', 25, self::MAX_PER_PAGE);

        try {
            $provider = $this->reports->get($key);
        } catch (UnknownProviderException) {
            return $this->placeholder($organisationId, $key, $page, $perPage);
        }

        if (!$provider instanceof HonoursOrgScope) {
            // Not a placeholder and not a refusal about the caller: the key
            // resolves, the provider exists, and it cannot honour the boundary
            // this run has. Saying so names the actual problem instead of
            // pretending the report is not installed.
            return $this->placeholder($organisationId, $key, $page, $perPage, 'vidyalms.report.scope_unsupported');
        }

        $context = $this->contextFor($actor, $scope, $this->validated($provider, $parameters));

        $result = $provider->generate($context);

        // The one shared stage, identical here and on the site route and in
        // the export writer. Each read path used to run half of it.
        [$result, $omitted] = $this->pipeline->screenStage($provider, $context, $result, $page, $perPage);

        // Named rather than silently absent: a reader told a column was withheld
        // knows the report has one, and a reader shown nothing does not.
        $result['omitted_fields'] = $omitted;

        return $result + [
            'organisation_id' => $organisationId,
            'report' => $key,
            'computed' => true,
            'computed_by' => $provider->key(),
            'reason' => null,
            'scope_digest' => $scope->digest(),
            'items' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function export(
        Principal $actor,
        int $organisationId,
        string $key,
        string $formatKey,
        ?string $deliveryKey = null,
        array $parameters = [],
    ): ReportRun {
        $scope = $this->access->require($actor, $organisationId, self::ACTION);

        try {
            $provider = $this->reports->get($key);
        } catch (UnknownProviderException) {
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

        if (!$provider instanceof HonoursOrgScope) {
            throw ValidationException::forField('report', sprintf(
                'The "%s" report was not written to honour an organisation scope, so it is refused inside one '
                . 'rather than trusted to narrow itself.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $key,
            ));
        }

        return $this->pipeline->execute(
            $provider,
            $this->contextFor($actor, $scope, $this->validated($provider, $parameters)),
            $formatKey,
            $deliveryKey,
            // Exactly OrgScope::digest(), never a second recipe. The run is
            // found by that digest and the actor id together, which is the
            // rule OrgScope's own docblock states for a per-actor artefact.
            $scope->digest(),
            $organisationId,
            [],
            null,
            \Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext::RENDER_AS_RECIPIENT,
            $this->retention?->artefactDaysFor($scope, $this->pipeline->siteArtefactDays()),
        );
    }

    /**
     * The bag, checked against the provider's own published schema.
     *
     * The site route and the Pro authoring surface both do this and this one
     * did not, so every organisation report's `parameterSchema()` was published
     * and ignored: a filter the definition never declared reached the context
     * exactly as the query string spelled it, which is how the interface's own
     * "a condition is not a filter" rule is bypassed.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function validated(object $provider, array $parameters): array
    {
        return $provider instanceof ReportDefinitionInterface
            ? ReportParameters::validate($provider->parameterSchema(), $parameters)
            : $parameters;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function contextFor(Principal $actor, OrgScope $scope, array $parameters): ReportContext
    {
        $zone = $this->settings?->getString(GetSiteReport::TIMEZONE_SETTING, 'UTC') ?? 'UTC';

        return ReportContext::create(
            $parameters,
            $actor,
            null,
            null,
            null,
            null,
            null,
            'en-GB',
            $scope,
            $zone === '' ? 'UTC' : $zone,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholder(
        int $organisationId,
        string $key,
        int $page,
        int $perPage,
        string $reasonKey = 'vidyalms.report.not_installed',
    ): array {
        return [
            'organisation_id' => $organisationId,
            'report' => $key,
            'computed' => false,
            'computed_by' => null,
            'reason' => $this->translator->translate($reasonKey, ['report' => $key]),
            'items' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function positive(array $parameters, string $key, int $default, int $max = 1000000): int
    {
        $value = $parameters[$key] ?? null;

        if (\is_string($value) && preg_match('/^[0-9]{1,9}$/D', $value) === 1) {
            $value = (int) $value;
        }

        return \is_int($value) && $value > 0 ? min($value, $max) : $default;
    }
}
