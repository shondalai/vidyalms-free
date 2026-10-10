<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Reporting;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportParameters;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportSchedule;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Report\ReportScheduleStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\DeclaresAvailability;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportFormatRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\OrgScopedReportInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportDefinitionInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\ReportDeliveryRegistry;

/**
 * Authoring a saved report schedule, and running one on demand.
 *
 * **Everything a schedule names is validated at save time and not at 03:00.**
 * The report key, the export format, the delivery provider and the audience are
 * all registry keys, and a schedule naming one nobody registered is a schedule
 * that will fail silently in the middle of the night on a site whose
 * administrator is asleep. That is the whole reason `parameterSchema()` exists
 * and it is the reason this class holds four registries.
 *
 * **`render_as` defaults to the recipient and a caller may not omit its way
 * into the author.** The default is in the domain factory, so a request body
 * that forgets the field lands on the safe value rather than the fast one.
 */
final class ManageReportSchedules
{
    public function __construct(
        private readonly ReportScheduleStore $store,
        private readonly ReportRegistry $reports,
        private readonly ExportFormatRegistry $formats,
        private readonly ReportDeliveryRegistry $deliveries,
        private readonly AudienceResolverRegistry $audiences,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(
        Principal $principal,
        ?string $reportKey = null,
        ?string $state = null,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $this->guard->require($principal, Capability::MANAGE);

        $all = [];

        foreach ($this->store->all() as $schedule) {
            if ($reportKey !== null && $reportKey !== '' && $schedule->reportKey !== $reportKey) {
                continue;
            }

            if ($state !== null && $state !== '' && $schedule->state !== $state) {
                continue;
            }

            // A site schedule surface never lists an organisation's own
            // schedules: those are read through the organisation route, under
            // an authorisation that resolved that organisation.
            if ($schedule->organisationId === null) {
                $all[] = $schedule;
            }
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        return [
            'items' => array_map(
                fn (ReportSchedule $schedule): array => self::render($schedule),
                \array_slice($all, ($page - 1) * $perPage, $perPage),
            ),
            'total' => \count($all),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function create(Principal $principal, array $body): array
    {
        $this->guard->require($principal, Capability::MANAGE);
        self::refuseTenantAnchor($body);

        $schedule = $this->scheduleFrom($body, $principal);
        $saved = $this->store->save($this->withNextRun($schedule));

        return self::render($saved);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function update(Principal $principal, int $id, array $body): array
    {
        $this->guard->require($principal, Capability::MANAGE);

        self::refuseTenantAnchor($body);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $existing = $this->store->find($id) ?? throw EntityNotFoundException::for('ReportSchedule', $id);
        $merged = $this->scheduleFrom($body + self::asBody($existing), $principal);
        $saved = $this->store->save($this->withNextRun($merged, $id, $existing));

        return self::render($saved);
    }

    public function delete(Principal $principal, int $id): void
    {
        $this->guard->require($principal, Capability::MANAGE);

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $this->store->find($id) ?? throw EntityNotFoundException::for('ReportSchedule', $id);
        $this->store->delete($id);
    }

    /**
     * Runs one schedule now, without moving its recurrence.
     *
     * The boundary is deliberately untouched: an administrator pressing Run is
     * asking for a copy of the report, not for the nightly one to be skipped.
     *
     * @return array<string, mixed>
     */
    public function runNow(Principal $principal, int $id, \Closure $runner): array
    {
        $this->guard->require($principal, Capability::MANAGE);

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $schedule = $this->store->find($id) ?? throw EntityNotFoundException::for('ReportSchedule', $id);

        return [
            'schedule_id' => $id,
            'render_as' => $schedule->renderAs,
            'runs' => $runner($schedule),
        ];
    }

    /**
     * The tenancy anchor is a path segment on the organisation surface and
     * never a field here.
     *
     * Refused rather than ignored, for two reasons. A schedule cut to a tenant
     * must be authored under an authorisation that resolved that tenant, and
     * this surface resolved none; and `list()` deliberately hides
     * organisation-scoped rows, so an accepted anchor would write a schedule
     * that runs every night and appears on no screen its author can reach.
     *
     * @param array<string, mixed> $body
     */
    private static function refuseTenantAnchor(array $body): void
    {
        if (\array_key_exists('organisation_id', $body) && $body['organisation_id'] !== null) {
            throw ValidationException::forField('organisation_id', 'An organisation-scoped schedule is authored '
                . 'on that organisation, where the scope is resolved from the path. Accepting the tenant as a '
                . 'field here would write a schedule under an authorisation that never saw it.');
        }
    }

    /**
     * The saved filter set, validated against the report's own published schema.
     *
     * **At save time and not at 03:00**, which is the whole reason a report
     * publishes `parameterSchema()`: a schedule whose filter the report has
     * never heard of produces the wrong file every night until somebody opens
     * it, and the author is standing here now.
     *
     * The contract's name for the member is `parameters`, which is what both
     * schedule surfaces accept; `definition` is the column it lands in and is
     * read as a fallback so nothing written against the stored name breaks.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function parametersFor(object $report, array $body): array
    {
        $raw = $body['parameters'] ?? $body['definition'] ?? [];
        /** @var array<string, mixed> $parameters */
        $parameters = \is_array($raw) ? $raw : [];

        // A provider predating the definition capability publishes no schema,
        // so there is nothing to validate against and the bag is stored as it
        // arrived. That is the legacy path the capability was added over: a
        // report with no schema declares no filters either.
        return $report instanceof ReportDefinitionInterface
            ? ReportParameters::validate($report->parameterSchema(), $parameters)
            : $parameters;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function scheduleFrom(array $body, Principal $principal): ReportSchedule
    {
        $reportKey = self::stringOf($body, 'report_key');

        if (!$this->reports->has($reportKey)) {
            throw ValidationException::forField('report_key', sprintf(
                'No report is registered under "%s" on this site.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $reportKey,
            ));
        }

        $report = $this->reports->get($reportKey);

        // The same two refusals both site entry points make, applied here so a
        // schedule cannot become the way past them. An organisation-scoped
        // report is refused because this surface resolves no tenant to compute
        // it inside, and a report that does not honour a visibility scope is
        // refused because rendering per recipient hands one to it.
        if ($report instanceof OrgScopedReportInterface) {
            throw ValidationException::forField('report_key', sprintf(
                'The "%s" report is organisation-scoped, and a site schedule resolves no organisation to compute '
                . 'it inside. It is scheduled on the organisation it belongs to.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $reportKey,
            ));
        }

        if (!$report instanceof FiltersByVisibility) {
            throw ValidationException::forField('report_key', sprintf(
                'The "%s" report was not written to honour a visibility scope, so it cannot be rendered per '
                . 'recipient. The catalogue publishes this as honours_visibility.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $reportKey,
            ));
        }

        $formatKey = self::stringOf($body, 'format_key', 'csv');

        if (!$this->formats->has($formatKey)) {
            throw ValidationException::forField('format_key', sprintf(
                'This site can write %s. It cannot write "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', $this->formats->keys()),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $formatKey,
            ));
        }

        // A format the registry answers for but the host cannot actually write
        // is refused at save time and not at 03:00. The picker publishes the
        // same flag, so a schedule can only be saved against a format the
        // screen was allowed to offer. The probe is a capability rather than a
        // method on the shipped interface: a format with no runtime to be
        // missing does not declare it and is always available.
        $format = $this->formats->get($formatKey);

        if ($format instanceof DeclaresAvailability && !$format->isAvailable()) {
            throw ValidationException::forField('format_key', sprintf(
                'This site cannot write the "%s" format: %s. A schedule naming it would fail every night.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $formatKey,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $format->unavailableReason() ?? 'no runtime for it is installed',
            ));
        }

        $deliveryKey = self::stringOf($body, 'delivery_key', 'notification-link');

        if (!$this->deliveries->has($deliveryKey)) {
            throw ValidationException::forField('delivery_key', sprintf(
                'No delivery provider is registered under "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $deliveryKey,
            ));
        }

        $audienceKey = self::stringOf($body, 'audience_key');

        if (!$this->audiences->has($audienceKey)) {
            // The audience registry's own miss direction is to resolve empty
            // and never to broadcast, and this is where a saved schedule is
            // stopped from being written against a key that would.
            throw ValidationException::forField('audience_key', sprintf(
                'No audience is registered under "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $audienceKey,
            ));
        }

        return ReportSchedule::create(
            self::stringOf($body, 'title'),
            $reportKey,
            $audienceKey,
            $this->momentOf($body, 'starts_at'),
            $principal->userId,
            $formatKey,
            $deliveryKey,
            $this->parametersFor($this->reports->get($reportKey), $body),
            \is_array($body['audience_config'] ?? null) ? $body['audience_config'] : [],
            self::stringOf($body, 'recurrence', ReportSchedule::RECURRENCE_DAILY),
            (int) ($body['recurrence_day'] ?? 1),
            (int) ($body['run_at_minute'] ?? 0),
            self::stringOf($body, 'timezone', 'UTC'),
            self::stringOf($body, 'render_as', ReportSchedule::RENDER_AS_RECIPIENT),
            self::stringOf($body, 'empty_policy', ReportSchedule::EMPTY_SEND),
            \is_string($body['subject'] ?? null) ? (string) $body['subject'] : null,
            \is_string($body['body'] ?? null) ? (string) $body['body'] : null,
            isset($body['organisation_id']) && is_numeric($body['organisation_id'])
                ? (int) $body['organisation_id']
                : null,
            self::stringOf($body, 'state', ReportSchedule::STATE_DISABLED),
        );
    }

    /**
     * The saved schedule with its first boundary computed, so an enabled
     * schedule is due at a moment somebody can read rather than at the next
     * tick.
     */
    private function withNextRun(
        ReportSchedule $schedule,
        ?int $id = null,
        ?ReportSchedule $existing = null,
    ): ReportSchedule {
        return new ReportSchedule(
            $id,
            $schedule->title,
            $schedule->reportKey,
            $schedule->formatKey,
            $schedule->deliveryKey,
            $schedule->definition,
            $schedule->definitionVersion,
            $schedule->audienceKey,
            $schedule->audienceConfig,
            $schedule->audienceConfigVersion,
            $schedule->recurrence,
            $schedule->recurrenceDay,
            $schedule->runAtMinute,
            $schedule->timezone,
            $schedule->startsAt,
            $schedule->nextDueAfter($this->clock->now()),
            $existing?->lastRunAt,
            $schedule->renderAs,
            $schedule->emptyPolicy,
            $schedule->subject,
            $schedule->body,
            $schedule->organisationId,
            $schedule->state,
            $existing === null ? $schedule->createdBy : $existing->createdBy,
            $existing === null ? 0 : $existing->runCount,
            $existing?->lastError,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function asBody(ReportSchedule $schedule): array
    {
        return [
            'title' => $schedule->title,
            'report_key' => $schedule->reportKey,
            'format_key' => $schedule->formatKey,
            'delivery_key' => $schedule->deliveryKey,
            'definition' => $schedule->definition,
            'audience_key' => $schedule->audienceKey,
            'audience_config' => $schedule->audienceConfig,
            'recurrence' => $schedule->recurrence,
            'recurrence_day' => $schedule->recurrenceDay,
            'run_at_minute' => $schedule->runAtMinute,
            'timezone' => $schedule->timezone,
            'starts_at' => $schedule->startsAt->format(DATE_ATOM),
            'render_as' => $schedule->renderAs,
            'empty_policy' => $schedule->emptyPolicy,
            'subject' => $schedule->subject,
            'body' => $schedule->body,
            'organisation_id' => $schedule->organisationId,
            'state' => $schedule->state,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function render(ReportSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'title' => $schedule->title,
            'report_key' => $schedule->reportKey,
            'format_key' => $schedule->formatKey,
            'delivery_key' => $schedule->deliveryKey,
            'definition' => $schedule->definition,
            'definition_version' => $schedule->definitionVersion,
            'audience_key' => $schedule->audienceKey,
            'audience_config' => $schedule->audienceConfig,
            'audience_config_version' => $schedule->audienceConfigVersion,
            'recurrence' => $schedule->recurrence,
            'recurrence_day' => $schedule->recurrenceDay,
            'run_at_minute' => $schedule->runAtMinute,
            'timezone' => $schedule->timezone,
            'starts_at' => $schedule->startsAt->format(DATE_ATOM),
            'next_run_at' => $schedule->nextRunAt?->format(DATE_ATOM),
            'last_run_at' => $schedule->lastRunAt?->format(DATE_ATOM),
            'render_as' => $schedule->renderAs,
            'empty_policy' => $schedule->emptyPolicy,
            'subject' => $schedule->subject,
            'body' => $schedule->body,
            'organisation_id' => $schedule->organisationId,
            'state' => $schedule->state,
            'created_by' => $schedule->createdBy,
            'run_count' => $schedule->runCount,
            'last_error' => $schedule->lastError,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function stringOf(array $body, string $key, string $default = ''): string
    {
        $value = $body[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function momentOf(array $body, string $key): \DateTimeImmutable
    {
        $value = $body[$key] ?? null;

        if (!\is_string($value) || $value === '') {
            return $this->clock->now();
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, 'That is not a moment this build can read.');
        }
    }
}
