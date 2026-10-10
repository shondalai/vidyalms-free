<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Report;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessLogQuery;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessLogStore;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;
use Shondalai\VidyaLMS\Core\Registry\Report\OrgScopedReportInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportResult;
use Shondalai\VidyaLMS\Core\Registry\Report\RowPerPerson;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;

/**
 * Who read what about one organisation, and whether the record can be trusted.
 *
 * It is the first organisation-scoped report and it is here rather than in the
 * governance wave on purpose: it exercises every seam this one builds at once.
 * It is tabular, so it drives the export writers. It declares
 * {@see HonoursOrgScope}, so it is the first provider that marker has ever been
 * probed on. It is {@see OrgScopedReportInterface}, so it is what proves the
 * site-wide report route answers the registry-miss shape for an organisation-only
 * key rather than 404 or 403. Its rows are read through the store's anchored
 * keyset walk, so the tenancy predicate is injected rather than typed. Its
 * counts go through the suppression policy. And it is the first production
 * caller {@see AdminAccessLogStore::verify()} has ever had.
 *
 * **The chain result is a report fact and not a footnote.** An audit export
 * whose reader cannot tell whether the log was intact when it was taken is an
 * export of unknown provenance, and the verification is cheap next to the
 * export it accompanies. `chain_anchor` is carried beside it because a chain
 * that verifies under an `unkeyed` anchor is evidence rather than proof, and an
 * auditor is entitled to know which of the two they are holding.
 *
 * **The artefact is keyed on the digest and the actor.** `OrgScope::digest()`
 * omits the actor deliberately, so two administrators of one organisation
 * share a digest; this report's rows do not depend on which of them is asking,
 * but the run record still keys on both, because the rule the digest's own
 * docblock states is that a per-actor artefact keys on the pair and the safe
 * direction for a table of who-read-what is to assume it is per actor.
 */
final class OrgAccessLogReport implements
    TabularReportInterface,
    FiltersByVisibility,
    HonoursOrgScope,
    OrgScopedReportInterface,
    // Every row names the person who made the read, so the marker the
    // cross-organisation attachment refusal reads is declared rather than left
    // to be inferred from the column list by a rule that returns early when it
    // is absent.
    RowPerPerson
{
    public const KEY = 'org-access-log';

    /** One page of the keyset walk over an append-only table under live traffic. */
    private const PAGE = 500;

    public function __construct(
        private readonly AdminAccessLogStore $log,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.report.org_access_log');
    }

    public function requiredCapability(): string
    {
        return Capability::VIEW_REPORTS;
    }

    /**
     * The filters, and nothing that could widen the anchor.
     *
     * The organisation is not among them and never will be: it comes from the
     * path and from the scope the authorisation produced. A run-time parameter
     * that could name the tenant is a run-time parameter that can widen a run
     * past the tenant its caller was authorised for, which is the whole of the
     * condition-versus-filter rule applied to the one table where getting it
     * wrong hands one tenant another tenant's audit trail.
     */
    public function parameterSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://vidyalms.org/schema/report/org-access-log/1',
            'type' => 'object',
            'additionalProperties' => true,
            'properties' => [
                'subject_user_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'One person inside this organisation, never a person outside it.',
                ],
                'course_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Reads about one course, matched on the log\'s own "course:{id}" resource grammar.',
                ],
                'from' => ['type' => 'string', 'format' => 'date-time'],
                'to' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'outcome' => ['enum' => ['all', 'allowed', 'refused'], 'default' => 'all'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 10000],
            ],
        ];
    }

    public function columns(ReportContext $context): array
    {
        return [
            $this->column('id', ColumnDefinition::TYPE_INTEGER),
            $this->column('occurred_at', ColumnDefinition::TYPE_DATETIME),
            $this->column('event_type'),
            $this->column('actor_id', ColumnDefinition::TYPE_INTEGER),
            $this->column('actor_role_key'),
            $this->column('subject_user_id', ColumnDefinition::TYPE_INTEGER),
            $this->column('resource'),
            $this->column('route'),
            $this->column('outcome'),
            // The one count on the row, so it is the one cell small-cell
            // suppression acts on. How many records a read returned is how
            // many people it was about.
            ColumnDefinition::count(
                'record_count',
                $this->translator->translate('vidyalms.org_access_log.column.record_count'),
            ),
        ];
    }

    /** The label key is derived from the column key, so the two cannot drift. */
    private function column(string $key, string $type = ColumnDefinition::TYPE_STRING): ColumnDefinition
    {
        return ColumnDefinition::of(
            $key,
            $this->translator->translate('vidyalms.org_access_log.column.' . $key),
            $type,
        );
    }

    public function generate(ReportContext $context): array
    {
        return $this->describe($context)->facts;
    }

    public function describe(ReportContext $context): ReportResult
    {
        $anchor = $this->anchor($context);
        $total = $this->log->countFor($anchor);
        // A tenant may read its own rows, not enumerate the site's activity
        // through chain lengths or force a complete scan of every tenant.
        if ($this->scope($context)->basis !== \Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope::BASIS_SITE_CAPABILITY) {
            return ReportResult::of([
                'organisation_id' => $this->scope($context)->organisationId,
                'total' => $total,
                'chain_verified' => false,
                'chain_complete' => false,
                'chain_anchor' => 'site_administrator_only',
            ], [], $total);
        }
        $chain = $this->log->verify(1000, 10000);

        return ReportResult::of([
            'organisation_id' => $this->scope($context)->organisationId,
            'total' => $total,
            // The chain covers the whole table and not this organisation's
            // slice, because the chain is site-wide by design: a row may have
            // no owning organisation at all, so a per-organisation chain would
            // leave those rows unchained.
            'chain_verified' => $chain['breaks'] === [] && $chain['complete'],
            'chain_breaks' => \count($chain['breaks']),
            'chain_rows_checked' => $chain['checked'],
            'chain_complete' => $chain['complete'],
            'chain_pruned' => $chain['pruned'],
            'chain_anchor' => $chain['anchor'],
        ], $chain['breaks'] === [] ? [] : ['admin_access_log.chain_broken'], $total);
    }

    public function rows(ReportContext $context): iterable
    {
        $limit = $context->int('limit') ?? 10000;
        $yielded = 0;

        // The filters are applied here rather than in the anchored query, and
        // that is deliberate rather than lazy: the anchor is the tenancy
        // predicate and the store owns it, so a filter that reached into the
        // same statement would be a second place a predicate can be forgotten.
        // The walk is keyset paged either way, so a filtered run reads no more
        // rows than an unfiltered one of the same length.
        foreach ($this->log->walk($this->anchor($context), self::PAGE) as $row) {
            if (!self::matches($row, $context)) {
                continue;
            }

            $yielded++;

            if ($yielded > max(1, $limit)) {
                return;
            }

            yield [
                'id' => (int) ($row['id'] ?? 0),
                'occurred_at' => $row['occurred_at'] ?? null,
                'event_type' => (string) ($row['event_type'] ?? ''),
                'actor_id' => (int) ($row['actor_id'] ?? 0),
                'actor_role_key' => $row['actor_role_key'] ?? null,
                'subject_user_id' => $row['subject_user_id'] ?? null,
                'resource' => $row['resource'] ?? null,
                'route' => (string) ($row['route'] ?? ''),
                'outcome' => (string) ($row['outcome'] ?? ''),
                'record_count' => (int) ($row['record_count'] ?? 0),
            ];
        }
    }

    /**
     * Whether one row survives the run-time filters.
     *
     * A course is matched on the log's own `course:{id}` resource grammar
     * rather than on a column of its own, because the log records a resource
     * string and inventing a second representation of the same fact is how the
     * two disagree.
     *
     * @param array<string, mixed> $row
     */
    private static function matches(array $row, ReportContext $context): bool
    {
        $outcome = $context->string('outcome') ?? 'all';

        if ($outcome !== 'all' && (string) ($row['outcome'] ?? '') !== $outcome) {
            return false;
        }

        $courseId = $context->int('course_id');

        if ($courseId !== null && $courseId > 0 && (string) ($row['resource'] ?? '') !== 'course:' . $courseId) {
            return false;
        }

        $occurred = (string) ($row['occurred_at'] ?? '');
        $from = $context->string('from');
        $to = $context->string('to');

        if ($from !== null && $occurred < self::instant($from)) {
            return false;
        }

        return $to === null || $occurred <= self::instant($to);
    }

    /**
     * An RFC 3339 boundary as the column stores it.
     *
     * The column is a UTC "Y-m-d H:i:s" string, so a caller's ISO instant is
     * normalised to that shape before it is compared. Comparing the two
     * spellings directly would silently exclude every row on the day a caller
     * passed an offset.
     */
    private static function instant(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            throw ValidationException::forField('from', 'A date boundary must be a readable instant.');
        }
    }

    /**
     * The anchor this run reads under.
     *
     * It is built from the scope the authorisation produced and never from a
     * parameter. A `subject_user_id` filter narrows inside that anchor and can
     * never replace it, which is the condition-versus-filter rule applied to
     * the one table where getting it wrong hands one tenant another tenant's
     * audit trail.
     */
    private function anchor(ReportContext $context): AdminAccessLogQuery
    {
        $scope = $this->scope($context);
        $subject = $context->int('subject_user_id');

        return $subject === null || $subject < 1
            ? AdminAccessLogQuery::forOrganisation($scope)
            : AdminAccessLogQuery::forSubject($scope, $subject);
    }

    private function scope(ReportContext $context): \Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope
    {
        return $context->orgScope ?? throw ValidationException::forField(
            'organisation_id',
            'This report reads one organisation and is only reachable through an organisation route.',
        );
    }
}
