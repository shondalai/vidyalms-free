<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;

/**
 * R11a's two free organisation gaps, driven against a real site and a real
 * database.
 *
 * One body of assertions and two callers, the way every organisation contract
 * is run: each free adapter's build/smoke.php runs it against its own ports.
 * The core's own suite already proves the release bound, the source registry
 * and the assignment exceptions against an in-memory SQLite harness, so every
 * section here is chosen because SQLite would have said yes and a real driver
 * might not:
 *
 *   - **A course reaches an organisation through a catalogue entry.**
 *     `org_catalogue_entries` is the table this wave adds, and its `params`
 *     column is JSON that one host's driver hands back as a string and the
 *     other's does not. The entry is written through the route, read back
 *     through the narrowed catalogue read, and the course the site owner
 *     released is the course the organisation sees.
 *   - **It is read back organisation-scoped.** A second organisation exists on
 *     the same site for the length of this run, and its catalogue is empty and
 *     its read of the first organisation's entry is a 404 rather than a 403.
 *     That predicate is a compiled JOIN on both hosts and it is the one thing
 *     no unit test on one driver can settle.
 *   - **A course nobody released is refused.** The bound is the whole design of
 *     the table: nothing an organisation administrator writes may widen the set
 *     a site owner released.
 *   - **A compliance requirement is authored and assigned, with its exception.**
 *     Until this wave nothing in the product wrote `compliance_requirements`,
 *     so `ComplianceAssignments::assign` had no caller on any site and the
 *     window sweep reported checked=0 everywhere. One bulk assignment over a
 *     member who holds the entitlement and a person who is not in the
 *     organisation must answer one assignment and one named exception, and
 *     never report the second person compliant.
 *   - **A requirement with no course is refused at save.** On the default
 *     enrolment anchor there is nothing to be enrolled in, so a requirement
 *     stored that way would raise anchor_unresolved on every assignment for
 *     ever. It is refused once instead, by name.
 *
 * **Everything it writes goes inside a transaction it rolls back**, and a check
 * afterwards proves the rollback rather than assuming it. It creates no table
 * and drops none, and it never names a Community Quiz table.
 */
final class OrganisationCatalogueContract
{
    /** Everything this contract creates carries it, so the cleanup can be exact. */
    public const MARKER = 'vidyalms-catalogue-contract';

    /** @var list<string> */
    private array $facts = [];

    /** @var list<string> */
    private array $failures = [];

    private int $organisationId = 0;

    /** The second organisation, which exists only so the tenancy has a far side. */
    private int $otherOrganisationId = 0;

    private int $entryId = 0;

    private int $requirementId = 0;

    /** The person in the organisation who holds the entitlement. */
    private int $memberId = 0;

    /** The person who is on the site and not in the organisation. */
    private int $strangerId = 0;

    private function __construct(
        private readonly CoreKernel $kernel,
        private readonly Ports $ports,
        private readonly Principal $actor,
        private readonly int $courseId,
        private readonly int $unreleasedCourseId,
    ) {
    }

    /**
     * @param array<string, int> $accounts the seat contract's fixture people,
     *                                     of which this needs two
     *
     * @return array{failures: list<string>, facts: list<string>}
     */
    public static function run(
        CoreKernel $kernel,
        Ports $ports,
        Principal $actor,
        int $courseId,
        array $accounts,
        int $unreleasedCourseId = 0,
    ): array {
        $contract = new self($kernel, $ports, $actor, $courseId, $unreleasedCourseId);
        $people = array_values(array_map('intval', $accounts));
        $contract->memberId = $people[2] ?? ($people[0] ?? 0);
        $contract->strangerId = $people[3] ?? ($people[1] ?? 0);

        if ($contract->memberId === 0 || $contract->strangerId === 0 || $contract->memberId === $contract->strangerId) {
            return [
                'failures' => ['the contract needs two distinct fixture accounts and was given ' . self::describe($accounts)],
                'facts' => [],
            ];
        }

        try {
            $ports->database->transactional(static function () use ($contract): void {
                $contract->seed();
                $contract->checkACourseReachesTheOrganisationThroughACatalogueEntry();
                $contract->checkTheEntryIsReadBackOrganisationScoped();
                $contract->checkACourseNobodyReleasedIsRefused();
                $contract->checkARequirementIsAuthoredAndAssignedWithItsException();
                $contract->checkACourselessRequirementOnTheDefaultAnchorIsRefused();

                throw new RollbackSignal();
            });
        } catch (RollbackSignal) {
            // The intended exit: the work is done and the transaction is gone.
        } catch (\Throwable $error) {
            $contract->failures[] = 'threw ' . $error::class . ': ' . $error->getMessage();
        }

        $contract->assertNothingSurvived();

        return ['failures' => $contract->failures, 'facts' => $contract->facts];
    }

    // -----------------------------------------------------------------------
    // The fixture
    // -----------------------------------------------------------------------

    /**
     * Two organisations, one member with an entitlement, and one released
     * course.
     *
     * The second organisation is not decoration. Every read below is bounded by
     * a tenancy predicate the host's own driver compiles, and a predicate can
     * only be shown to bind when there is something on the other side of it.
     */
    private function seed(): void
    {
        $this->organisationId = $this->createOrganisation('R11a catalogue contract');
        $this->otherOrganisationId = $this->createOrganisation('R11a catalogue contract, the other one');

        if ($this->organisationId === 0 || $this->otherOrganisationId === 0) {
            throw new \RuntimeException('the contract could not create its two organisations');
        }

        $this->post(
            '/manage/organisations/' . $this->organisationId . '/members',
            [
                'expected_revision' => $this->revision($this->organisationId),
                'user_id' => $this->memberId,
                'role_key' => 'org_member',
            ],
            'member',
            201,
        );

        $this->post(
            '/manage/organisations/' . $this->organisationId . '/releases',
            [
                'expected_revision' => $this->revision($this->organisationId),
                'course_id' => $this->courseId,
            ],
            'release',
            200,
        );

        // The entitlement the compliance assignment reads. Without it the
        // member is a no_entitlement exception, which is a true answer to a
        // different question than the one this contract asks.
        $this->post(
            '/manage/organisations/' . $this->organisationId . '/learning/grant',
            [
                'expected_revision' => $this->revision($this->organisationId),
                'user_id' => $this->memberId,
                'course_id' => $this->courseId,
            ],
            'grant',
            200,
        );
    }

    // -----------------------------------------------------------------------
    // The contract
    // -----------------------------------------------------------------------

    /**
     * The palette lists the two free sources, an entry is written against one
     * of them, and the narrowed read answers the released course.
     */
    private function checkACourseReachesTheOrganisationThroughACatalogueEntry(): void
    {
        $sources = $this->get('/manage/organisations/' . $this->organisationId . '/catalogue/sources');
        $keys = array_map(
            static fn (array $row): string => (string) ($row['key'] ?? ''),
            \is_array($sources['items'] ?? null) ? $sources['items'] : [],
        );

        $this->expect(
            \in_array('course', $keys, true) && \in_array('unit-subtree', $keys, true),
            'the free build offers the two catalogue sources the design names',
            self::describe($keys),
        );

        $entry = $this->post(
            '/manage/organisations/' . $this->organisationId . '/catalogue',
            [
                'source_key' => 'course',
                'course_id' => $this->courseId,
                'grant_mode' => 'auto_grant',
            ],
            'entry',
            201,
        );
        $this->entryId = (int) ($entry['id'] ?? 0);

        $this->expect(
            ($entry['resolved'] ?? null) === true
            && ($entry['params']['course_id'] ?? null) === $this->courseId
            && \in_array($this->courseId, array_map('intval', (array) ($entry['course_ids'] ?? [])), true),
            'the entry resolves through the source and the stored params survive this host\'s JSON column',
            self::describe($entry),
        );

        $courses = $this->get('/manage/organisations/' . $this->organisationId . '/catalogue/courses');
        $reached = array_map(
            static fn (array $row): int => (int) ($row['course_id'] ?? 0),
            \is_array($courses['items'] ?? null) ? $courses['items'] : [],
        );

        $this->expect(
            $reached === [$this->courseId] && ($courses['unresolved_entries'] ?? []) === [],
            'the narrowed catalogue read answers the released course and reports no unresolved entry',
            self::describe($courses),
        );
    }

    /**
     * The entry belongs to the organisation that wrote it and to nothing else.
     *
     * The far side answers 404 rather than 403, which is the shape the whole
     * organisation surface uses: a 403 on an organisation-scoped route would
     * tell a caller that the id names an organisation somebody else has.
     */
    private function checkTheEntryIsReadBackOrganisationScoped(): void
    {
        $other = $this->get('/manage/organisations/' . $this->otherOrganisationId . '/catalogue');

        $this->expect(
            ($other['items'] ?? null) === [],
            'the second organisation\'s catalogue is empty, so the entry did not leak across the tenancy',
            self::describe($other),
        );

        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            'PATCH',
            '/manage/organisations/' . $this->otherOrganisationId . '/catalogue/' . $this->entryId,
            $this->actor,
            [],
            ['grant_mode' => 'visible'],
            self::MARKER . '-cross-' . bin2hex(random_bytes(6)),
        ));

        $this->expect(
            $response->status === 404,
            'another organisation reads this entry as absent rather than as forbidden',
            'status ' . $response->status . ': ' . self::describe($response->data),
        );

        $stored = $this->ports->database->selectOne(
            Query::from('org_catalogue_entries')->select(['organisation_id', 'grant_mode'])
                ->where('id', '=', $this->entryId),
        );

        $this->expect(
            (int) ($stored['organisation_id'] ?? 0) === $this->organisationId
            && (string) ($stored['grant_mode'] ?? '') === 'auto_grant',
            'the refused cross-tenant write changed nothing on the row it named',
            self::describe($stored),
        );
    }

    /**
     * Nothing an organisation administrator writes may widen the set a site
     * owner released.
     *
     * The refusal is a 409 naming forbidden_scope, and the catalogue is
     * unchanged afterwards, because a refusal that had already written the row
     * would be worse than no refusal at all.
     */
    private function checkACourseNobodyReleasedIsRefused(): void
    {
        if ($this->unreleasedCourseId <= 0 || $this->unreleasedCourseId === $this->courseId) {
            $this->facts[] = 'skipped: this host passed no second course, so the release bound is unproved here';

            return;
        }

        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            'POST',
            '/manage/organisations/' . $this->organisationId . '/catalogue',
            $this->actor,
            [],
            ['source_key' => 'course', 'course_id' => $this->unreleasedCourseId],
            self::MARKER . '-unreleased-' . bin2hex(random_bytes(6)),
        ));
        $detail = (string) ($response->data['detail'] ?? '');

        $this->expect(
            $response->status === 409 && str_contains($detail, 'forbidden_scope'),
            'a course nobody released is refused by name rather than catalogued',
            'status ' . $response->status . ': ' . self::describe($response->data),
        );

        $entries = $this->get('/manage/organisations/' . $this->organisationId . '/catalogue');

        $this->expect(
            \count(\is_array($entries['items'] ?? null) ? $entries['items'] : []) === 1,
            'the refused entry wrote no row',
            self::describe($entries),
        );
    }

    /**
     * A requirement is authored, assigned in bulk, and the person who is not in
     * the organisation is an exception rather than a silent success.
     */
    private function checkARequirementIsAuthoredAndAssignedWithItsException(): void
    {
        $requirement = $this->post(
            '/manage/organisations/' . $this->organisationId . '/compliance/requirements',
            ['title' => 'R11a contract requirement', 'course_id' => $this->courseId],
            'requirement',
            201,
        );
        $this->requirementId = (int) ($requirement['id'] ?? 0);

        $this->expect(
            ($requirement['rule_key'] ?? null) === 'none'
            && ($requirement['rule_resolved'] ?? null) === true
            && ($requirement['anchor_resolved'] ?? null) === true,
            'a free site authors a requirement with no rule installed and the registry says so plainly',
            self::describe($requirement),
        );

        $assigned = $this->post(
            '/manage/organisations/' . $this->organisationId . '/compliance/requirements/'
                . $this->requirementId . '/assignments',
            ['user_ids' => [$this->memberId, $this->strangerId]],
            'assignments',
            200,
        );
        $items = \is_array($assigned['items'] ?? null) ? array_values($assigned['items']) : [];
        $exception = '';

        foreach ($items as $item) {
            if ((int) ($item['user_id'] ?? 0) === $this->strangerId) {
                $exception = (string) ($item['exception'] ?? '');
            }
        }

        $this->expect(
            (int) ($assigned['assigned'] ?? 0) === 1
            && (int) ($assigned['exceptions'] ?? 0) === 1
            && $exception === 'user_not_in_org',
            'one bulk assignment answers one assignment and one named exception',
            self::describe($assigned),
        );

        $assignments = $this->get('/manage/organisations/' . $this->organisationId . '/compliance/assignments');
        $assignedTo = array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            \is_array($assignments['items'] ?? null) ? $assignments['items'] : [],
        );

        $this->expect(
            $assignedTo === [$this->memberId],
            'the assignment list holds the member and nobody else',
            self::describe($assignments),
        );

        $exceptions = $this->get('/manage/organisations/' . $this->organisationId . '/compliance/exceptions');
        $raised = \is_array($exceptions['items'] ?? null) ? array_values($exceptions['items']) : [];

        $this->expect(
            \count($raised) === 1
            && (int) ($raised[0]['user_id'] ?? 0) === $this->strangerId
            && (string) ($raised[0]['kind'] ?? '') === 'user_not_in_org',
            'the exception is a row somebody can act on rather than a number in a response',
            self::describe($exceptions),
        );
    }

    /**
     * A requirement with no course, on the anchor a requirement gets by
     * default, is refused at save.
     *
     * The default anchor is the learner's enrolment, and a requirement with no
     * course has no enrolment to be anchored to. Storing it would raise
     * anchor_unresolved on every assignment for ever, which is a defect that
     * only shows up long after the person who authored it has left the screen.
     */
    private function checkACourselessRequirementOnTheDefaultAnchorIsRefused(): void
    {
        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            'POST',
            '/manage/organisations/' . $this->organisationId . '/compliance/requirements',
            $this->actor,
            [],
            ['title' => 'R11a contract requirement with no course'],
            self::MARKER . '-courseless-' . bin2hex(random_bytes(6)),
        ));
        $errors = \is_array($response->data['errors'] ?? null) ? $response->data['errors'] : [];

        $this->expect(
            $response->status === 422 && \array_key_exists('rule_params.anchor', $errors),
            'a courseless requirement on the enrolment anchor is refused once, by field name',
            'status ' . $response->status . ': ' . self::describe($response->data),
        );
    }

    // -----------------------------------------------------------------------
    // Plumbing
    // -----------------------------------------------------------------------

    private function createOrganisation(string $name): int
    {
        $created = $this->post(
            '/manage/organisations',
            ['name' => $name, 'slug' => self::MARKER . '-' . bin2hex(random_bytes(5))],
            'organisation',
            201,
        );

        return (int) ($created['id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body, string $what, int $expected): array
    {
        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            'POST',
            $path,
            $this->actor,
            [],
            $body,
            self::MARKER . '-' . $what . '-' . bin2hex(random_bytes(6)),
        ));

        if ($response->status !== $expected) {
            $this->failures[] = 'POST ' . $path . ' answered ' . $response->status
                . ' rather than ' . $expected . ': ' . self::describe($response->data);

            return [];
        }

        return $response->data ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $response = $this->kernel->apiKernel()->handle(new ApiRequest('GET', $path, $this->actor, ['per_page' => '100']));

        if ($response->status !== 200) {
            $this->failures[] = 'GET ' . $path . ' answered ' . $response->status
                . ': ' . self::describe($response->data);

            return [];
        }

        return $response->data ?? [];
    }

    private function revision(int $orgId): int
    {
        $row = $this->ports->database->selectOne(
            Query::from('organisations')->select(['revision'])->where('id', '=', $orgId),
        );

        return (int) ($row['revision'] ?? 1);
    }

    /**
     * The rollback, measured rather than assumed.
     *
     * Every row this contract wrote hangs off one of its two organisations, so
     * counting the organisations is enough: a site that still has either of
     * them is a site the transaction did not leave.
     */
    private function assertNothingSurvived(): void
    {
        $left = [];

        foreach (
            ['organisations' => [$this->organisationId, $this->otherOrganisationId]] as $table => $ids
        ) {
            foreach ($ids as $id) {
                if ($id <= 0) {
                    continue;
                }

                if ($this->countQuietly(Query::from($table)->where('id', '=', $id)) > 0) {
                    $left[] = $table . ' ' . $id;
                }
            }
        }

        foreach (['org_catalogue_entries' => $this->entryId, 'compliance_requirements' => $this->requirementId] as $table => $id) {
            if ($id > 0 && $this->countQuietly(Query::from($table)->where('id', '=', $id)) > 0) {
                $left[] = $table . ' ' . $id;
            }
        }

        $this->expect(
            $left === [],
            'the transaction rolled back and the site is exactly as it was found',
            'left behind ' . implode(', ', $left),
        );
    }

    private function countQuietly(Query $query): int
    {
        try {
            return $this->ports->database->count($query);
        } catch (\Throwable) {
            // A table this build does not have is not a survivor.
            return 0;
        }
    }

    private static function describe(mixed $value): string
    {
        $encoded = json_encode($value);

        return $encoded === false ? gettype($value) : $encoded;
    }

    private function expect(bool $condition, string $what, string $evidence): void
    {
        if ($condition) {
            $this->facts[] = 'ok: ' . $what;

            return;
        }

        $this->failures[] = $what . ': ' . $evidence;
    }
}
