<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Report;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceStatus;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\AggregatesPeople;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;
use Shondalai\VidyaLMS\Core\Registry\Report\OrgScopedReportInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportResult;
use Shondalai\VidyaLMS\Core\Registry\Report\RowPerPerson;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;

/**
 * Every active member of one organisation, and which of them are doing nothing.
 *
 * **It is built on membership and not on enrolment, and that is the whole
 * point.** Thinkific documents the opposite as a live support issue: only
 * students with enrolments appear, so people assigned to a group and enrolled in
 * nothing are invisible in group reports. Those are precisely the people an
 * organisation administrator has to chase, so a roster that omitted them would
 * answer the easy question and hide the useful one. `enrolment_count` is
 * therefore a left join and `without_enrolment` is a first-class filter rather
 * than something a reader derives by eye.
 *
 * It is also the free build's second tabular report, which is what gives the
 * free CSV writer, the export pipeline, the field disclosure stage and the
 * cell suppression policy a row-per-person artefact to be exercised on. The
 * access log is the other, and one of the two is about administrators rather
 * than learners.
 *
 * **It declares {@see RowPerPerson}.** Every row here is one identifiable
 * person, so the second delivery refusal applies to it: this report may not
 * leave the product as an email attachment across an organisation boundary.
 *
 * **The email column exists and the free build never discloses it.** That is not
 * dead weight and it is not a placeholder. The design's `org_minimal` rule is
 * that an organisation-scoped principal never sees an address on a domain the
 * organisation has not claimed; domain claiming lands with the identity seam, so
 * today no domain is claimed and every address is withheld. Declaring the column
 * and letting the pipeline omit it is what makes that rule a running stage
 * rather than a sentence, and it is what a Business `org_declared` policy widens
 * without this provider being touched.
 */
final class OrgRosterReport implements
    TabularReportInterface,
    FiltersByVisibility,
    HonoursOrgScope,
    OrgScopedReportInterface,
    AggregatesPeople,
    RowPerPerson
{
    public const KEY = 'org-roster';

    /** One page of the keyset walk, and the batch every per-page lookup uses. */
    private const PAGE = 500;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly UsersPort $users,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.report.org_roster');
    }

    /** The same answer `org-access-log` gives, for the reason its own docblock states. */
    public function requiredCapability(): string
    {
        return Capability::VIEW_REPORTS;
    }

    public function parameterSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://vidyalms.org/schema/report/org-roster/1',
            'type' => 'object',
            'additionalProperties' => true,
            'properties' => [
                'include_descendants' => ['type' => 'boolean', 'default' => true],
                'state' => [
                    'enum' => ['all', 'invited', 'active', 'suspended', 'left'],
                    'default' => 'active',
                ],
                'without_enrolment_only' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Only members with no enrolment through this organisation.',
                ],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 10000],
            ],
        ];
    }

    public function columns(ReportContext $context): array
    {
        return [
            $this->column('user_id', ColumnDefinition::TYPE_INTEGER),
            $this->column('display_name'),
            // Withheld by the free org_minimal policy in the pipeline stage,
            // never by this provider deciding for itself.
            $this->column('email'),
            $this->column('unit_path'),
            $this->column('role_keys'),
            $this->column('state'),
            $this->column('joined_at', ColumnDefinition::TYPE_DATETIME),
            $this->column('provisioned_by'),
            $this->column('directory_managed', ColumnDefinition::TYPE_BOOLEAN),
            ColumnDefinition::count(
                'enrolment_count',
                $this->translator->translate('vidyalms.org_roster.column.enrolment_count'),
            ),
            $this->column('seat_state'),
            $this->column('compliance_summary'),
        ];
    }

    private function column(string $key, string $type = ColumnDefinition::TYPE_STRING): ColumnDefinition
    {
        return ColumnDefinition::of(
            $key,
            $this->translator->translate('vidyalms.org_roster.column.' . $key),
            $type,
        );
    }

    public function generate(ReportContext $context): array
    {
        return $this->describe($context)->facts;
    }

    /**
     * Both scalars, because both are counts of people.
     *
     * Declaring it is what puts them through the same small-cell policy the
     * overview obeys. Without it the roster published, in the clear and beside
     * the overview, the head-count the overview had just suppressed: a
     * two-member organisation read active_members as null with two suppressed
     * cells on one report and total = 2 on the other, over the same scope,
     * under the same right, on the same screen.
     *
     * @return list<string>
     */
    public function countFactKeys(ReportContext $context): array
    {
        return ['total', 'members_without_enrolment'];
    }

    public function describe(ReportContext $context): ReportResult
    {
        $scope = $this->scope($context);
        $total = 0;
        $withoutEnrolment = 0;

        foreach ($this->rows($context) as $row) {
            $total++;

            if ((int) $row['enrolment_count'] === 0) {
                $withoutEnrolment++;
            }
        }

        return ReportResult::of([
            'organisation_id' => $scope->organisationId,
            'unit_id' => $scope->unitId,
            'total' => $total,
            // The gap, named as its own number rather than left for a reader to
            // count. It is the answer the report exists to give.
            'members_without_enrolment' => $withoutEnrolment,
        ], [], $total);
    }

    public function rows(ReportContext $context): iterable
    {
        $scope = $this->scope($context);
        $state = $context->string('state') ?? 'active';
        $withoutOnly = ($context->parameters['without_enrolment_only'] ?? false) === true;
        $limit = max(1, $context->int('limit') ?? 10000);
        $cursor = 0;
        $yielded = 0;

        while (true) {
            $page = $this->membershipPage($scope, $state, $cursor);

            if ($page === []) {
                return;
            }

            $membershipIds = [];
            $userIds = [];

            foreach ($page as $member) {
                $cursor = max($cursor, (int) $member['id']);
                $membershipIds[] = (int) $member['id'];
                $userIds[] = (int) $member['user_id'];
            }

            $roles = $this->rolesFor($scope, $membershipIds);
            $units = $this->unitPathsFor($scope, $page);
            $enrolments = $this->enrolmentCountsFor($scope, $userIds);
            $seats = $this->seatStatesFor($scope, $membershipIds);
            $compliance = $this->complianceFor($scope, $userIds);

            foreach ($page as $member) {
                $membershipId = (int) $member['id'];
                $userId = (int) $member['user_id'];
                $count = $enrolments[$userId] ?? 0;

                if ($withoutOnly && $count > 0) {
                    continue;
                }

                $yielded++;

                if ($yielded > $limit) {
                    return;
                }

                $account = $userId > 0 ? $this->users->findById($userId) : null;

                yield [
                    'user_id' => $userId,
                    'display_name' => $account->name ?? '',
                    'email' => $account->email ?? '',
                    'unit_path' => $units[(int) ($member['org_unit_id'] ?? 0)] ?? '',
                    'role_keys' => implode(', ', $roles[$membershipId] ?? []),
                    'state' => (string) ($member['state'] ?? ''),
                    'joined_at' => $member['joined_at'] ?? null,
                    'provisioned_by' => (string) ($member['provisioned_by'] ?? 'manual'),
                    'directory_managed' => (int) ($member['directory_managed'] ?? 0) === 1,
                    'enrolment_count' => $count,
                    'seat_state' => $seats[$membershipId] ?? '',
                    'compliance_summary' => $compliance[$userId] ?? $this->untracked(),
                ];
            }

            if (\count($page) < self::PAGE) {
                return;
            }
        }
    }

    /**
     * One keyset page of memberships, started at the scoped reader so the
     * tenancy predicate is injected rather than typed.
     *
     * @return list<array<string, mixed>>
     */
    private function membershipPage(OrgScope $scope, string $state, int $after): array
    {
        $query = OrgScopedReader::from($scope, 'org_memberships', 'm')
            ->select(['m.id', 'm.user_id', 'm.org_unit_id', 'm.state', 'm.joined_at', 'm.provisioned_by',
                'm.directory_managed'])
            ->where('m.id', '>', $after)
            ->orderBy('m.id')
            ->limit(self::PAGE);

        if ($state !== 'all') {
            $query->where('m.state', '=', $state);
        }

        $scope->constrainUnit($query, 'm.org_unit_id');

        return $this->database->selectAll($query);
    }

    /**
     * @param list<int> $membershipIds
     *
     * @return array<int, list<string>>
     */
    private function rolesFor(OrgScope $scope, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $out = [];

        foreach (
            $this->database->selectAll(
                OrgScopedReader::from($scope, 'org_role_assignments', 'r')
                    ->select(['r.membership_id', 'r.role_key'])
                    ->whereIn('r.membership_id', $membershipIds)
                    ->orderBy('r.role_key'),
            ) as $row
        ) {
            $out[(int) $row['membership_id']][] = (string) $row['role_key'];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $page
     *
     * @return array<int, string>
     */
    private function unitPathsFor(OrgScope $scope, array $page): array
    {
        $ids = [];

        foreach ($page as $member) {
            $unitId = (int) ($member['org_unit_id'] ?? 0);

            if ($unitId > 0) {
                $ids[$unitId] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (
            $this->database->selectAll(
                OrgScopedReader::from($scope, 'org_units', 'u')
                    ->select(['u.id', 'u.path'])
                    ->whereIn('u.id', array_keys($ids)),
            ) as $row
        ) {
            $out[(int) $row['id']] = (string) ($row['path'] ?? '');
        }

        return $out;
    }

    /**
     * How many enrolments each person reaches **through this organisation**.
     *
     * The row grain is the entitlement and never the enrolment, which is the
     * isolation rule stated once and applied here: a learner who bought the same
     * course themselves is not this organisation's learner on it, and counting
     * the enrolment row alone would report somebody as active on learning this
     * organisation never paid for.
     *
     * @param list<int> $userIds
     *
     * @return array<int, int>
     */
    private function enrolmentCountsFor(OrgScope $scope, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // entitlements is on the scoped reader's own named exclusion list, so
        // the predicate is written here rather than injected. It is written all
        // the same, because the bound is the rule and not the mechanism.
        $query = Query::from('entitlements', 'e')
            ->select(['e.user_id'])
            ->aggregate(Aggregate::countDistinctOf('n.id', 'enrolments'))
            ->join('enrolments', 'n', 'e.user_id', 'n.user_id', [['e.course_id', 'n.course_id']])
            ->where('e.organisation_id', '=', $scope->organisationId)
            ->whereIn('e.user_id', $userIds)
            ->where('e.status', '=', 'active')
            ->groupBy('e.user_id');

        $out = [];

        foreach ($this->database->selectAll($query) as $row) {
            $out[(int) $row['user_id']] = (int) ($row['enrolments'] ?? 0);
        }

        return $out;
    }

    /**
     * @param list<int> $membershipIds
     *
     * @return array<int, string>
     */
    private function seatStatesFor(OrgScope $scope, array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $out = [];

        foreach (
            $this->database->selectAll(
                OrgScopedReader::from($scope, 'seat_assignments', 'a')
                    ->select(['a.membership_id', 'a.state'])
                    ->whereIn('a.membership_id', $membershipIds)
                    ->orderBy('a.id'),
            ) as $row
        ) {
            $out[(int) $row['membership_id']] = (string) $row['state'];
        }

        return $out;
    }

    /**
     * The compliance line beside each person.
     *
     * **The free build always answers "assigned, untracked", and that is the
     * registry's published miss direction rather than a gap.** The compliance
     * tables ship free and no rule is registered into `ComplianceRule`, so no
     * assignment has a resolved status; reporting anybody compliant because
     * nothing was installed to say otherwise is the one wrong answer this seam
     * must never give.
     *
     * @param list<int> $userIds
     *
     * @return array<int, string>
     */
    private function complianceFor(OrgScope $scope, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $out = [];

        foreach (
            $this->database->selectAll(
                OrgScopedReader::from($scope, 'compliance_assignments', 'c')
                    ->select(['c.user_id', 'c.compliance_status'])
                    ->whereIn('c.user_id', $userIds)
                    ->orderBy('c.id'),
            ) as $row
        ) {
            $userId = (int) $row['user_id'];
            $status = ComplianceStatus::of(\is_string($row['compliance_status']) ? $row['compliance_status'] : null);
            $out[$userId] = isset($out[$userId]) ? $out[$userId] . ', ' . $status : $status;
        }

        return $out;
    }

    private function untracked(): string
    {
        return ComplianceStatus::ASSIGNED;
    }

    private function scope(ReportContext $context): OrgScope
    {
        return $context->orgScope ?? throw ValidationException::forField(
            'organisation_id',
            'This report reads one organisation and is only reachable through an organisation route.',
        );
    }
}
